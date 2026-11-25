<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Services\SwitchService;
use App\Support\PingUsage;
use App\Support\ValueRecorder;
use Illuminate\Database\Seeder;

/**
 * A home network for the Networks page (after DeviceSeeder, which has the agents of the LAN 192.168.1.0/24): the router Turris, the gateway of the
 * LAN, read over SNMP with its ports; three switches (SNMP, and a TP-Link one read from its web page) with the devices plugged into their ports;
 * and devices plugged into the ports of the router (pi-hole, the NanoKVM of DeviceSeeder). Run again: its devices are replaced.
 */
class NetworkMapSeeder extends Seeder
{
    private const NETWORK = '192.168.1';

    public function run(): void
    {
        Device::query()->where('token', 'like', 'seed-map-%')->get()->each->delete();

        // The router: the gateway of the LAN (the address the agents report), 10 Ethernet ports, 6 of them with a link.
        $turris = $this->ping('Turris', 1, 'D8:58:D7:00:11:22', snmp: ['name' => 'HeartOfGold', 'descr' => 'Linux turris 6.6.159 #0 SMP Thu Oct 22 19:53:31 2026 armv7l']);
        $this->ports($turris, [[2, 0], [3, 6], [4, 6], [5, 6], [6, 0], [7, 6], [8, 6], [9, 0], [12, 0], [13, 6]]);

        $sw1 = $this->ping('SW1 (Rack)', 8, '1C:3B:F3:10:00:01', snmp: ['name' => 'sw1-rack', 'descr' => 'Managed gigabit switch, 5 ports']);
        $this->ports($sw1, [[1, 6], [2, 6], [3, 6], [4, 0], [5, 4]]);
        $sw2 = $this->ping('SW2', 11, '1C:3B:F3:10:00:02', snmp: ['name' => 'sw2', 'descr' => 'Managed gigabit switch, 5 ports']);
        $this->ports($sw2, [[1, 6], [2, 6], [3, 5], [4, 0], [5, 0]]);
        // A TP-Link Easy Smart switch (its MAC address is one of TP-Link): read from its web page.
        $sw3 = $this->ping('SW3', 3, '50:C7:BF:10:00:03', tplink: true);
        $this->ports($sw3, [[1, 6], [2, 6], [3, 4], [4, 5], [5, 0]]);

        // The devices of the LAN and where they are plugged in (device => switch, port).
        $this->plug($this->ping('pi-hole', 181, 'DC:A6:32:10:00:04'), $turris, 4);
        $kvm = Device::query()->where('token', 'seed-ping-203')->first() ?? $this->ping('kvm', 203, '48:DA:35:6F:D1:8A', online: false);
        $this->plug($kvm, $turris, 13);
        $this->plug($this->ping('PVE01', 5, 'BC:24:11:10:00:05'), $sw1, 2);
        $this->plug($this->ping('PVE02', 6, 'BC:24:11:10:00:06'), $sw1, 3);
        $this->plug($this->ping('Synology NAS', 7, '00:11:32:10:00:07', online: false), $sw1, 5);
        $this->plug($this->ping('unify-filip-ap-01', 253, '74:AC:B9:10:00:08'), $sw2, 2);
        $this->plug($this->ping('unify-vasek-ap-01', 217, '74:AC:B9:10:00:09'), $sw3, 1);
        $this->plug($this->ping('ASUS-PC', 108, '2C:F0:5D:10:00:0A'), $sw3, 3);
        $this->ping('docker-host', 103, '02:42:AC:10:00:0B');
        $this->ping('lgwebostv', 174, 'A8:23:FE:10:00:0C', online: false);
    }

    /** @param  array{name: string, descr: string}|null  $snmp  read over SNMP (the community "public"), what the device says about itself */
    private function ping(string $name, int $host, string $mac, bool $online = true, ?array $snmp = null, bool $tplink = false): Device
    {
        $device = new Device;
        $device->forceFill([
            'kind' => 'ping', 'name' => $name, 'os' => '', 'token' => 'seed-map-'.$host,
            'ping_address' => self::NETWORK.'.'.$host, 'ping_prefix' => 24, 'ping_mac' => $mac,
            'last_seen_at' => $online ? now()->addYear() : now()->subHours(6),
        ] + ($snmp !== null ? ['switch_type' => SwitchService::SNMP, 'switch_password' => SwitchService::storePassword('public')] : [])
            + ($tplink ? ['switch_type' => SwitchService::TPLINK, 'switch_user' => 'admin', 'switch_password' => SwitchService::storePassword('secret')] : []));
        $device->save();
        if ($snmp !== null) {
            $device->recordInventory('snmp', 'system', $snmp + ['version' => '2c']);
        }

        // A day of checks every 5 minutes (every 30 seconds the last hour) for the chart of the response time; an offline device did not answer for 6 hours.
        $moments = [];
        for ($seconds = 24 * 3600; $seconds >= 0; $seconds -= $seconds > 3600 ? 300 : 30) {
            $up = $online || $seconds > 6 * 3600;
            $moments[] = [now()->subSeconds($seconds), PingUsage::values($up, $up ? round(mt_rand(5, 40) / 10, 1) : null)];
        }
        ValueRecorder::recordMany($device->getMorphClass(), $device->id, $moments);

        return $device->fresh();
    }

    /** The ports a switch has read: [port, link code] (SwitchService::LINKS), packets counted up from the port number. */
    private function ports(Device $switch, array $ports): void
    {
        $values = [['key' => 'switch.ports.up', 'value' => count(array_filter($ports, fn (array $port) => $port[1] >= 2))]];
        foreach ($ports as [$port, $link]) {
            $name = (string) $port;
            $values[] = ['key' => 'switch.port.link', 'name' => $name, 'value' => $link];
            $values[] = ['key' => 'switch.port.bad', 'name' => $name, 'value' => 0];
            $values[] = ['key' => 'switch.port.tx', 'name' => $name, 'value' => $link ? $port * 7_000_000 + 1234 : 0];
            $values[] = ['key' => 'switch.port.rx', 'name' => $name, 'value' => $link ? $port * 3_000_000 + 4321 : 0];
        }
        ValueRecorder::record($switch->getMorphClass(), $switch->id, $values);
    }

    private function plug(Device $device, Device $switch, int $port): Device
    {
        $device->forceFill(['switch_id' => $switch->id, 'switch_port' => $port])->save();

        return $device;
    }
}
