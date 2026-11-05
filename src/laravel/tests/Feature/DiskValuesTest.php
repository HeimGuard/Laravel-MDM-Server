<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The usage of every disk (and the battery) of a device is a measured value with a history. */
class DiskValuesTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'secret-token');
        $device->name = 'srv';
        $device->save();

        return $device;
    }

    private function report(array $drives): array
    {
        return ['machine' => ['Hostname' => 'srv', 'os' => 'x', 'Drives' => $drives]];
    }

    public function test_every_disk_of_a_windows_server_has_its_values(): void
    {
        $device = $this->device();
        $gb = 1024 ** 3;

        $this->withToken('secret-token')->postJson('/api/device', $this->report([
            ['DriveLetter' => 'C', 'FriendlyName' => 'System', 'Size' => 250 * $gb, 'SizeRemaining' => 100 * $gb, 'DriveType' => 3],
            ['DriveLetter' => 'D', 'FriendlyName' => 'Data', 'Size' => 1000 * $gb, 'SizeRemaining' => 130 * $gb, 'DriveType' => 3],
            ['DriveLetter' => 'E', 'FriendlyName' => 'Backup', 'Size' => 2000 * $gb, 'SizeRemaining' => 1320 * $gb, 'DriveType' => 3],
            ['DriveLetter' => 'F', 'FriendlyName' => 'Empty card reader', 'Size' => 0, 'SizeRemaining' => 0, 'DriveType' => 3],
        ]))->assertOk();

        $device = $device->fresh();
        // Three disks, two values each; the one with no size says nothing.
        $this->assertSame(6, $device->valueDefinitions()->count());
        $this->assertSame(60.0, $device->latestValue('disk.used_pct', 'C:'));
        $this->assertSame(87.0, $device->latestValue('disk.used_pct', 'D:'));
        $this->assertSame(34.0, $device->latestValue('disk.used_pct', 'E:'));
        $this->assertSame((float) (130 * $gb), $device->latestValue('disk.free_bytes', 'D:'));
        $this->assertNull($device->valueDefinition('disk.used_pct', 'F:'));
    }

    public function test_a_disk_has_a_history_and_a_linux_disk_is_named_by_its_mount_point(): void
    {
        $device = $this->device();
        $disk = fn (int $free) => $this->report([['DriveLetter' => '/data', 'FriendlyName' => 'sdb1', 'Size' => 1000, 'SizeRemaining' => $free, 'DriveType' => 3]]);

        $this->withToken('secret-token')->postJson('/api/device', $disk(400))->assertOk();
        $this->travel(5)->minutes();
        $this->withToken('secret-token')->postJson('/api/device', $disk(300))->assertOk();

        $device = $device->fresh();
        $this->assertSame([60.0, 70.0], $device->valueHistory('disk.used_pct', '/data')->pluck('value')->all());
        $this->assertSame(70.0, $device->latestValue('disk.used_pct', '/data'));
    }

    public function test_the_battery_comes_with_the_heartbeat(): void
    {
        $device = $this->device();

        Device::recordHeartbeat($device->id, null, 'http', ['power' => ['battery' => 83, 'plugged' => false]]);
        Device::recordHeartbeat($device->id, null, 'http', ['restart_required' => false]);

        $this->assertSame(83.0, $device->latestValue('battery.level'));
        $this->assertSame(1, $device->valueHistory('battery.level')->count());
    }
}
