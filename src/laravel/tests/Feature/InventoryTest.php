<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\InventoryDefinition;
use App\Models\InventorySample;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;
use Tests\TestCase;

/** The inventory of a model: a definition per thing with its current state, a sample only when it changes. */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $name = 'srv'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $name);
        $device->name = $name;
        $device->save();

        return $device;
    }

    public function test_only_a_change_adds_a_sample(): void
    {
        $device = $this->device();
        $service = fn (string $state) => ['DisplayName' => 'A high performance web server', 'State' => $state];

        $this->assertTrue($device->recordInventory('service', 'nginx', $service('running'), null, now()->subHours(3)));
        $this->assertFalse($device->recordInventory('service', 'nginx', $service('running'), null, now()->subHours(2)));
        $this->assertTrue($device->recordInventory('service', 'nginx', $service('stopped'), null, now()->subHour()));

        $this->assertSame($service('stopped'), $device->latestInventory('service', 'nginx'));
        $this->assertSame(['running', 'stopped'], $device->inventoryHistory('service', 'nginx')->get()->map(fn ($sample) => $sample->data['State'])->all());
        $this->assertSame(1, InventoryDefinition::query()->count());
    }

    public function test_a_thing_that_is_gone_has_null_data_and_can_come_back(): void
    {
        $device = $this->device();
        $device->recordInventory('container', 'redis', ['Image' => 'redis:7', 'State' => 'running'], null, now()->subHours(3));

        $device->recordInventory('container', 'redis', null, null, now()->subHours(2));
        $this->assertNull($device->latestInventory('container', 'redis'));
        $this->assertSame([null], $device->inventoryHistory('container', 'redis', now()->subMinutes(150))->get()->map(fn ($sample) => $sample->data)->all());
        // Gone twice is one change.
        $this->assertFalse($device->recordInventory('container', 'redis', null, null, now()->subHour()));

        $device->recordInventory('container', 'redis', ['Image' => 'redis:7', 'State' => 'running'], null, now());
        $this->assertSame('running', $device->latestInventory('container', 'redis')['State']);
        // The same definition all along, three samples: running, gone, running.
        $this->assertSame(1, InventoryDefinition::query()->count());
        $this->assertSame(3, InventorySample::query()->count());
    }

    public function test_every_thing_of_a_model_is_its_own_definition(): void
    {
        $device = $this->device();
        $other = $this->device('other');

        $device->recordInventory('port', 'tcp|0.0.0.0|443', ['Port' => 443, 'Process' => 'nginx']);
        $device->recordInventory('port', 'tcp|0.0.0.0|22', ['Port' => 22, 'Process' => 'sshd']);
        $other->recordInventory('port', 'tcp|0.0.0.0|443', ['Port' => 443, 'Process' => 'caddy']);

        $this->assertSame(2, $device->inventoryDefinitions()->count());
        $this->assertSame('nginx', $device->latestInventory('port', 'tcp|0.0.0.0|443')['Process']);
        $this->assertSame('caddy', $other->latestInventory('port', 'tcp|0.0.0.0|443')['Process']);
        // They are gone with the device.
        $device->delete();
        $this->assertSame(1, InventoryDefinition::query()->count());
        $this->assertSame(1, InventorySample::query()->count());
    }

    public function test_a_change_of_a_kind_as_the_agents_send_it(): void
    {
        $device = $this->device();
        $type = $device->getMorphClass();
        $item = fn (string $name, string $version) => [$name, InventoryService::hash(['Name' => $name, 'Version' => $version]), ['Name' => $name, 'Version' => $version]];

        // The whole list: three things, three samples.
        $first = InventoryService::apply($type, $device->id, 'software', [$item('a', '1'), $item('b', '1'), $item('c', '1')], [], true);
        $this->assertSame(3, $first['changed']);

        // A delta: b changed, c is gone, d is new; a is not in it and stays.
        $delta = InventoryService::apply($type, $device->id, 'software', [$item('b', '2'), $item('d', '1')], ['c'], false);
        $this->assertSame(3, $delta['changed']);
        // The things before: b (changed) and c (gone) as they were.
        $this->assertEqualsCanonicalizing([['Name' => 'b', 'Version' => '1'], ['Name' => 'c', 'Version' => '1']], $delta['old']);
        $this->assertEqualsCanonicalizing(['a', 'b', 'd'], array_keys(InventoryService::current($type, $device->id, 'software')));

        // The same list again, whole: nothing changed, nothing written; a thing that is not in a whole list is gone.
        $same = InventoryService::apply($type, $device->id, 'software', [$item('a', '1'), $item('b', '2')], [], true);
        $this->assertSame(1, $same['changed']);
        $this->assertEqualsCanonicalizing(['a', 'b'], array_keys(InventoryService::current($type, $device->id, 'software')));
        $this->assertSame(['d'], InventoryDefinition::query()->where('key', 'software')->whereNull('last_data')->where('name', 'd')->pluck('name')->all());
    }

    public function test_the_history_is_pruned_with_the_setting_and_a_gone_thing_is_forgotten(): void
    {
        $device = $this->device();
        $device->recordInventory('service', 'cron', ['State' => 'running'], null, now()->subDays(40));
        $device->recordInventory('service', 'cron', ['State' => 'stopped'], null, now()->subDays(10));
        $device->recordInventory('service', 'old', ['State' => 'running'], null, now()->subDays(50));
        $device->recordInventory('service', 'old', null, null, now()->subDays(45));

        $deleted = InventoryService::prune();

        // 30 days by default: the old samples of cron and of old, and old itself (gone since 45 days). cron keeps its state.
        $this->assertSame(3, $deleted);
        $this->assertSame(['State' => 'stopped'], $device->latestInventory('service', 'cron'));
        $this->assertNull($device->inventoryDefinition('service', 'old'));
        $this->assertSame(1, $device->inventoryHistory('service', 'cron')->count());

        // The same setting as the values: 5 days.
        Setting::query()->updateOrCreate(['index' => \App\Support\ValueRetention::SETTING, 'settable_id' => null, 'settable_type' => null], ['value' => '5', 'type' => SettingDataType::INT]);
        $this->assertSame(1, InventoryService::prune());
        $this->assertSame(['State' => 'stopped'], $device->latestInventory('service', 'cron'));
    }
}
