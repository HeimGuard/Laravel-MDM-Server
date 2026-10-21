<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/** pip --user and pipx package updates: parameter validation and the app-update mapping. */
class PythonUpdateTest extends TestCase
{
    use RefreshDatabase;
    use SignsDeviceRequests;

    private function linuxDevice(string $version, array $packages): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'secret-token');
        $device->data = json_encode([
            'machine' => ['Hostname' => 'srv', 'AgentVersion' => $version, 'Platform' => 'linux', 'Drives' => []],
            'packages_updates' => $packages,
        ]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    public function test_pip_and_pipx_parameters_are_validated(): void
    {
        $this->assertSame(['kind' => 'pip', 'id' => 'requests'], DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'pip', 'id' => 'requests']));
        $this->assertSame(['kind' => 'pipx', 'id' => 'poetry', 'user' => 'alice'], DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'pipx', 'id' => 'poetry', 'user' => 'alice']));
        // A package name only; no spaces or shell characters.
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'pip', 'id' => 'bad name']));
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'pipx', 'id' => 'a;rm -rf']));
        // An invalid user is rejected.
        $this->assertNull(DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'pip', 'id' => 'requests', 'user' => 'Bad User']));
    }

    public function test_app_updates_map_to_pip_and_pipx(): void
    {
        $device = $this->linuxDevice('1.19.0', [
            ['Id' => 'poetry', 'Version' => '1.1', 'Avaliable' => '1.2', 'Source' => 'pipx'],
            ['Id' => 'black', 'Version' => '22.1', 'Avaliable' => '24.1', 'Source' => 'pipx (alice)'],
            ['Id' => 'httpie', 'Version' => '3.0', 'Avaliable' => '3.2', 'Source' => 'pip (bob)'],
        ]);
        $rows = $device->apps_packages_updates;

        $this->assertSame(['kind' => 'pipx', 'id' => 'poetry', 'title' => 'poetry'], $device->updateTarget('app', $rows[0]));
        $this->assertSame(['kind' => 'pipx', 'id' => 'black', 'user' => 'alice', 'title' => 'black'], $device->updateTarget('app', $rows[1]));
        $this->assertSame(['kind' => 'pip', 'id' => 'httpie', 'user' => 'bob', 'title' => 'httpie'], $device->updateTarget('app', $rows[2]));
    }

    public function test_application_updates_are_split_into_sections_by_provider(): void
    {
        $device = $this->linuxDevice('1.22.0', [
            ['Id' => 'org.gimp.GIMP', 'Version' => '2.10', 'Avaliable' => '3.0', 'Source' => 'flatpak'],
            ['Id' => 'httpie', 'Version' => '3.0', 'Avaliable' => '3.2', 'Source' => 'pip (bob)'],
            ['Id' => 'core22', 'Version' => '1', 'Avaliable' => '2', 'Source' => 'snap'],
            ['Id' => 'poetry', 'Version' => '1.1', 'Avaliable' => '1.2', 'Source' => 'pipx'],
        ]);

        $groups = $device->appUpdateGroups();
        // In the order of the sections, rows under their index in the report.
        $this->assertSame(['python', 'flatpak', 'snap'], array_keys($groups));
        $this->assertSame([1, 3], array_keys($groups['python']));
        $this->assertSame('pip', $device->updateGroupKey('app', $groups['python'][1]));
        $this->assertSame('pipx', $device->updateGroupKey('app', $groups['python'][3]));
        $this->assertSame('apt', $device->updateGroupKey('os', []));
    }

    public function test_an_older_agent_cannot_install_python_updates(): void
    {
        $device = $this->linuxDevice('1.18.0', [
            ['Id' => 'poetry', 'Version' => '1.1', 'Avaliable' => '1.2', 'Source' => 'pipx'],
        ]);
        // The update list still shows it, but it offers no install button on an agent that is too old.
        $this->assertNull($device->updateTarget('app', $device->apps_packages_updates[0]));
        $this->assertSame(__('Needs agent :version or newer', ['version' => '1.19.0']), $device->commandRefusal('installUpdate', ['kind' => 'pipx', 'id' => 'poetry']));
    }
}
