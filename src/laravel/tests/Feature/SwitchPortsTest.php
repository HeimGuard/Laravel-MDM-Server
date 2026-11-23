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

    public function test_the_settings_of_the_switch_are_saved_with_the_password_encrypted(): void
    {
        $this->actingAs(User::factory()->create());
        $switch = $this->switchDevice(false);

        Livewire::test(\App\Livewire\PingSettings::class, ['deviceId' => $switch->id])
            ->set('switchType', 'tplink')
            ->call('save')
            ->assertHasErrors('switchUser')
            ->set('switchUser', 'admin')
            ->set('switchPassword', 's3cret')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $switch->fresh();
        $this->assertSame(['tplink', 'admin'], [$fresh->switch_type, $fresh->switch_user]);
        $this->assertNotSame('s3cret', $fresh->switch_password);
        $this->assertTrue(SwitchService::enabled($fresh));
        // A blank password keeps the stored one; no kind removes the settings.
        Livewire::test(\App\Livewire\PingSettings::class, ['deviceId' => $switch->id])->call('save')->assertHasNoErrors();
        $this->assertTrue(SwitchService::enabled($switch->fresh()));
        Livewire::test(\App\Livewire\PingSettings::class, ['deviceId' => $switch->id])->set('switchType', '')->call('save');
        $this->assertFalse(SwitchService::enabled($switch->fresh()));
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
}
