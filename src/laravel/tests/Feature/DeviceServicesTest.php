<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Livewire\DeviceMetrics;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceServicesTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $extra, array $machine = []): Device
    {
        $device = new Device();
        $device->token = hash('sha256', 'secret-token');
        $device->save();

        $this->withToken('secret-token')->postJson('/api/device', [
            'machine' => [
                'Hostname' => 'srv1',
                'os' => 'Debian GNU/Linux 12',
                'RestartRequired' => false,
                'Drives' => [],
            ] + $machine,
        ] + $extra)->assertOk();

        return $device->fresh();
    }

    public function test_services_are_sorted_failed_first(): void
    {
        $device = $this->report(['services' => [
            ['Name' => 'ssh', 'DisplayName' => 'OpenBSD Secure Shell server', 'State' => 'running'],
            ['Name' => 'nginx', 'DisplayName' => 'Web server', 'State' => 'failed'],
            ['Name' => 'Spooler', 'DisplayName' => 'Print Spooler', 'State' => 'stopped', 'StartType' => 'automatic'],
        ]]);

        $this->assertSame(['nginx', 'Spooler', 'ssh'], array_column($device->services, 'Name'));
    }

    public function test_docker_and_disk_health_are_optional(): void
    {
        $device = $this->report([]);

        $this->assertSame([], $device->services);
        $this->assertNull($device->docker);
        $this->assertNull($device->diskHealth);
        $this->assertFalse($device->diskHealthProblem);
    }

    public function test_single_items_serialized_as_objects_are_lists(): void
    {
        $device = $this->report([
            'services' => ['Name' => 'ssh', 'State' => 'running'],
            'docker' => ['containers' => ['Name' => 'web', 'State' => 'running']],
            'disk_health' => ['disks' => ['Device' => '/dev/sda', 'Health' => 'failed']],
        ]);

        $this->assertSame('ssh', $device->services[0]['Name']);
        $this->assertSame('web', $device->docker['containers'][0]['Name']);
        $this->assertTrue($device->diskHealthProblem);
    }

    public function test_detail_shows_services_containers_and_disk_health(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->report([
            'services' => [
                ['Name' => 'nginx', 'DisplayName' => 'A high performance web server', 'State' => 'failed'],
                ['Name' => 'ssh', 'DisplayName' => 'OpenBSD Secure Shell server', 'State' => 'running'],
            ],
            'docker' => ['containers' => [
                ['Name' => 'mdm-web', 'Image' => 'alpine:3.22', 'State' => 'running', 'Status' => 'Up 2 hours', 'Ports' => '0.0.0.0:80->80/tcp'],
                ['Name' => 'backup', 'Image' => 'restic/restic', 'State' => 'exited', 'Status' => 'Exited (0) 3 hours ago', 'Ports' => ''],
            ]],
            'disk_health' => ['disks' => [
                ['Device' => '/dev/nvme0', 'Model' => 'WD Blue SN570 1TB', 'MediaType' => 'SSD', 'Health' => 'passed', 'Temperature' => 41, 'PowerOnHours' => 2210, 'WearPercent' => 3, 'MediaErrors' => 0, 'Standby' => false],
                ['Device' => '/dev/sdb', 'Model' => 'WD Red 4TB', 'MediaType' => 'HDD', 'Health' => 'passed', 'Standby' => true],
            ]],
        ]);

        // Only the selected tab is rendered.
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Services')
            ->assertSee('Docker')
            ->assertDontSee('A high performance web server')
            ->set('tab', 'services')
            ->assertSee('A high performance web server')
            ->set('tab', 'docker')
            ->assertSee('mdm-web')
            ->assertSee('Exited (0) 3 hours ago')
            ->set('tab', 'drives')
            ->assertSee('Disk health')
            ->assertSee('WD Blue SN570 1TB')
            ->assertSee('41 °C')
            ->assertSee('Disk is asleep, showing the last known values.');
    }

    public function test_detail_shows_powershell_module_updates(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->report(['module_updates' => [
            ['Name' => 'Az.Accounts', 'Version' => '2.12.1', 'Available' => '3.0.4', 'Edition' => 'Windows PowerShell'],
            ['Name' => 'Pester', 'Version' => '5.5.0', 'Available' => '5.6.1', 'Edition' => 'PowerShell 7'],
        ]]);

        $this->assertCount(2, $device->moduleUpdates);
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            // The alert of the overview names them, the Updates tab lists them.
            ->assertSee('PowerShell modules')
            ->set('tab', 'updates')
            ->assertSee('Updates')
            ->assertSee('Az.Accounts')
            ->assertSee('Windows PowerShell')
            ->assertSee('5.5.0 → 5.6.1');
    }

    public function test_detail_splits_module_updates_of_agents_on_windows_powershell(): void
    {
        // Agents up to 1.7.2 on Windows PowerShell 5.1 sent the modules of an edition as one row of lists.
        $this->actingAs(User::factory()->create());
        $device = $this->report(['module_updates' => [
            ['Name' => ['JiraPS', 'Microsoft.WinGet.Client'], 'Version' => ['2.14.7', '1.10.340'], 'Available' => ['2.16.0', '1.29.380'], 'Edition' => 'Windows PowerShell', 'User' => [null, null], 'Error' => [null, null]],
        ]]);

        $this->assertSame([
            ['Name' => 'JiraPS', 'Version' => '2.14.7', 'Available' => '2.16.0', 'Edition' => 'Windows PowerShell', 'User' => null, 'Error' => null],
            ['Name' => 'Microsoft.WinGet.Client', 'Version' => '1.10.340', 'Available' => '1.29.380', 'Edition' => 'Windows PowerShell', 'User' => null, 'Error' => null],
        ], $device->moduleUpdates);
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->set('tab', 'updates')
            ->assertSee('JiraPS')
            ->assertSee('1.10.340 → 1.29.380');
    }

    public function test_detail_shows_collection_errors(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->report([
            'docker' => ['error' => 'Cannot connect to the Docker daemon'],
            'disk_health' => ['error' => 'smartctl not found, install smartmontools (apt install smartmontools).'],
        ], ['AgentVersion' => '1.5.0']);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->set('tab', 'docker')
            ->assertSee('Cannot connect to the Docker daemon')
            ->assertDontSee('No containers.')
            ->set('tab', 'drives')
            ->assertSee('install smartmontools');
    }

    public function test_docker_tab_is_hidden_when_an_old_agent_found_only_the_cli(): void
    {
        $this->actingAs(User::factory()->create());
        // Agents before 1.5.0 reported Docker whenever the docker command existed.
        $device = $this->report(['docker' => ['error' => 'Cannot connect to the Docker daemon']], ['AgentVersion' => '1.4.0']);

        $this->assertNull($device->docker);
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->assertDontSee('Cannot connect to the Docker daemon');

        // Containers from an old agent are still shown.
        $this->withToken('secret-token')->postJson('/api/device', [
            'machine' => ['Hostname' => 'srv1', 'AgentVersion' => '1.4.0', 'Drives' => []],
            'docker' => ['containers' => [['Name' => 'web', 'State' => 'running']]],
        ])->assertOk();
        $this->assertCount(1, $device->fresh()->docker['containers']);
    }

    public function test_phased_and_held_updates_are_marked_and_not_counted(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->report(['os_updates' => [
            ['Title' => 'drkonqi 6.6.6-0ubuntu0.1', 'Status' => 'phased'],
            ['Title' => 'forticlient 7.4.8.1904', 'Status' => 'held'],
            ['Title' => 'curl 8.18.0-1ubuntu2.7', 'Status' => 'installable'],
        ]]);

        $this->assertSame(['curl 8.18.0-1ubuntu2.7', 'drkonqi 6.6.6-0ubuntu0.1', 'forticlient 7.4.8.1904'], array_column($device->updates, 'Title'));
        $this->assertCount(1, $device->installableUpdates);
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->set('tab', 'updates')
            ->assertSee('Phased')
            ->assertSee('Held back')
            ->assertSeeHtml('fa-hourglass-half')
            ->assertSeeHtml('fa-pause-circle')
            // One installable update: counted on the tab.
            ->assertSeeHtml('title="Installable updates"');
    }

    public function test_only_deferred_updates_raise_no_warning(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->report(['os_updates' => [['Title' => 'drkonqi 6.6.6', 'Status' => 'phased']]]);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->set('tab', 'updates')
            ->assertSee('drkonqi 6.6.6')
            ->assertDontSeeHtml('title="Installable updates"');

        // Windows and older agents send no status: installable.
        $this->withToken('secret-token')->postJson('/api/device', [
            'machine' => ['Hostname' => 'srv1', 'Drives' => []],
            'os_updates' => [['Title' => 'KB5031356']],
        ])->assertOk();
        $this->assertCount(1, $device->fresh()->installableUpdates);
    }

    public function test_application_updates_show_source_and_versions(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->report([
            'packages_updates' => [
                ['Id' => 'org.mozilla.firefox', 'Version' => '130.0', 'Avaliable' => '131.0.2', 'Source' => 'flatpak'],
                ['Id' => 'code', 'Version' => '1.93.0', 'Avaliable' => '1.94.2', 'Source' => 'snap'],
            ],
            'module_updates' => [['Name' => 'Pester', 'Version' => '5.5.0', 'Available' => '5.6.1', 'Edition' => 'PowerShell 7', 'User' => 'jonatanrek']],
        ]);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->set('tab', 'updates')
            ->assertSee('org.mozilla.firefox')
            ->assertSee('flatpak')
            ->assertSee('130.0 → 131.0.2')
            ->assertSee('snap')
            ->assertSee('jonatanrek');
    }

    public function test_tab_and_chart_range_come_from_the_url(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->report([
            'services' => [['Name' => 'ssh', 'State' => 'running']],
            'os_updates' => [['Title' => 'curl 8.18.0']],
        ]);

        Livewire::withQueryParams(['tab' => 'services'])->test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->assertSet('tab', 'services')
            ->assertSeeHtml('class="nav-link active" x-on:click="$wire.set(\'tab\', \'services\')"')
            ->assertSeeHtml('class="tab-pane fade show active" id="services-tab-pane"');

        // A tab this device does not have (no Docker, no drives) falls back to the first one, Overview.
        Livewire::withQueryParams(['tab' => 'docker'])->test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->assertSeeHtml('class="tab-pane fade show active" id="overview-tab-pane"')
            ->assertDontSeeHtml('class="tab-pane fade show active" id="services-tab-pane"');

        Livewire::withQueryParams(['range' => '24h'])->test(DeviceMetrics::class, ['selectedDeviceId' => $device->id])
            ->assertSet('range', '24h');
        Livewire::withQueryParams(['range' => 'forever'])->test(DeviceMetrics::class, ['selectedDeviceId' => $device->id])
            ->assertSet('range', '1h');
    }
}
