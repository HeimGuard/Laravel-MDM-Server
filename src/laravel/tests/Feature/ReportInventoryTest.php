<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\InventoryDefinition;
use App\Models\InventorySample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The lists of the report (services, containers, updates, disk health) are the inventory of the device, read as they were sent. */
class ReportInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $extra = []): array
    {
        return ['machine' => ['Hostname' => 'srv', 'Platform' => 'linux', 'AgentVersion' => '1.31.0', 'Drives' => []]] + $extra;
    }

    private function device(array $report): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'srv');
        $device->name = 'srv';
        $device->data = json_encode($report);
        $device->save();

        return $device->fresh();
    }

    public function test_the_lists_are_the_inventory_and_not_in_the_column(): void
    {
        $device = $this->device($this->report([
            'services' => [['Name' => 'nginx', 'State' => 'running'], ['Name' => 'cron', 'State' => 'stopped']],
            'docker' => ['containers' => [['Name' => 'web', 'Image' => 'nginx:1.27', 'State' => 'running']]],
            'packages_updates' => [['Id' => 'Git.Git', 'Version' => '1', 'Available' => '2', 'Source' => 'winget']],
            'disk_health' => ['disks' => [['Model' => 'Samsung SSD 870', 'Health' => 'healthy']]],
            'neighbours' => [['Ip' => '192.168.1.77', 'Mac' => 'AA:BB:CC:DD:EE:77']],
        ]));

        // What stays in the column is the rest of the report: no lists, and no ARP table.
        $this->assertSame(['machine'], array_keys(json_decode($device->getRawOriginal('data'), true)));
        $kinds = InventoryDefinition::query()->where('inventorable_id', $device->id)->whereNotNull('last_data')->selectRaw('"key", count(*) as n')->groupBy('key')->pluck('n', 'key')->map(fn ($n) => (int) $n)->all();
        ksort($kinds);
        $this->assertSame(['container' => 1, 'disk_health' => 1, 'docker' => 1, 'package_update' => 1, 'service' => 2], $kinds);

        // And read as they were sent: the accessors the pages use.
        $this->assertSame(['cron', 'nginx'], array_column($device->services, 'Name'));
        $this->assertSame([['Name' => 'web', 'Image' => 'nginx:1.27', 'State' => 'running']], $device->docker['containers']);
        $this->assertSame('Git.Git', $device->apps_packages_updates[0]['Id']);
        $this->assertSame('healthy', $device->diskHealth['disks'][0]['Health']);
    }

    public function test_the_report_the_agent_posts_is_stored_as_inventory(): void
    {
        $device = new Device;
        $device->token = hash('sha256', 'secret-token');
        $device->save();

        $this->withToken('secret-token')->postJson('/api/device', $this->report([
            'services' => [['Name' => 'nginx', 'State' => 'running']],
            'neighbours' => [['Ip' => '192.168.1.77', 'Mac' => 'AA:BB:CC:DD:EE:77']],
        ]))->assertOk();

        // The lists are the inventory, not in the column; the ARP table of the agent is not kept.
        $this->assertSame(['machine'], array_keys(json_decode(Device::find($device->id)->getRawOriginal('data'), true)));
        $this->assertSame('running', Device::find($device->id)->latestInventory('service', 'nginx')['State']);
    }

    public function test_the_order_of_a_list_is_the_order_of_the_report(): void
    {
        $rows = fn (array $ids) => array_map(fn ($id) => ['Id' => $id, 'Version' => '1', 'Available' => '2', 'Source' => 'winget'], $ids);
        $device = $this->device($this->report(['packages_updates' => $rows(['c', 'a', 'b'])]));
        $this->assertSame(['c', 'a', 'b'], array_column($device->apps_packages_updates, 'Id'));

        // A new report: the same ones in another order and a new one in the middle (installUpdate finds a row by its index).
        $device->data = json_encode($this->report(['packages_updates' => $rows(['b', 'd', 'c', 'a'])]));
        $device->save();

        $this->assertSame(['b', 'd', 'c', 'a'], array_column($device->fresh()->apps_packages_updates, 'Id'));
        // Only the new one is a change: the others are the same things in another place.
        $this->assertSame(4, InventorySample::query()->count());
    }

    public function test_only_what_changed_in_the_next_report_is_a_sample(): void
    {
        $device = $this->device($this->report(['services' => [['Name' => 'nginx', 'State' => 'running'], ['Name' => 'cron', 'State' => 'running']]]));
        $this->assertSame(2, InventorySample::query()->count());

        $device->data = json_encode($this->report(['services' => [['Name' => 'nginx', 'State' => 'stopped'], ['Name' => 'cron', 'State' => 'running']]]));
        $device->save();

        $this->assertSame(3, InventorySample::query()->count());
        $this->assertSame(['running', 'stopped'], $device->inventoryHistory('service', 'nginx')->get()->map(fn ($sample) => $sample->data['State'])->all());
    }

    public function test_a_list_the_report_no_longer_has_is_gone(): void
    {
        $device = $this->device($this->report(['services' => [['Name' => 'nginx', 'State' => 'running']], 'docker' => ['containers' => [['Name' => 'web', 'State' => 'running']]]]));
        $this->assertNotNull($device->docker);

        $device->data = json_encode($this->report(['services' => [['Name' => 'cron', 'State' => 'running']]]));
        $device->save();

        $fresh = $device->fresh();
        $this->assertSame(['cron'], array_column($fresh->services, 'Name'));
        // No docker any more: null, as when a device never had it.
        $this->assertNull($fresh->docker);
        $this->assertNull($fresh->latestInventory('container', 'web'));
    }

    public function test_docker_with_an_error_and_no_containers(): void
    {
        $device = $this->device($this->report(['docker' => ['error' => 'permission denied', 'containers' => []]]));

        $docker = $device->docker;

        $this->assertSame('permission denied', $docker['error']);
        $this->assertSame([], $docker['containers']);
    }

    public function test_the_lists_of_many_devices_cost_one_query(): void
    {
        foreach (range(1, 6) as $i) {
            $device = new Device;
            $device->token = hash('sha256', "t$i");
            $device->name = "pc$i";
            $device->data = json_encode($this->report(['services' => [['Name' => 'nginx', 'State' => 'running']], 'packages_updates' => [['Id' => "p$i"]]]));
            $device->save();
        }
        $devices = Device::all();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        Device::loadSections($devices);
        $names = $devices->map(fn (Device $device) => [array_column($device->services, 'Name'), array_column($device->apps_packages_updates, 'Id')])->all();

        $this->assertSame(1, $queries);
        $this->assertSame([['nginx'], ['p1']], $names[0]);
    }
}
