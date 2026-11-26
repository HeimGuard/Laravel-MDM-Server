<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/** Node.js, Python and their global modules (agents 1.43.0+): parameter validation, the mapping of the rows and the sections. */
class RuntimeUpdateTest extends TestCase
{
    use RefreshDatabase;
    use SignsDeviceRequests;

    private function device(string $platform, string $version, array $packages): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'secret-token-'.$platform);
        $device->data = json_encode([
            'machine' => ['Hostname' => 'srv', 'AgentVersion' => $version, 'Platform' => $platform, 'Drives' => []],
            'packages_updates' => $packages,
        ]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    public function test_the_parameters_are_validated(): void
    {
        $this->assertSame(['kind' => 'npm', 'id' => 'typescript'], DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'npm', 'id' => 'typescript']));
        $this->assertSame(['kind' => 'npm', 'id' => '@angular/cli'], DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'npm', 'id' => '@angular/cli']));
        $this->assertSame(['kind' => 'pipglobal', 'id' => 'requests'], DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'pipglobal', 'id' => 'requests']));
        $this->assertSame(['kind' => 'node', 'id' => '20.19.5'], DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'node', 'id' => '20.19.5']));
        $this->assertSame(['kind' => 'python', 'id' => '3.12.10'], DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'python', 'id' => '3.12.10']));
        // A name or a release only: no spaces, shell characters, a second scope, a user, a Python 2 or a version that is a range.
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'npm', 'id' => 'a; rm -rf /']));
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'npm', 'id' => '@a/b/c']));
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'pipglobal', 'id' => 'bad name']));
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'node', 'id' => '20.x']));
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'python', 'id' => '2.7.18']));
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'npm', 'id' => 'typescript', 'user' => 'alice']));
    }

    public function test_the_rows_map_to_their_commands(): void
    {
        $device = $this->device('windows', '1.43.0', [
            ['Id' => 'typescript', 'Version' => '5.3.0', 'Avaliable' => '5.6.2', 'Source' => 'npm', 'Provider' => 'node'],
            ['Id' => 'requests', 'Version' => '2.30.0', 'Avaliable' => '2.32.3', 'Source' => 'pip-global', 'Provider' => 'python'],
            ['Id' => 'Node.js', 'Version' => '20.11.0', 'Avaliable' => '20.19.5', 'Source' => 'nodejs.org', 'Provider' => 'node'],
            ['Id' => 'Python 3.12', 'Version' => '3.12.1', 'Avaliable' => '3.12.10', 'Source' => 'python.org', 'Provider' => 'python'],
        ]);
        $rows = $device->apps_packages_updates;

        $this->assertSame(['kind' => 'npm', 'id' => 'typescript', 'title' => 'typescript'], $device->updateTarget('app', $rows[0]));
        $this->assertSame(['kind' => 'pipglobal', 'id' => 'requests', 'title' => 'requests'], $device->updateTarget('app', $rows[1]));
        $this->assertSame(['kind' => 'node', 'id' => '20.19.5', 'title' => 'Node.js 20.19.5'], $device->updateTarget('app', $rows[2]));
        $this->assertSame(['kind' => 'python', 'id' => '3.12.10', 'title' => 'Python 3.12.10'], $device->updateTarget('app', $rows[3]));
    }

    public function test_on_linux_a_runtime_is_listed_and_not_installed(): void
    {
        $device = $this->device('linux', '1.43.0', [
            ['Id' => 'Node.js', 'Version' => '20.11.0', 'Avaliable' => '20.19.5', 'Source' => 'nodejs.org', 'Provider' => 'node'],
            ['Id' => 'Python 3.12', 'Version' => '3.12.1', 'Avaliable' => '3.12.12', 'Source' => 'python.org', 'Provider' => 'python'],
            ['Id' => 'typescript', 'Version' => '5.3.0', 'Avaliable' => '5.6.2', 'Source' => 'npm', 'Provider' => 'node'],
        ]);
        $rows = $device->apps_packages_updates;

        $this->assertNull($device->updateTarget('app', $rows[0]));
        $this->assertNull($device->updateTarget('app', $rows[1]));
        // The global modules are installed on Linux too.
        $this->assertSame('npm', $device->updateTarget('app', $rows[2])['kind']);
    }

    public function test_an_older_agent_has_no_buttons(): void
    {
        $device = $this->device('windows', '1.42.1', [
            ['Id' => 'typescript', 'Version' => '5.3.0', 'Avaliable' => '5.6.2', 'Source' => 'npm'],
            ['Id' => 'Node.js', 'Version' => '20.11.0', 'Avaliable' => '20.19.5', 'Source' => 'nodejs.org'],
        ]);
        $rows = $device->apps_packages_updates;

        $this->assertNull($device->updateTarget('app', $rows[0]));
        $this->assertNull($device->updateTarget('app', $rows[1]));
    }

    public function test_the_rows_are_in_the_sections_of_node_and_python(): void
    {
        $device = $this->device('linux', '1.43.0', [
            ['Id' => 'typescript', 'Version' => '5.3.0', 'Avaliable' => '5.6.2', 'Source' => 'npm'],
            ['Id' => 'requests', 'Version' => '2.30.0', 'Avaliable' => '2.32.3', 'Source' => 'pip-global'],
            ['Id' => 'httpie', 'Version' => '3.0', 'Avaliable' => '3.2', 'Source' => 'pip (bob)'],
            ['Id' => 'Node.js', 'Version' => '20.11.0', 'Avaliable' => '20.19.5', 'Source' => 'nodejs.org'],
            ['Id' => 'Python 3.12', 'Version' => '3.12.1', 'Avaliable' => '3.12.12', 'Source' => 'python.org'],
        ]);
        $groups = $device->appUpdateGroups();
        $rows = $device->apps_packages_updates;

        $this->assertSame(['python', 'node'], array_keys($groups));
        $this->assertSame([1, 2, 4], array_keys($groups['python']));
        $this->assertSame([0, 3], array_keys($groups['node']));
        $this->assertSame('npm', $device->updateGroupKey('app', $rows[0]));
        $this->assertSame('pipglobal', $device->updateGroupKey('app', $rows[1]));
        // A "pip" row of a user is still the old kind, not the global one.
        $this->assertSame('pip', $device->updateGroupKey('app', $rows[2]));
        $this->assertSame('nodert', $device->updateGroupKey('app', $rows[3]));
        $this->assertSame('pythonrt', $device->updateGroupKey('app', $rows[4]));
    }
}
