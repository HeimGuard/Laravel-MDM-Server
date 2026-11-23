<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

class DeviceLiveStateTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    private function report(Device $device, array $extra = []): void
    {
        $this->withToken('secret-token')->postJson('/api/device', [
            'machine' => ['Hostname' => 'srv1', 'os' => 'Debian', 'RestartRequired' => false, 'Drives' => []],
            'services' => [
                ['Name' => 'nginx', 'DisplayName' => 'Web server', 'State' => 'running'],
                ['Name' => 'ssh', 'DisplayName' => 'OpenBSD Secure Shell server', 'State' => 'running'],
            ],
            'docker' => ['containers' => [
                ['Name' => 'web', 'Image' => 'nginx', 'State' => 'running', 'Status' => 'Up 2 hours'],
                ['Name' => 'db', 'Image' => 'mysql', 'State' => 'running', 'Status' => 'Up 2 hours (healthy)'],
            ]],
        ] + $extra)->assertOk();
    }

    private function createDevice(): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'secret-token');
        $device->save();

        return $device;
    }

    public function test_live_state_overrides_the_older_report(): void
    {
        $device = $this->createDevice();
        $this->report($device);
        $this->travel(30)->seconds();

        Device::recordHeartbeat($device->id, null, 'ws', [
            'restart_required' => true,
            'services' => ['nginx' => 'failed', 'ssh' => 'running', 'cron' => 'running'],
            'containers' => ['web' => 'exited', 'db' => 'unhealthy'],
        ]);

        $device->refresh();
        $this->assertTrue($device->restartPending);

        $services = collect($device->services)->keyBy('Name');
        $this->assertSame(['nginx', 'cron', 'ssh'], $services->keys()->all());
        $this->assertSame('failed', $services['nginx']['State']);
        // Details come from the report, new services get their name only.
        $this->assertSame('Web server', $services['nginx']['DisplayName']);
        $this->assertArrayNotHasKey('DisplayName', $services['cron']);

        $containers = collect($device->docker['containers'])->keyBy('Name');
        $this->assertSame('exited', $containers['web']['State']);
        $this->assertArrayNotHasKey('Status', $containers['web']);
        $this->assertSame('mysql', $containers['db']['Image']);
        $this->assertSame('running', $containers['db']['State']);
        $this->assertSame('unhealthy', $containers['db']['Health']);
    }

    public function test_a_newer_report_wins_over_the_live_state(): void
    {
        $device = $this->createDevice();
        Device::recordHeartbeat($device->id, null, 'ws', ['restart_required' => true, 'services' => ['nginx' => 'failed']]);
        $this->travel(30)->seconds();
        $this->report($device);

        $device->refresh();
        $this->assertFalse($device->restartPending);
        $this->assertSame('running', collect($device->services)->firstWhere('Name', 'nginx')['State']);
    }

    public function test_unchanged_items_keep_their_report_details(): void
    {
        $device = $this->createDevice();
        $this->report($device);
        $this->travel(30)->seconds();

        Device::recordHeartbeat($device->id, null, 'ws', ['containers' => ['web' => 'running', 'db' => 'running']]);

        $containers = collect($device->fresh()->docker['containers'])->keyBy('Name');
        $this->assertSame('Up 2 hours', $containers['web']['Status']);
        $this->assertSame('Up 2 hours (healthy)', $containers['db']['Status']);
    }

    public function test_heartbeat_without_state_keeps_the_last_one(): void
    {
        $device = $this->createDevice();
        Device::recordHeartbeat($device->id, null, 'ws', ['restart_required' => true]);
        Device::recordHeartbeat($device->id, ['cpu' => 5, 'memory_used' => 1, 'memory_total' => 2], 'ws');

        $this->assertTrue($device->fresh()->restartPending);
    }

    public function test_live_state_is_sanitized(): void
    {
        $this->assertNull(Device::sanitizeLiveState('nope'));
        $this->assertNull(Device::sanitizeLiveState(['unknown' => 1]));
        $this->assertSame(
            ['restart_required' => false, 'services' => ['ssh' => 'running'], 'containers' => []],
            Device::sanitizeLiveState([
                'restart_required' => 'false',
                'services' => ['ssh' => 'running', 'evil' => '<script>', '' => 'running', 0 => 'running'],
                'containers' => ['x' => ['nested']],
            ]),
        );
    }

    public function test_http_heartbeat_carries_live_state(): void
    {
        $device = $this->createDevice();

        $this->withToken('secret-token')->postJson('/api/device/heartbeat', [
            'state' => ['restart_required' => true],
        ])->assertNoContent();

        $this->assertTrue($device->fresh()->restartPending);
    }

    public function test_commands_queued_from_stale_models_are_not_lost(): void
    {
        $device = $this->registerDeviceKey($this->createDevice());
        Device::recordHeartbeat($device->id);
        // Two requests holding the same device: without compare-and-swap the second save would
        // drop the first command.
        $first = Device::find($device->id);
        $second = Device::find($device->id);

        $this->assertTrue($first->queueCommand('restart'));
        $this->assertTrue($second->queueCommand('doUpdates'));
        $this->assertFalse($second->queueCommand('restart'));

        $this->assertSame(['restart', 'doUpdates'], Device::takeCommands($device->id));
        $this->assertSame([], Device::takeCommands($device->id));
    }

    public function test_report_returns_queued_commands_once(): void
    {
        $device = $this->registerDeviceKey($this->createDevice());
        Device::recordHeartbeat($device->id);
        $device->fresh()->queueCommand('restart');

        $this->signedJson('POST', '/api/device', ['machine' => ['Hostname' => 'srv1', 'Drives' => []]], 'secret-token')
            ->assertJson(['commands' => ['restart']]);
        $this->signedJson('POST', '/api/device', ['machine' => ['Hostname' => 'srv1', 'Drives' => []]], 'secret-token')
            ->assertJson(['commands' => []]);
    }

    public function test_power_status_comes_from_the_live_state(): void
    {
        $device = $this->createDevice();
        $this->withToken('secret-token')->postJson('/api/device', [
            'machine' => ['Hostname' => 'nb1', 'Battery' => 80, 'PluggedIn' => false, 'Drives' => []],
        ])->assertOk();
        $device->refresh();
        $this->assertSame(80, $device->batteryLevel);
        $this->assertFalse($device->pluggedIn);

        $this->travel(30)->seconds();
        Device::recordHeartbeat($device->id, null, 'ws', ['power' => ['battery' => 81, 'plugged' => true]]);
        $device->refresh();
        $this->assertSame(81, $device->batteryLevel);
        $this->assertTrue($device->pluggedIn);

        $this->actingAs(User::factory()->create());
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->assertSee('fa-bolt', false)
            ->assertSee('81 %');
    }

    public function test_devices_without_power_data_have_no_charging_state(): void
    {
        $device = $this->createDevice();
        $this->withToken('secret-token')->postJson('/api/device', ['machine' => ['Hostname' => 'pc1', 'Battery' => 50, 'Drives' => []]])->assertOk();

        $this->assertNull($device->fresh()->pluggedIn);
        $this->assertSame(['battery' => 100, 'plugged' => false], Device::sanitizeLiveState(['power' => ['battery' => 250, 'plugged' => 'no']])['power']);
    }
}
