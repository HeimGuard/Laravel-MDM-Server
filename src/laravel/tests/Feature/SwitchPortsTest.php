<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\SwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/** The ports of a switch without SNMP: the agent of its network is the proxy, the server makes the requests and reads the answers. */
class SwitchPortsTest extends TestCase
{
    use RefreshDatabase;
    use SignsDeviceRequests;

    private const PAGE = "var max_port_num = 5;\nvar tmp_info = {\nstate:[1,1,1,1,0],\nlink_status:[6,0,5,0,0],\npkts:[100,1,200,2, 0,0,0,0, 30,0,40,3, 0,0,0,0, 0,0,0,0]\n};";

    private function relay(string $version = '1.37.0', string $token = 'relay'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->name = 'nas';
        $device->data = json_encode(['machine' => ['Hostname' => 'nas', 'AgentVersion' => $version, 'Platform' => 'linux', 'ScriptsEnabled' => true, 'Drives' => [], 'Networks' => [
            ['Name' => 'eth0', 'Type' => 'lan', 'Connected' => true, 'Status' => 'Up', 'Mac' => 'AA-BB-CC-DD-EE-01', 'IPAddresses' => ['192.168.1.5'], 'Addresses' => [['Address' => '192.168.1.5', 'PrefixLength' => 24]]],
        ]]]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    private function switchDevice(bool $configured = true, string $token = 'switch'): Device
    {
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => 'switch', 'os' => '', 'token' => hash('sha256', $token), 'ping_address' => '192.168.1.8', 'ping_prefix' => 24]);
        if ($configured) {
            $device->forceFill(['switch_type' => 'tplink', 'switch_user' => 'admin', 'switch_password' => SwitchService::storePassword('s3cret')]);
        }
        $device->save();

        return $device->fresh();
    }

    public function test_the_page_of_the_switch_is_read_into_ports(): void
    {
        $ports = SwitchService::parse(self::PAGE);

        $this->assertCount(5, $ports);
        $this->assertSame([1, true, 6, 100, 1, 200, 2], [$ports[0]['port'], $ports[0]['enabled'], $ports[0]['link'], $ports[0]['tx'], $ports[0]['tx_bad'], $ports[0]['rx'], $ports[0]['rx_bad']]);
        $this->assertSame([5, false], [$ports[4]['port'], $ports[4]['enabled']]);
        $this->assertSame([3, 5, 30, 40, 3], [$ports[2]['port'], $ports[2]['link'], $ports[2]['tx'], $ports[2]['rx'], $ports[2]['rx_bad']]);
        // The login page, or another model: not ports.
        $this->assertNull(SwitchService::parse('<html>logonInfo</html>'));
        $this->assertNull(SwitchService::parse("var max_port_num = 5;\nlink_status:[1,2],\npkts:[1,2]"));
    }

    public function test_only_an_agent_that_can_gets_the_request_with_the_password_for_its_own_key_only(): void
    {
        $switch = $this->switchDevice();
        $relay = $this->relay();

        $probe = SwitchService::probe($switch, $relay);

        $this->assertSame(['POST', 'GET', 'GET'], array_column($probe['steps'], 'method'));
        $this->assertSame(['/logon.cgi', '/PortStatisticsRpm.htm', '/PortStatisticsRpm.htm'], array_column($probe['steps'], 'path'));
        $this->assertStringNotContainsString('s3cret', json_encode($probe));
        // Only the agent with the key reads it.
        $this->assertTrue(openssl_private_decrypt(base64_decode($probe['steps'][0]['secret']), $plain, $this->deviceKey(), OPENSSL_PKCS1_OAEP_PADDING));
        $this->assertSame(['username' => 'admin', 'password' => 's3cret'], json_decode($plain, true));
        // An older agent, or a device that is no switch, gets none.
        $this->assertNull(SwitchService::probe($switch, $this->relay('1.36.2', 'old')));
        $this->assertNull(SwitchService::probe($this->switchDevice(false, 'plain'), $relay));
        // The pings of the agent carry it.
        $this->assertArrayHasKey('probe', Device::pingTargetsFor($relay)[0]);
    }

    public function test_the_ports_the_agent_read_are_stored_and_the_login_is_not_repeated(): void
    {
        $switch = $this->switchDevice();
        $relay = $this->relay();
        Cache::flush();

        $taken = SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => self::PAGE, 'error' => null]]);

        $this->assertSame(1, $taken);
        $ports = SwitchService::ports($switch);
        $this->assertCount(5, $ports);
        $this->assertSame([6, 3, 200], [$ports[0]['link'], $ports[0]['bad'], $ports[0]['rx']]);
        $this->assertSame(2.0, $switch->latestValue('switch.ports.up'));
        // Logged in: just the page.
        $this->assertSame(['GET'], array_column(SwitchService::probe($switch, $relay)['steps'], 'method'));
        // An answer that is not the page (the switch forgot the login): the next request logs in again.
        $this->assertSame(0, SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => '<html>logon</html>', 'error' => null]]));
        $this->assertSame(['POST', 'GET', 'GET'], array_column(SwitchService::probe($switch, $relay)['steps'], 'method'));
        // Not a device the agent was given: dropped.
        $this->assertSame(0, SwitchService::record($relay, [['id' => 999999, 'status' => 200, 'body' => self::PAGE]]));
    }

    public function test_the_tplink_item_of_the_menu_is_only_for_a_device_with_a_tplink_mac_address(): void
    {
        $this->actingAs(User::factory()->create());
        $other = $this->pingDevice('printer', '00:11:22:33:44:55');
        $archer = $this->pingDevice('archer', '50:C7:BF:AA:BB:CC');

        $this->assertTrue(SwitchService::isTpLink($archer));
        $this->assertFalse(SwitchService::isTpLink($other));
        // The window opens for the one, 404 for the other (whatever is sent).
        Livewire::test(\App\Livewire\TplinkSettings::class, ['deviceId' => $archer->id])->assertOk();
        Livewire::test(\App\Livewire\TplinkSettings::class, ['deviceId' => $other->id])->assertNotFound();
        $this->assertNull($other->fresh()->switch_type);
        // A device that is read this way already keeps its window when its MAC address changes.
        $other->forceFill(['switch_type' => 'tplink', 'switch_user' => 'admin', 'switch_password' => SwitchService::storePassword('s3cret')])->save();
        Livewire::test(\App\Livewire\TplinkSettings::class, ['deviceId' => $other->id])->assertOk()->assertSet('user', 'admin')->assertSet('hasPassword', true);
    }

    public function test_a_tplink_switch_is_saved_with_the_password_encrypted_and_turned_off_with_an_empty_user(): void
    {
        $this->actingAs(User::factory()->create());
        $archer = $this->pingDevice('archer', '50:C7:BF:AA:BB:CC');

        Livewire::test(\App\Livewire\TplinkSettings::class, ['deviceId' => $archer->id])
            ->set('user', 'admin')
            ->call('save')
            ->assertHasErrors('user')
            ->set('password', 's3cret')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $archer->fresh();
        $this->assertSame(['tplink', 'admin'], [$fresh->switch_type, $fresh->switch_user]);
        $this->assertNotSame('s3cret', $fresh->switch_password);
        $this->assertTrue(SwitchService::enabled($fresh));
        // A blank password keeps the stored one; a new user name is saved.
        $stored = $fresh->switch_password;
        Livewire::test(\App\Livewire\TplinkSettings::class, ['deviceId' => $archer->id])->set('user', 'root')->call('save')->assertHasNoErrors();
        $this->assertSame(['root', $stored], [$archer->fresh()->switch_user, $archer->fresh()->switch_password]);
        Livewire::test(\App\Livewire\TplinkSettings::class, ['deviceId' => $archer->id])->set('password', 'n3w')->call('save');
        $this->assertNotSame($stored, $archer->fresh()->switch_password);
        // The Ping settings leave it alone.
        Livewire::test(\App\Livewire\PingSettings::class, ['deviceId' => $archer->id])->call('save')->assertHasNoErrors();
        $this->assertTrue(SwitchService::enabled($archer->fresh()));
        // No user name: not read any more, the device stays.
        Livewire::test(\App\Livewire\TplinkSettings::class, ['deviceId' => $archer->id])->set('user', '')->call('save')->assertHasNoErrors();
        $this->assertFalse(SwitchService::enabled($archer->fresh()));
        $this->assertNotNull(Device::find($archer->id));
    }

    private function snmpSwitch(string $community = 'public'): Device
    {
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => 'snmp-switch', 'os' => '', 'token' => hash('sha256', 'snmp'), 'ping_address' => '192.168.1.9', 'ping_prefix' => 24,
            'switch_type' => 'snmp', 'switch_password' => SwitchService::storePassword($community)]);
        $device->save();

        return $device->fresh();
    }

    private function snmpAnswer(): string
    {
        $lines = [];
        // ifIndex 1: 1000 Mb/s up, 2: down, 3: 100 Mb/s up, 4: a VLAN (type 53), 5: 10 Gb/s up with ifHighSpeed missing speed.
        foreach ([1 => [6, 1, 1000], 2 => [6, 2, 0], 3 => [6, 1, 100], 4 => [53, 1, 0]] as $index => [$type, $status, $mbit]) {
            $lines[] = SwitchService::SNMP_COLUMNS['type'].".$index $type";
            $lines[] = SwitchService::SNMP_COLUMNS['status'].".$index $status";
            $lines[] = SwitchService::SNMP_COLUMNS['fast'].".$index $mbit";
            $lines[] = SwitchService::SNMP_COLUMNS['rx'].".$index ".($index * 10);
            $lines[] = SwitchService::SNMP_COLUMNS['tx'].".$index ".($index * 20);
            $lines[] = SwitchService::SNMP_COLUMNS['rx_bad'].".$index $index";
            $lines[] = SwitchService::SNMP_COLUMNS['tx_bad'].".$index 1";
        }
        $lines[] = SwitchService::SNMP_COLUMNS['type'].'.5 6';
        $lines[] = SwitchService::SNMP_COLUMNS['status'].'.5 1';
        $lines[] = SwitchService::SNMP_COLUMNS['speed'].'.5 100000000';

        return implode("\n", $lines);
    }

    public function test_the_snmp_table_is_read_into_ports(): void
    {
        $ports = SwitchService::parseSnmp($this->snmpAnswer());

        // The VLAN is no port; the port is the ifIndex.
        $this->assertSame([1, 2, 3, 5], array_column($ports, 'port'));
        $this->assertSame([6, 0, 5, 5], array_column($ports, 'link'));
        $this->assertSame([10, 20, 1, 1], [$ports[0]['rx'], $ports[0]['tx'], $ports[0]['rx_bad'], $ports[0]['tx_bad']]);
        $this->assertNull(SwitchService::parseSnmp(''));
        $this->assertNull(SwitchService::parseSnmp("1.3.6.1.2.1.2.2.1.3.1 53\nnot a line"));
    }

    public function test_an_snmp_switch_gets_the_columns_and_the_community_for_its_own_key_only(): void
    {
        $switch = $this->snmpSwitch();
        $relay = $this->relay('1.39.0');

        $this->assertTrue(SwitchService::enabled($switch));
        $probe = SwitchService::probe($switch, $relay);

        $this->assertSame(161, $probe['port']);
        $this->assertArrayNotHasKey('steps', $probe);
        $this->assertSame(array_values(SwitchService::SNMP_COLUMNS), $probe['snmp']['columns']);
        $this->assertStringNotContainsString('public', json_encode($probe));
        $this->assertTrue(openssl_private_decrypt(base64_decode($probe['snmp']['secret']), $plain, $this->deviceKey(), OPENSSL_PKCS1_OAEP_PADDING));
        $this->assertSame(['community' => 'public'], json_decode($plain, true));
        // An agent that has HTTP probes only (1.38.0 and older) cannot.
        $this->assertNull(SwitchService::probe($switch, $this->relay('1.38.0', 'http-only')));
        // No version known yet: the agent tries v2c, then v1.
        $this->assertArrayNotHasKey('version', $probe['snmp']);
    }

    public function test_the_snmp_ports_the_agent_read_are_stored(): void
    {
        $switch = $this->snmpSwitch();
        $relay = $this->relay('1.39.0');

        $taken = SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => $this->snmpAnswer(), 'error' => null]]);

        $this->assertSame(1, $taken);
        $ports = SwitchService::ports($switch);
        $this->assertSame([1, 2, 3, 5], array_column($ports, 'port'));
        $this->assertSame([6, 0, 5], array_slice(array_column($ports, 'link'), 0, 3));
        $this->assertSame(3.0, $switch->latestValue('switch.ports.up'));
        // No answer: nothing is stored.
        $this->assertSame(0, SwitchService::record($relay, [['id' => $switch->id, 'status' => 0, 'body' => null, 'error' => 'timeout']]));
    }

    public function test_what_the_device_says_and_the_version_that_answered_are_remembered(): void
    {
        Cache::flush();
        $switch = $this->snmpSwitch();
        $relay = $this->relay('1.39.0');
        $c = SwitchService::SNMP_COLUMNS;
        $body = $this->snmpAnswer()."\n".$c['sys_name'].'.0 x:'.bin2hex('sw-office')."\n".$c['sys_descr'].'.0 x:'.bin2hex("Managed switch\x00 24 ports");

        $this->assertSame(['name' => 'sw-office', 'descr' => 'Managed switch 24 ports'], SwitchService::system($body));
        $this->assertSame(['name' => null, 'descr' => null], SwitchService::system($this->snmpAnswer()));

        // The device answers SNMP v1 only: the version is remembered and the agent tries it first next time.
        SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => $body, 'error' => null, 'version' => 0]]);

        $this->assertSame(['sw-office', 'Managed switch 24 ports', '1'], array_values(SwitchService::info($switch)));
        $this->assertSame(0, SwitchService::probe($switch, $relay)['snmp']['version']);
        // Not a version of SNMP: not remembered.
        SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => $body, 'error' => null, 'version' => 7]]);
        $this->assertSame(0, SwitchService::probe($switch, $relay)['snmp']['version']);
    }

    public function test_a_device_that_has_no_ethernet_port_still_tells_what_it_is(): void
    {
        Cache::flush();
        $switch = $this->snmpSwitch();
        $c = SwitchService::SNMP_COLUMNS;

        $taken = SwitchService::record($this->relay('1.39.0'), [['id' => $switch->id, 'status' => 200, 'body' => $c['sys_name'].'.0 x:'.bin2hex('ups'), 'error' => null, 'version' => 1]]);

        $this->assertSame(0, $taken);
        $this->assertSame('ups', SwitchService::info($switch)['name']);
        $this->assertNull(SwitchService::ports($switch));
    }

    public function test_the_snmp_community_alone_makes_a_device_an_snmp_device(): void
    {
        $this->actingAs(User::factory()->create());
        $nas = $this->pingDevice('nas', '00:11:22:33:44:55');

        Livewire::test(\App\Livewire\SnmpSettings::class, ['deviceId' => $nas->id])->assertSet('community', '')->set('community', 'public')->call('save')->assertHasNoErrors();

        $fresh = $nas->fresh();
        $this->assertSame('snmp', $fresh->switch_type);
        $this->assertTrue(blank($fresh->switch_user));
        $this->assertNotSame('public', $fresh->switch_password);
        $this->assertSame('public', SwitchService::password($fresh));
        $this->assertTrue(SwitchService::enabled($fresh));
        $stored = $fresh->switch_password;
        // Shown again; saved as it is, it is not encrypted again; a new one is stored.
        Livewire::test(\App\Livewire\SnmpSettings::class, ['deviceId' => $nas->id])->assertSet('community', 'public')->call('save');
        $this->assertSame($stored, $nas->fresh()->switch_password);
        Livewire::test(\App\Livewire\SnmpSettings::class, ['deviceId' => $nas->id])->set('community', 'private')->call('save');
        $this->assertSame('private', SwitchService::password($nas->fresh()));
        // The Ping settings leave it alone.
        Livewire::test(\App\Livewire\PingSettings::class, ['deviceId' => $nas->id])->call('save')->assertHasNoErrors();
        $this->assertTrue(SwitchService::enabled($nas->fresh()));
        // Empty: off, the device stays. A community that is not one is refused.
        Livewire::test(\App\Livewire\SnmpSettings::class, ['deviceId' => $nas->id])->set('community', "bad\ncommunity")->call('save')->assertHasErrors('community');
        Livewire::test(\App\Livewire\SnmpSettings::class, ['deviceId' => $nas->id])->set('community', '')->call('save')->assertHasNoErrors();
        $this->assertFalse(SwitchService::enabled($nas->fresh()));
        $this->assertNotNull(Device::find($nas->id));
    }

    public function test_snmp_and_the_tplink_login_replace_each_other_and_empty_does_not_touch_the_other(): void
    {
        $this->actingAs(User::factory()->create());
        $archer = $this->pingDevice('archer', '50:C7:BF:AA:BB:CC');
        $archer->forceFill(['switch_type' => 'tplink', 'switch_user' => 'admin', 'switch_password' => SwitchService::storePassword('s3cret')])->save();

        // An empty community does not switch the TP-Link reading off.
        Livewire::test(\App\Livewire\SnmpSettings::class, ['deviceId' => $archer->id])->set('community', '')->call('save');
        $this->assertSame('tplink', $archer->fresh()->switch_type);
        Livewire::test(\App\Livewire\SnmpSettings::class, ['deviceId' => $archer->id])->set('community', 'public')->call('save');
        $this->assertSame('snmp', $archer->fresh()->switch_type);
        // The empty user name of the TP-Link window does not switch SNMP off.
        Livewire::test(\App\Livewire\TplinkSettings::class, ['deviceId' => $archer->id])->set('user', '')->call('save');
        $this->assertSame('snmp', $archer->fresh()->switch_type);
    }

    private function pingDevice(string $name, string $mac): Device
    {
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => $name, 'os' => '', 'token' => hash('sha256', $name), 'ping_address' => '192.168.1.'.(20 + strlen($name)), 'ping_prefix' => 24, 'ping_mac' => $mac]);
        $device->save();

        return $device->fresh();
    }

    /** The interface table of snmpAnswer() with a MAC table: 2 MAC addresses on port 1 (a trunk), 1 on port 3, one on the VLAN table too. */
    private function snmpAnswerWithMacs(): string
    {
        $c = SwitchService::SNMP_COLUMNS;

        return $this->snmpAnswer()."\n".implode("\n", [
            $c['bridge_port'].'.1 1', $c['bridge_port'].'.2 2', $c['bridge_port'].'.3 3',
            $c['fdb'].'.0.17.34.51.68.85 3',
            $c['fdb_vlan'].'.10.0.17.34.51.68.85 3',
            $c['fdb'].'.0.17.34.51.68.86 1', $c['fdb'].'.0.17.34.51.68.87 1',
            // The broadcast address and the entry of the switch itself (port 0) are no device.
            $c['fdb'].'.255.255.255.255.255.255 1', $c['fdb'].'.0.17.34.51.68.88 0',
        ]);
    }

    public function test_a_device_is_plugged_into_the_port_where_its_mac_is_learned(): void
    {
        Cache::flush();
        $switch = $this->snmpSwitch();
        $relay = $this->relay('1.39.0');
        $nas = $this->pingDevice('nas', '00:11:22:33:44:55');
        $byHand = $this->pingDevice('printer', '00:11:22:33:44:56');
        $byHand->forceFill(['switch_id' => $switch->id, 'switch_port' => 2])->save();

        $body = $this->snmpAnswerWithMacs();
        SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => $body, 'error' => null]]);

        $this->assertSame([3, 'auto'], [$nas->fresh()->switch_port, $nas->fresh()->switch_auto]);
        $this->assertSame($switch->id, $nas->fresh()->switch_id);
        // Set by hand: the table does not move it.
        $this->assertSame(2, $byHand->fresh()->switch_port);
        $this->assertNull($byHand->fresh()->switch_auto);

        // Taken off the port by hand: not paired again, even when the table changes.
        SwitchService::unplug($nas->fresh());
        $this->assertSame('off', $nas->fresh()->switch_auto);
        SwitchService::pair($switch, [3 => ['00:11:22:33:44:55', '00:11:22:33:44:77']]);
        $this->assertNull($nas->fresh()->switch_id);
    }

    public function test_a_trunk_with_many_mac_addresses_is_not_a_place_of_a_device(): void
    {
        Cache::flush();
        $switch = $this->snmpSwitch();
        $nas = $this->pingDevice('nas', '00:11:22:33:44:55');
        $macs = array_map(fn (int $i) => sprintf('00:11:22:33:55:%02X', $i), range(1, 20));

        SwitchService::pair($switch, [1 => ['00:11:22:33:44:55', ...$macs]]);

        $this->assertNull($nas->fresh()->switch_id);
    }

    private function printer(): Device
    {
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => 'printer', 'os' => '', 'token' => hash('sha256', 'printer'), 'ping_address' => '192.168.1.50', 'ping_prefix' => 24])->save();

        return $device->fresh();
    }

    public function test_devices_are_plugged_into_a_port_of_the_switch_and_the_map_shows_it(): void
    {
        $switch = $this->switchDevice();
        $printer = $this->printer();
        $relay = $this->relay();
        SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => self::PAGE, 'error' => null]]);

        // The devices of its network without a port (not the switch itself).
        $ids = array_column(SwitchService::candidates($switch, 3), 'id');
        $this->assertContains($printer->id, $ids);
        $this->assertNotContains($switch->id, $ids);

        $this->assertSame(1, SwitchService::assign($switch, 3, [$printer->id]));
        $this->assertSame([$switch->id, 3], [$printer->fresh()->switch_id, $printer->fresh()->switch_port]);
        // Plugged in: not a candidate of another port, but of its own.
        $this->assertNotContains($printer->id, array_column(SwitchService::candidates($switch, 4), 'id'));
        $this->assertSame([true], array_column(array_filter(SwitchService::candidates($switch, 3), fn (array $row) => $row['id'] === $printer->id), 'selected'));

        $nodes = collect(\App\Support\NetworkMap::build()['nodes'])->keyBy('id');
        $card = $nodes['device:'.$switch->id]['switch'];
        $this->assertCount(5, $card['ports']);
        $this->assertSame([$printer->displayName], array_column(collect($card['ports'])->firstWhere('port', 3)['devices'], 'name'));
        $this->assertContains('port 3', $nodes['device:'.$printer->id]['badges']);

        // Not selected any more: free again. A deleted switch frees its devices too.
        $this->assertSame(1, SwitchService::assign($switch, 3, []));
        $this->assertNull($printer->fresh()->switch_port);
        SwitchService::assign($switch, 5, [$printer->id]);
        $switch->delete();
        $this->assertNull($printer->fresh()->switch_id);
    }

    public function test_the_modal_of_a_port_saves_the_devices_ticked(): void
    {
        $this->actingAs(User::factory()->create());
        $switch = $this->switchDevice();
        $printer = $this->printer();
        $this->relay();

        Livewire::test(\App\Livewire\Networks\SwitchPort::class, ['deviceId' => $switch->id, 'port' => 2])
            ->assertSee('printer')
            ->set('selected', [$printer->id])
            ->call('save')
            ->assertDispatched('closeModal');

        $this->assertSame([$switch->id, 2], [$printer->fresh()->switch_id, $printer->fresh()->switch_port]);
    }

    public function test_a_device_plugged_into_a_port_without_a_link_gets_an_alert(): void
    {
        $switch = $this->switchDevice();
        $printer = $this->printer();
        $relay = $this->relay();
        // The ports 2, 4 and 5 have no link (PAGE).
        SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => self::PAGE, 'error' => null]]);
        $switch->forceFill(['last_seen_at' => now()])->saveQuietly();
        SwitchService::assign($switch, 2, [$printer->id]);

        // It still answers: the cable is somewhere else, the port can be removed.
        $printer->forceFill(['last_seen_at' => now()])->saveQuietly();
        $alert = collect(\App\Support\SmartAlerts::for($printer->fresh()))->firstWhere('key', 'switch_port');
        $this->assertSame('unplugPort', $alert['action']['command']);
        $this->assertStringContainsString('still answers', $alert['message']);

        // It does not: the port is the reason, nothing to do.
        $printer->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        $alert = collect(\App\Support\SmartAlerts::for($printer->fresh()))->firstWhere('key', 'switch_port');
        $this->assertNull($alert['action']);
        $this->assertStringContainsString('check the cable', $alert['message']);

        // A port with a link: no alert. A switch that does not answer says nothing about its ports.
        SwitchService::assign($switch, 2, []);
        SwitchService::assign($switch, 1, [$printer->id]);
        $this->assertNull(collect(\App\Support\SmartAlerts::for($printer->fresh()))->firstWhere('key', 'switch_port'));
        SwitchService::assign($switch, 1, []);
        SwitchService::assign($switch, 2, [$printer->id]);
        $switch->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        $this->assertNull(collect(\App\Support\SmartAlerts::for($printer->fresh()))->firstWhere('key', 'switch_port'));
        $switch->forceFill(['last_seen_at' => now()])->saveQuietly();

        // Remove the port (the action of the alert).
        $printer->forceFill(['last_seen_at' => now()])->saveQuietly();
        $this->assertNull(\App\Support\SmartAlerts::run($printer->fresh(), 'switch_port'));
        $this->assertNull($printer->fresh()->switch_id);
    }

    public function test_a_router_that_is_the_gateway_is_one_node_with_its_port(): void
    {
        $switch = $this->switchDevice();
        $relay = $this->relay();
        SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => self::PAGE, 'error' => null]]);
        // The router is added as a ping-only device at the address its LAN reports as the gateway (the agent's interface).
        $data = json_decode(json_encode($relay->data), true);
        $data['machine']['Networks'][0]['Gateway'] = '192.168.1.1';
        $data['machine']['Networks'][0]['GatewayMac'] = 'AA-BB-CC-00-00-01';
        $relay->forceFill(['data' => json_encode($data)])->saveQuietly();
        $router = new Device;
        $router->forceFill(['kind' => 'ping', 'name' => 'Turris', 'os' => '', 'token' => hash('sha256', 'turris'), 'ping_address' => '192.168.1.1', 'ping_prefix' => 24])->save();
        SwitchService::assign($switch, 3, [$router->id]);

        $map = \App\Support\NetworkMap::build();
        $nodes = collect($map['nodes'])->keyBy('id');

        // One node for the router, the gateway, with its port: not a second one among the devices.
        $this->assertFalse($nodes->has('device:'.$router->id));
        $gateway = $nodes->first(fn (array $node) => $node['kind'] === 'gateway');
        $this->assertSame('Turris', $gateway['label']);
        $this->assertContains('port 3', $gateway['badges']);
        $this->assertStringContainsString('Plugged into', (string) $gateway['title']);
    }

    public function test_a_router_that_is_the_gateway_and_is_read_over_snmp_has_its_ports_on_the_gateway(): void
    {
        Cache::flush();
        $relay = $this->relay('1.39.0');
        $data = json_decode(json_encode($relay->data), true);
        $data['machine']['Networks'][0]['Gateway'] = '192.168.1.1';
        $data['machine']['Networks'][0]['GatewayMac'] = 'AA-BB-CC-00-00-01';
        $relay->forceFill(['data' => json_encode($data)])->saveQuietly();
        $router = new Device;
        $router->forceFill(['kind' => 'ping', 'name' => 'Turris', 'os' => '', 'token' => hash('sha256', 'turris'), 'ping_address' => '192.168.1.1', 'ping_prefix' => 24,
            'switch_type' => 'snmp', 'switch_password' => SwitchService::storePassword('public')])->save();
        SwitchService::record($relay, [['id' => $router->id, 'status' => 200, 'body' => $this->snmpAnswer(), 'error' => null, 'version' => 1]]);

        $nodes = collect(\App\Support\NetworkMap::build()['nodes'])->keyBy('id');

        // Still one node (the gateway), now with the ports: the same card as of a switch among the devices.
        $this->assertFalse($nodes->has('device:'.$router->id));
        $gateway = $nodes->first(fn (array $node) => $node['kind'] === 'gateway');
        $this->assertSame('Turris', $gateway['label']);
        $this->assertSame($router->id, $gateway['switch']['id']);
        $this->assertSame([1, 2, 3, 5], array_column($gateway['switch']['ports'], 'port'));
        $this->assertSame([6, 0, 5, 5], array_column($gateway['switch']['ports'], 'link'));
    }

    public function test_the_card_of_the_ports_shows_the_devices_plugged_into_a_port_as_links(): void
    {
        $switch = $this->switchDevice();
        $printer = $this->printer();
        $relay = $this->relay();
        SwitchService::record($relay, [['id' => $switch->id, 'status' => 200, 'body' => self::PAGE, 'error' => null]]);
        SwitchService::assign($switch, 3, [$printer->id]);

        $html = view('partials.device.switch-ports', ['device' => $switch->fresh()])->render();

        $this->assertStringContainsString('printer', $html);
        $this->assertStringContainsString(route('devices', ['selectedDeviceId' => $printer->id]), $html);
    }
}
