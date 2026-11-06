<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\ValueDefinition;
use App\Models\ValueSample;
use App\Support\ValueRecorder;
use App\Support\ValueRetention;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Measured values of a model: a definition (model, key, name) and its history. */
class ValueTest extends TestCase
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

    public function test_a_new_kind_of_value_needs_no_migration(): void
    {
        $device = $this->device();

        $device->recordValues(['cpu' => 41.5, 'memory.used' => 3221225472]);
        $device->recordValues(['cpu' => 18.4]);

        $this->assertSame(2, ValueDefinition::query()->count());
        $this->assertSame(3, ValueSample::query()->count());
        $this->assertSame(18.4, $device->latestValue('cpu'));
        $this->assertSame([41.5, 18.4], $device->valueHistory('cpu')->pluck('value')->all());
        $this->assertNull($device->latestValue('never.measured'));
        $this->assertSame([], $device->valueHistory('never.measured')->get()->all());
    }

    public function test_every_disk_of_a_device_has_its_own_definition(): void
    {
        $device = $this->device('win');
        $disk = fn (string $name, int $used, int $free) => [
            ['key' => 'disk.used_pct', 'name' => $name, 'value' => $used, 'unit' => '%'],
            ['key' => 'disk.free_bytes', 'name' => $name, 'value' => $free, 'unit' => 'bytes'],
        ];

        $device->recordValues([...$disk('C:', 61, 99857989632), ...$disk('D:', 87, 130027692032), ...$disk('E:', 34, 1320000000000)]);
        $device->recordValues($disk('D:', 88, 129000000000));

        // Six definitions (three disks, two values each), each once.
        $this->assertSame(6, $device->valueDefinitions()->count());
        $this->assertSame(88.0, $device->latestValue('disk.used_pct', 'D:'));
        $this->assertSame(61.0, $device->latestValue('disk.used_pct', 'C:'));
        $this->assertSame('%', $device->valueDefinition('disk.used_pct', 'E:')->unit);
        $this->assertSame([87.0, 88.0], $device->valueHistory('disk.used_pct', 'D:')->pluck('value')->all());
        // A value of one that there is one of has the empty name.
        $device->recordValues(['cpu' => 5]);
        $this->assertSame('', $device->valueDefinition('cpu')->name);
    }

    public function test_the_values_of_one_device_are_not_the_values_of_another(): void
    {
        $a = $this->device('a');
        $b = $this->device('b');

        $a->recordValues(['cpu' => 10]);
        $b->recordValues(['cpu' => 90]);

        $this->assertSame(10.0, $a->latestValue('cpu'));
        $this->assertSame(90.0, $b->latestValue('cpu'));
        // They are gone with the device.
        $a->delete();
        $this->assertSame(1, ValueDefinition::query()->count());
    }

    public function test_an_older_sample_does_not_replace_the_current_value(): void
    {
        $device = $this->device();

        $device->recordValues(['cpu' => 50], now());
        $device->recordValues(['cpu' => 10], now()->subHour());

        $this->assertSame(50.0, $device->latestValue('cpu'));
        $this->assertSame([10.0, 50.0], $device->valueHistory('cpu')->pluck('value')->all());
    }

    public function test_what_is_not_a_number_is_not_stored(): void
    {
        $device = $this->device();

        $stored = $device->recordValues(['cpu' => 'abc', 'memory.used' => null, 'x' => INF, 'up' => true, '' => 1]);

        $this->assertSame(1, $stored);
        $this->assertSame(1.0, $device->latestValue('up'));
        $this->assertSame(1, ValueDefinition::query()->count());
    }

    public function test_the_history_is_kept_as_long_as_the_setting_says(): void
    {
        $device = $this->device();
        $this->assertSame(30, ValueRetention::days());
        foreach ([['cpu', 40], ['cpu', 10], ['ping.rtt', 45], ['ping.rtt', 0]] as [$key, $days]) {
            $device->recordValues([$key => 1], now()->subDays($days));
        }

        // The same for every value: 30 days by default.
        $this->assertSame(2, ValueRetention::prune());
        $this->assertSame(1, $device->valueHistory('cpu')->count());
        $this->assertSame(1, $device->valueHistory('ping.rtt')->count());

        // One setting changes it for all of them.
        Setting::query()->updateOrCreate(['index' => ValueRetention::SETTING, 'settable_id' => null, 'settable_type' => null], ['value' => '5', 'type' => SettingDataType::INT]);
        $this->assertSame(5, ValueRetention::days());
        $this->assertSame(1, ValueRetention::prune());
        $this->assertSame(0, $device->valueHistory('cpu')->count());
        $this->assertSame(1, $device->valueHistory('ping.rtt')->count());
    }

    public function test_a_value_that_is_not_measured_any_more_is_forgotten(): void
    {
        $device = $this->device();
        $device->recordValues([['key' => 'disk.used_pct', 'name' => 'F:', 'value' => 5]], now()->subDays(45));
        $device->recordValues(['cpu' => 1]);

        ValueRetention::prune();

        $this->assertNull($device->valueDefinition('disk.used_pct', 'F:'));
        $this->assertNotNull($device->valueDefinition('cpu'));
    }

    public function test_samples_can_be_stored_for_other_models_too(): void
    {
        $this->assertSame(1, ValueRecorder::record('App\\Models\\Site', 3, ['rx_bytes' => 1000]));
        $this->assertSame('App\\Models\\Site', ValueDefinition::query()->sole()->valuable_type);
    }
}
