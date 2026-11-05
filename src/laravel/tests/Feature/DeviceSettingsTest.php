<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use Tests\TestCase;

/** The address, prefix, MAC and agent of a ping-only device and the Wake-on-LAN target of an agent are settings (lan.*). */
class DeviceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function ping(string $address = '192.168.1.50'): Device
    {
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => 'printer', 'os' => '', 'token' => hash('sha256', $address), 'ping_address' => $address, 'ping_prefix' => 24, 'ping_mac' => 'AA:BB:CC:DD:EE:50'])->save();

        return $device;
    }

    private function agent(): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'agent');
        $device->name = 'srv';
        $device->save();

        return $device;
    }

    public function test_a_ping_device_keeps_its_address_in_the_settings(): void
    {
        $printer = $this->ping();

        $this->assertSame(['lan.address' => '192.168.1.50', 'lan.mac' => 'AA:BB:CC:DD:EE:50', 'lan.prefix' => '24'],
            Setting::query()->where('settable_type', Device::class)->where('settable_id', $printer->id)->orderBy('index')->pluck('value', 'index')->all());
        $fresh = Device::query()->findOrFail($printer->id);
        $this->assertSame(['192.168.1.50', 24, 'AA:BB:CC:DD:EE:50', null], [$fresh->ping_address, $fresh->ping_prefix, $fresh->ping_mac, $fresh->ping_via_id]);
        // There are no columns for them any more.
        $this->assertArrayNotHasKey('ping_address', $fresh->getAttributes());
    }

    public function test_only_what_changed_is_dirty_and_stored(): void
    {
        $printer = Device::query()->findOrFail($this->ping()->id);

        $printer->forceFill(['ping_address' => '192.168.1.50', 'ping_prefix' => '24', 'ping_mac' => 'AA:BB:CC:DD:EE:51']);

        // The same address is not a change, the new MAC is.
        $this->assertSame(['ping_mac'], array_keys($printer->getDirty()));
        $printer->save();
        $this->assertSame('AA:BB:CC:DD:EE:51', Device::query()->findOrFail($printer->id)->ping_mac);
        $this->assertSame(3, Setting::query()->where('settable_id', $printer->id)->count());
    }

    public function test_an_emptied_setting_is_deleted(): void
    {
        $printer = Device::query()->findOrFail($this->ping()->id);
        $printer->forceFill(['ping_via_id' => 7])->save();
        $this->assertSame(7, Device::query()->findOrFail($printer->id)->ping_via_id);

        $printer->forceFill(['ping_via_id' => null, 'ping_mac' => ''])->save();

        $fresh = Device::query()->findOrFail($printer->id);
        $this->assertNull($fresh->ping_via_id);
        $this->assertNull($fresh->ping_mac);
        $this->assertSame(['lan.address', 'lan.prefix'], Setting::query()->where('settable_id', $printer->id)->orderBy('index')->pluck('index')->all());
    }

    public function test_the_wake_target_of_an_agent_is_not_a_ping_address(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['wake_mac' => 'AA:BB:CC:DD:EE:01', 'wake_address' => '192.168.1.10', 'wake_prefix' => 24])->save();

        $fresh = Device::query()->findOrFail($agent->id);
        $this->assertSame(['AA:BB:CC:DD:EE:01', '192.168.1.10', 24], [$fresh->wake_mac, $fresh->wake_address, $fresh->wake_prefix]);
        // The same keys, but each kind of device has only its own.
        $this->assertNull($fresh->ping_address);
        $this->assertNull(Device::query()->findOrFail($this->ping()->id)->wake_address);
    }

    public function test_devices_are_found_by_a_setting(): void
    {
        $a = $this->ping('192.168.1.50');
        $b = $this->ping('192.168.1.51');
        $b->forceFill(['ping_mac' => 'AA:BB:CC:DD:EE:99'])->save();
        $this->agent()->forceFill(['wake_address' => '192.168.1.10'])->save();

        $this->assertSame([$a->id], Device::query()->where('kind', 'ping')->whereSettingIn('lan.address', ['192.168.1.50'])->pluck('id')->all());
        $this->assertSame([$b->id], Device::query()->where('kind', 'ping')->whereSettingIn('lan.mac', ['AA:BB:CC:DD:EE:99', 'FF:FF:FF:FF:FF:FF'])->pluck('id')->all());
        $this->assertCount(2, Device::query()->where('kind', 'ping')->whereHasSetting('lan.address')->get());
    }

    public function test_the_settings_go_with_the_device_and_cost_no_query_per_device(): void
    {
        foreach (range(1, 5) as $i) {
            $this->ping("192.168.1.$i");
        }
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        $addresses = Device::all()->map(fn (Device $device) => $device->ping_address)->all();

        $this->assertCount(5, $addresses);
        // The devices and all their settings: two queries, not six.
        $this->assertSame(2, $queries);
        $first = Device::query()->first();
        $first->delete();
        $this->assertSame(0, Setting::query()->where('settable_type', Device::class)->where('settable_id', $first->id)->count());
    }
}
