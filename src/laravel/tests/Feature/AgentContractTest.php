<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/**
 * What the agent (src/powershell/app.ps1) really sends, in the shapes PowerShell makes of it (docs/agent-api.md): a
 * list of one item is the item itself, an empty list is null. The server has to store and read all of them.
 */
class AgentContractTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    private function device(): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'secret-token');
        $device->save();

        return $device;
    }

    private function machine(array $extra = []): array
    {
        // $extra first: with + the left side wins, so a key of the test has to be on the left to replace a default.
        return $extra + ['Hostname' => 'win1', 'Platform' => 'windows', 'AgentVersion' => '1.33.1', 'os' => 'Microsoft Windows 11 Pro (10.0.22631)'];
    }

    public function test_a_report_without_a_machine_is_an_error_and_not_an_empty_answer(): void
    {
        $this->device();

        $this->withToken('secret-token')->postJson('/api/device', ['neighbours' => []])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_report');
    }

    public function test_one_drive_is_the_drive_itself_in_the_report(): void
    {
        $device = $this->device();

        // Get-Volume | ForEach-Object { ... } is not wrapped in @(): a single volume is an object, not a list.
        $this->withToken('secret-token')->postJson('/api/device', ['machine' => $this->machine(['Drives' => [
            'DriveLetter' => 'C', 'FriendlyName' => '', 'SizeRemaining' => 25, 'Size' => 100, 'DriveType' => 3,
        ]])])->assertOk();

        $drives = Device::find($device->id)->drives;
        $this->assertCount(1, $drives);
        $this->assertSame('C', $drives[0]['DriveLetter']);
        $this->assertEquals(75, $drives[0]['PercentUsed']);
    }

    public function test_no_volumes_is_null_in_the_report(): void
    {
        $device = $this->device();

        $this->withToken('secret-token')->postJson('/api/device', ['machine' => $this->machine(['Drives' => null])])->assertOk();

        $this->assertSame([], Device::find($device->id)->drives);
    }

    public function test_a_report_with_a_hostname_that_is_not_text_keeps_the_name(): void
    {
        $device = $this->device();
        $device->name = 'kept';
        $device->save();

        $this->withToken('secret-token')->postJson('/api/device', ['machine' => $this->machine(['Hostname' => ['x'], 'Drives' => []])])->assertOk();

        $this->assertSame('kept', Device::find($device->id)->name);
    }

    public function test_one_service_and_one_module_update_are_the_row_itself(): void
    {
        $device = $this->device();

        $this->withToken('secret-token')->postJson('/api/device', ['machine' => $this->machine(['Drives' => []]),
            'services' => ['Name' => 'cron', 'DisplayName' => 'Cron', 'State' => 'running', 'StartType' => null],
            'module_updates' => ['Name' => 'Az', 'Version' => '1.0.0', 'Available' => '2.0.0', 'Edition' => 'PowerShell 7', 'User' => null],
        ])->assertOk();

        $device = Device::find($device->id);
        $this->assertSame(['cron'], array_column($device->services, 'Name'));
        $modules = $device->module_updates;
        $this->assertCount(1, $modules);
        $this->assertSame(['Az', '1.0.0', '2.0.0', 'PowerShell 7'], [$modules[0]['Name'], $modules[0]['Version'], $modules[0]['Available'], $modules[0]['Edition']]);
    }

    public function test_the_errors_of_the_agent_are_stored_with_their_count(): void
    {
        $device = $this->device();
        $this->registerDeviceKey($device);
        $now = time();

        // Send-AgentErrors: message, count, first and last as unix time.
        $this->signedJson('POST', '/api/device/errors', ['errors' => [['message' => 'Report failed: boom', 'count' => 3, 'first' => $now - 60, 'last' => $now]]], 'secret-token')
            ->assertOk()->assertJson(['taken' => 1]);

        $errors = Device::find($device->id)->recent_agent_errors;
        $this->assertSame('Report failed: boom', $errors[0]['message']);
        $this->assertSame(3, $errors[0]['count']);
    }

    public function test_command_parameters_with_a_trailing_newline_are_refused(): void
    {
        $this->assertSame(['kind' => 'winget', 'id' => 'Git.Git'], DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'winget', 'id' => 'Git.Git']));
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'winget', 'id' => "Git.Git\n"]));
        $this->assertNull(DeviceCommand::sanitizeParams('wake', ['macs' => ["AA:BB:CC:DD:EE:FF\n"], 'broadcasts' => ['192.168.1.255'], 'device' => 1]));
    }
}
