<?php

namespace Tests\Feature;

use App\Livewire\ShowDevices;
use App\Models\Device;
use App\Models\User;
use App\Support\SmartAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Switching between devices must not cost queries and work for every device of the list. */
class DeviceListSpeedTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $name, bool $online, bool $restart = false): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $name);
        $device->name = $name;
        $device->data = json_encode(['machine' => ['Hostname' => $name, 'AgentVersion' => '1.31.0', 'Platform' => 'linux', 'RestartRequired' => $restart, 'Drives' => [], 'Networks' => [
            ['Name' => 'eth0', 'Type' => 'lan', 'Connected' => true, 'Status' => 'Up', 'IPAddresses' => ['10.0.0.'.random_int(2, 250)]],
        ]]]);
        $device->save();
        $device->forceFill(['last_seen_at' => $online ? now() : now()->subHour()])->saveQuietly();

        return $device->fresh();
    }

    public function test_the_report_of_a_device_is_decoded_once(): void
    {
        $device = $this->agent('pc', true);

        $this->assertSame($device->data, $device->data);
        $first = $device->data;
        $device->setRawAttributes(['data' => json_encode(['machine' => ['Hostname' => 'other']])] + $device->getAttributes());
        $this->assertSame('other', $device->data->machine->Hostname);
        $this->assertNotSame($first, $device->data);
    }

    public function test_the_alerts_of_the_list_are_those_of_the_device(): void
    {
        $devices = [$this->agent('online', true), $this->agent('offline', false), $this->agent('restart', true, true)];
        $preloaded = SmartAlerts::preload();

        foreach ($devices as $device) {
            $summary = fn (array $alerts) => array_map(fn ($alert) => [$alert['key'], $alert['severity'], $alert['title'], $alert['message']], $alerts);
            $this->assertSame($summary(SmartAlerts::for($device)), $summary(SmartAlerts::for($device, null, $preloaded)), $device->name);
        }
        $this->assertSame(['offline'], array_column(SmartAlerts::for($devices[1], null, $preloaded), 'key'));
        $this->assertSame(['restart'], array_column(SmartAlerts::for($devices[2], null, $preloaded), 'key'));
    }

    public function test_the_queries_of_the_list_do_not_grow_with_the_devices(): void
    {
        $this->actingAs(User::factory()->create());
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ShowDevices::class)->call('selectDevice', Device::query()->orderByDesc('id')->value('id'));

            return count(DB::getQueryLog());
        };
        foreach (range(1, 3) as $i) {
            $this->agent("a$i", $i !== 2);
        }
        $few = $count();
        foreach (range(4, 12) as $i) {
            $this->agent("a$i", $i % 2 === 0, $i % 3 === 0);
        }

        // Nine devices more, about the same number of queries (it was about sixteen per device).
        $this->assertLessThan($few + 10, $count());
    }

    public function test_selecting_a_device_does_not_work_out_the_list_again(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (range(1, 6) as $i) {
            $this->agent("b$i", true);
        }
        $component = Livewire::test(ShowDevices::class);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $component->call('selectDevice', Device::query()->orderBy('id')->value('id'));

        // The expiry of stale commands (SmartAlerts::preload) is one query per command type; the detail asks for its own.
        $expiries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], '"device_commands"') && str_contains($query['query'], '"command" = '))->count();
        $this->assertLessThanOrEqual(count(\App\Models\DeviceCommand::TIMEOUTS) * 2, $expiries);
        $component->assertSee('b1')->assertSee('b6');
    }
}
