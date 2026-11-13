<?php

namespace Tests\Feature;

use App\Livewire\Networks\Page;
use App\Livewire\Networks\Show;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\PortScanResult;
use App\Models\User;
use App\Support\NetworkMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/** Port scanning: an agent scans the open ports of an address in its network on request. */
class PortScanTest extends TestCase
{
    use RefreshDatabase;
    use SignsDeviceRequests;

    private function machine(string $portScan = 'on', string $version = '1.18.0', string $discovery = 'neighbours'): array
    {
        return ['Hostname' => 'nas', 'AgentVersion' => $version, 'Platform' => 'linux', 'Drives' => [], 'NetworkDiscovery' => $discovery, 'PortScan' => $portScan, 'Networks' => [
            ['Name' => 'eth0', 'Type' => 'lan', 'Connected' => true, 'Status' => 'Up', 'Mac' => 'AA-00-00-00-00-01', 'IPAddresses' => ['192.168.1.5'],
                'Addresses' => [['Address' => '192.168.1.5', 'PrefixLength' => 24]], 'Gateway' => '192.168.1.1', 'GatewayMac' => '50-C7-BF-AA-BB-CC'],
        ]];
    }

    private function agent(string $portScan = 'on', string $token = 'secret-token', string $version = '1.18.0'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->name = 'nas';
        $device->public_ip = '31.30.4.122';
        $device->data = json_encode(['machine' => $this->machine($portScan, $version)]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    /** Reports a neighbour so it is on the Networks page (seen by this agent). */
    private function seeNeighbour(string $ip = '192.168.1.50', string $mac = 'b8-27-eb-12-34-56'): void
    {
        $this->signedJson('POST', '/api/device', ['machine' => $this->machine(), 'neighbours' => [['Ip' => $ip, 'Mac' => $mac]]],
            'secret-token', ['server' => ['REMOTE_ADDR' => '31.30.4.122']])->assertOk();
    }

    private function sendResult(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->signedJson('POST', '/api/device/port-scan', $payload, 'secret-token', ['server' => ['REMOTE_ADDR' => '31.30.4.122']]);
    }

    public function test_a_result_is_recorded_for_an_address_of_the_agents_network(): void
    {
        $this->agent();
        $this->sendResult([
            'ip' => '192.168.1.50',
            'ports' => [
                ['port' => 443, 'service' => 'https', 'banner' => 'TLS: nas.lan; Server: nginx'],
                ['port' => 22, 'service' => 'ssh', 'banner' => 'SSH-2.0-OpenSSH_9.2'],
                // Out of range and malformed entries are dropped.
                ['port' => 70000, 'service' => 'x'],
                ['service' => 'no port'],
            ],
            'findings' => ['Port 23: Telnet is unencrypted'],
        ])->assertOk()->assertJson(['taken' => true]);

        $result = PortScanResult::query()->firstOrFail();
        $this->assertSame(['gw:50:C7:BF:AA:BB:CC', '192.168.1.0/24', '192.168.1.50'], [$result->site, $result->network, $result->ip]);
        // Sorted by port, only the valid ones.
        $this->assertSame([22, 443], array_column($result->ports, 'port'));
        $this->assertSame(['Port 23: Telnet is unencrypted'], $result->findings);
    }

    public function test_nothing_is_recorded_when_port_scanning_is_off_on_the_device_or_in_the_portal(): void
    {
        // Off on the device.
        $agent = $this->agent('off');
        $this->sendResult(['ip' => '192.168.1.50', 'ports' => [['port' => 80]]])->assertOk()->assertJson(['taken' => false]);
        $this->assertSame(0, PortScanResult::count());

        // On the device but off in the portal.
        $agent->forceFill(['data' => json_encode(['machine' => $this->machine('on')])])->save();
        PortScanResult::setEnabled(false);
        $this->sendResult(['ip' => '192.168.1.50', 'ports' => [['port' => 80]]])->assertOk()->assertJson(['taken' => false]);
        $this->assertSame(0, PortScanResult::count());

        PortScanResult::setEnabled(true);
        $this->sendResult(['ip' => '192.168.1.50', 'ports' => [['port' => 80]]])->assertOk()->assertJson(['taken' => true]);
        $this->assertSame(1, PortScanResult::count());
    }

    public function test_an_address_outside_the_agents_networks_is_not_recorded(): void
    {
        $this->agent();
        $this->sendResult(['ip' => '10.9.9.9', 'ports' => [['port' => 80]]])->assertOk()->assertJson(['taken' => false]);
        $this->assertSame(0, PortScanResult::count());
    }

    public function test_the_scan_goes_to_the_agent_that_saw_the_device(): void
    {
        config(['mdm.public_address' => '31.30.4.122']);
        $agent = $this->agent();
        $this->seeNeighbour();
        // actingAs after the signed device request: it forgets the guards, which would otherwise
        // leave the device (not a user) as auth()->user() when the page renders.
        $this->actingAs(User::factory()->create());
        $neighbour = \App\Models\NetworkNeighbour::query()->where('ip', '192.168.1.50')->firstOrFail();

        Livewire::withQueryParams(['view' => 'list'])->test(Page::class)->call('scanPorts', $neighbour->id)->assertHasNoErrors();
        $command = $agent->commands()->where('command', 'scanPorts')->firstOrFail();
        $this->assertSame(['scanPorts', ['ip' => '192.168.1.50'], 'ports:192.168.1.50'], [$command->command, $command->params, $command->target]);

        // A second one is a duplicate while the first is still active.
        Livewire::test(Page::class)->call('scanPorts', $neighbour->id)->assertHasErrors();
        $this->assertSame(1, $agent->commands()->where('command', 'scanPorts')->count());
    }

    public function test_a_full_scan_carries_the_all_flag(): void
    {
        config(['mdm.public_address' => '31.30.4.122']);
        $agent = $this->agent();
        $this->seeNeighbour();
        $this->actingAs(User::factory()->create());
        $neighbour = \App\Models\NetworkNeighbour::query()->where('ip', '192.168.1.50')->firstOrFail();

        Livewire::withQueryParams(['view' => 'list'])->test(Page::class)->call('scanPorts', $neighbour->id, true)->assertHasNoErrors();
        $command = $agent->commands()->where('command', 'scanPorts')->firstOrFail();
        $this->assertSame(['ip' => '192.168.1.50', 'all' => true], $command->params);
        $this->assertStringContainsString('all ports', $command->label);
    }

    public function test_an_old_agent_or_a_device_with_port_scan_off_refuses(): void
    {
        $off = $this->agent('off', 'off-token');
        $this->assertStringContainsString('port_scan', $off->commandRefusal('scanPorts', ['ip' => '192.168.1.50']));

        $old = $this->agent('on', 'old-token', '1.17.1');
        $this->assertSame(__('Needs agent :version or newer', ['version' => '1.18.0']), $old->commandRefusal('scanPorts', ['ip' => '192.168.1.50']));

        $on = $this->agent('on', 'on-token');
        PortScanResult::setEnabled(false);
        $this->assertSame(__('Port scanning is turned off in the portal'), $on->commandRefusal('scanPorts', ['ip' => '192.168.1.50']));
    }

    public function test_the_command_parameters_are_validated(): void
    {
        $this->assertNull(DeviceCommand::sanitizeParams('scanPorts', ['ip' => 'nonsense']));
        $this->assertNull(DeviceCommand::sanitizeParams('scanPorts', []));
        $this->assertSame(['ip' => '192.168.1.50'], DeviceCommand::sanitizeParams('scanPorts', ['ip' => '192.168.1.50']));
        // "all" (the full 1-65535 sweep) is kept and wins over any port list.
        $this->assertSame(['ip' => '192.168.1.50', 'all' => true], DeviceCommand::sanitizeParams('scanPorts', ['ip' => '192.168.1.50', 'all' => true, 'ports' => [80]]));
        // A custom list is deduplicated, sorted and range-checked; an empty effective list is invalid.
        $this->assertSame(['ip' => '192.168.1.50', 'ports' => [22, 80, 443]], DeviceCommand::sanitizeParams('scanPorts', ['ip' => '192.168.1.50', 'ports' => [443, 80, 22, 80, 70000, 0]]));
        $this->assertNull(DeviceCommand::sanitizeParams('scanPorts', ['ip' => '192.168.1.50', 'ports' => [0, 70000]]));
        $this->assertNull(DeviceCommand::sanitizeParams('scanPorts', ['ip' => '192.168.1.50', 'ports' => range(1, 1025)]));
    }

    public function test_system_admins_turn_port_scanning_off_for_the_portal(): void
    {
        User::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->agent();
        Livewire::test(Page::class)->call('togglePortScan')->assertForbidden();

        config(['boilerplate.system_admins' => [(string) $user->id]]);
        $this->actingAs($user->fresh());
        Livewire::test(Page::class)->call('togglePortScan');
        $this->assertFalse(PortScanResult::enabled());
    }

    public function test_a_hostile_banner_is_stored_and_shown_as_plain_text(): void
    {
        config(['mdm.public_address' => '31.30.4.122']);
        $this->agent();
        $this->seeNeighbour();
        // A banner the scanned host could return, trying SQL and script injection, with a control char.
        $this->sendResult([
            'ip' => '192.168.1.50',
            'ports' => [['port' => 80, 'service' => 'http', 'banner' => "x'; DROP TABLE devices;--\x07<script>alert(1)</script>"]],
        ])->assertOk()->assertJson(['taken' => true]);
        // actingAs after the signed device requests (they forget the guards).
        $this->actingAs(User::factory()->create());

        // Stored verbatim as text (the control character stripped); the devices table is intact.
        $result = PortScanResult::query()->firstOrFail();
        $this->assertStringContainsString("'; DROP TABLE devices;--", $result->ports[0]['banner']);
        $this->assertStringNotContainsString("\x07", $result->ports[0]['banner']);
        $this->assertTrue(Device::query()->exists());

        // Rendered escaped: the raw <script> tag never reaches the page.
        $html = Livewire::test(Show::class, ['network' => NetworkMap::build()['networks'][0]['anchor']])->html();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_open_ports_card_is_one_partial_for_ping_only_and_agent_devices(): void
    {
        $this->withoutVite();
        $html = view('partials.device.open-ports', ['portScan' => [
            'address' => '192.168.1.10',
            'scanner' => null,
            'command' => null,
            'result' => (object) [
                'ports' => [['port' => 22, 'service' => 'ssh', 'banner' => 'SSH-2.0-<b>x</b>']],
                'findings' => ['Port 22: test finding'],
                'scanned_at' => now()->subMonth(),
            ],
        ]])->render();

        $this->assertStringContainsString('Open ports', $html);
        $this->assertStringContainsString('SSH-2.0-&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('Port 22: test finding', $html);

        // Both overviews use it; the device card no longer has a block of its own for the ports.
        $this->assertStringContainsString('partials.device.open-ports', file_get_contents(resource_path('views/livewire/ping-monitor.blade.php')));
        $this->assertStringContainsString('partials.device.open-ports', file_get_contents(resource_path('views/livewire/device-detail.blade.php')));
        $this->assertStringNotContainsString(':count open port', file_get_contents(resource_path('views/livewire/device-commands.blade.php')));
    }
}
