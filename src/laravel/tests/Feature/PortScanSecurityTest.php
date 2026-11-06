<?php

namespace Tests\Feature;

use App\Livewire\DeviceCommands;
use App\Livewire\DeviceDetail;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\User;
use App\Support\AlertEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/**
 * The open ports an agent reports become security findings through the `portscan` detection rules:
 * findings of an unknown neighbour keep the host (no device), findings of a managed device link to
 * it, and both resolve when a later scan no longer has them. Device-less findings feed the
 * `exposure` alert.
 */
class PortScanSecurityTest extends TestCase
{
    use RefreshDatabase;
    use SignsDeviceRequests;

    private function machine(string $portScan = 'on', string $version = '1.20.0'): array
    {
        return ['Hostname' => 'nas', 'AgentVersion' => $version, 'Platform' => 'linux', 'Drives' => [], 'NetworkDiscovery' => 'neighbours', 'PortScan' => $portScan, 'Networks' => [
            ['Name' => 'eth0', 'Type' => 'lan', 'Connected' => true, 'Status' => 'Up', 'Mac' => 'AA-00-00-00-00-01', 'IPAddresses' => ['192.168.1.5'],
                'Addresses' => [['Address' => '192.168.1.5', 'PrefixLength' => 24]], 'Gateway' => '192.168.1.1', 'GatewayMac' => '50-C7-BF-AA-BB-CC'],
        ]];
    }

    private function agent(): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'secret-token');
        $device->name = 'nas';
        $device->public_ip = '31.30.4.122';
        $device->data = json_encode(['machine' => $this->machine()]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    private function sendResult(array $ports, string $ip = '192.168.1.50'): \Illuminate\Testing\TestResponse
    {
        return $this->signedJson('POST', '/api/device/port-scan', ['ip' => $ip, 'ports' => $ports],
            'secret-token', ['server' => ['REMOTE_ADDR' => '31.30.4.122']]);
    }

    public function test_open_ports_open_device_less_findings_and_resolve_when_gone(): void
    {
        $this->agent();

        $this->sendResult([
            ['port' => 23, 'service' => 'telnet', 'banner' => 'telnet'],
            ['port' => 443, 'service' => 'https', 'tls' => true, 'certSelfSigned' => true],
            ['port' => 22, 'service' => 'ssh', 'banner' => 'SSH-2.0-OpenSSH_9.2'],
        ])->assertOk()->assertJson(['taken' => true]);

        $findings = SecurityFinding::query()->with('rule')->active()->get();
        $keys = $findings->map(fn ($f) => $f->rule->key)->sort()->values()->all();
        $this->assertContains('portscan.telnet', $keys);
        $this->assertContains('portscan.self-signed-cert', $keys);
        // A current OpenSSH 9 banner is not a finding.
        $this->assertNotContains('portscan.outdated-ssh', $keys);

        $telnet = $findings->firstWhere('rule.key', 'portscan.telnet');
        $this->assertNull($telnet->device_id);
        $this->assertSame('192.168.1.50', $telnet->target_ip);
        $this->assertSame('192.168.1.0/24', $telnet->network);
        $this->assertSame('gw:50:C7:BF:AA:BB:CC', $telnet->site);
        $this->assertStringContainsString('23', $telnet->message);

        // A later scan without those ports resolves their findings.
        $this->sendResult([['port' => 22, 'service' => 'ssh', 'banner' => 'SSH-2.0-OpenSSH_9.2']])->assertOk();
        $this->assertSame(0, SecurityFinding::query()->active()->count());
        $this->assertSame(2, SecurityFinding::query()->whereNotNull('resolved_at')->count());
    }

    public function test_a_finding_links_to_a_managed_device_when_the_address_is_one(): void
    {
        $this->agent();
        // A ping-only device at the scanned address: the finding links to it instead of the host.
        $printer = new Device;
        $printer->forceFill(['kind' => 'ping', 'name' => 'Printer', 'os' => '', 'token' => hash('sha256', 'p'),
            'ping_address' => '192.168.1.50', 'ping_prefix' => 24])->save();

        $this->sendResult([['port' => 80, 'service' => 'http', 'http' => true, 'tls' => false]])->assertOk();

        $finding = SecurityFinding::query()->with('rule')->active()->firstOrFail();
        $this->assertSame('portscan.cleartext-http', $finding->rule->key);
        $this->assertSame($printer->id, $finding->device_id);
        $this->assertSame('192.168.1.50', $finding->target_ip);
    }

    public function test_a_ping_only_device_has_the_security_and_history_tabs(): void
    {
        $this->agent();
        $printer = new Device;
        $printer->forceFill(['kind' => 'ping', 'name' => 'Printer', 'os' => '', 'token' => hash('sha256', 'p'),
            'ping_address' => '192.168.1.50', 'ping_prefix' => 24])->save();
        Device::recordHeartbeat($printer->id);
        $this->actingAs(User::factory()->create());

        // Nothing to show yet: Overview and the (empty) History only, never the agent's tab.
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $printer->id])
            ->assertSee('id="overview-tab"', false)
            ->assertSee('id="history-tab"', false)
            ->set('tab', 'history')
            ->assertSee(__('Nothing done with the device yet.'))
            ->assertDontSee('id="security-tab"', false)
            ->assertDontSee('id="agent-tab"', false);

        // A scan of its ports (run by the agent) is its history, the findings its security.
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $printer->id])->call('scanPorts');
        $this->sendResult([['port' => 80, 'service' => 'http', 'http' => true, 'tls' => false]])->assertOk();

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $printer->id])
            ->assertSee('id="security-tab"', false)
            ->assertSee('id="history-tab"', false)
            ->assertDontSee('id="agent-tab"', false)
            ->set('tab', 'security')
            ->assertDontSee('No security inventory yet');
    }

    public function test_the_exposure_alert_announces_device_less_findings(): void
    {
        $agent = $this->agent();
        $user = User::factory()->create();
        $rule = AlertRule::query()->create(['user_id' => $user->id, 'type' => 'exposure', 'target' => [], 'channels' => [], 'enabled' => true]);

        $this->sendResult([['port' => 443, 'service' => 'https', 'tls' => true, 'certSelfSigned' => true]])->assertOk();

        AlertEvaluator::run();

        $event = $rule->events()->sole();
        $finding = SecurityFinding::query()->active()->firstOrFail();
        $this->assertSame((float) $finding->id, $event->value);
        $this->assertSame($agent->id, $event->device_id);
        $this->assertStringContainsString('192.168.1.50', $event->message);
        $this->assertStringContainsString('seen by', $event->message);

        // It is announced once, not again on the next run.
        AlertEvaluator::run();
        $this->assertSame(1, $rule->events()->count());
    }
}
