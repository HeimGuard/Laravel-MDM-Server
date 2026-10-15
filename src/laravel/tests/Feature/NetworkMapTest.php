<?php

namespace Tests\Feature;

use App\Livewire\Networks\Page;
use App\Models\Device;
use App\Models\NetworkSite;
use App\Models\User;
use App\Support\NetworkMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The Networks page: the map built from what the agents report. */
class NetworkMapTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $name, ?string $publicIp, array $networks, bool $online = true): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $name);
        $device->name = $name;
        $device->public_ip = $publicIp;
        $device->data = json_encode(['machine' => ['Hostname' => $name, 'AgentVersion' => '1.15.0', 'Platform' => 'linux', 'Drives' => [], 'Networks' => $networks]]);
        $device->save();
        $device->forceFill(['last_seen_at' => $online ? now() : now()->subHour()])->saveQuietly();

        return $device->fresh();
    }

    private function nic(string $name, string $type, string $ip, int $prefix = 24, bool $up = true, ?string $gateway = null, ?string $gatewayMac = null): array
    {
        return ['Name' => $name, 'Type' => $type, 'Connected' => $up, 'Status' => $up ? 'Up' : 'Down', 'Mac' => 'AA-00-00-00-00-01', 'IPAddresses' => [$ip],
            'Addresses' => [['Address' => $ip, 'PrefixLength' => $prefix]], 'Gateway' => $gateway, 'GatewayMac' => $gatewayMac];
    }

    public function test_the_map_goes_from_the_internet_through_sites_and_gateways_to_networks_and_devices(): void
    {
        config(['mdm.public_address' => '31.30.4.122']);
        $gw = '50-C7-BF-AA-BB-CC';
        $nas = $this->agent('NAS', '192.168.1.5', [$this->nic('eth0', 'lan', '192.168.1.5', 24, true, '192.168.1.1', $gw)]);
        $laptop = $this->agent('LAPTOP', '31.30.4.122', [
            $this->nic('eth0', 'lan', '192.168.1.42', 24, true, '192.168.1.1', $gw),
            $this->nic('wlan0', 'wifi', '192.168.77.123', 24, false, '192.168.77.1', $gw),
            $this->nic('wg0', 'vpn', '10.8.0.7', 32),
            $this->nic('docker0', 'docker', '172.17.0.1', 16),
        ]);
        $office = $this->agent('OFFICE-SRV', '89.24.10.5', [$this->nic('eth0', 'lan', '10.0.0.2'), $this->nic('wg0', 'vpn', '10.8.0.3', 24)]);
        $vps = $this->agent('VPS', '185.12.4.9', [$this->nic('eth0', 'lan', '185.12.4.9')], online: false);
        $printer = new Device;
        $printer->forceFill(['kind' => 'ping', 'name' => 'Printer', 'os' => '', 'token' => hash('sha256', 'p'), 'ping_address' => '192.168.1.30', 'ping_prefix' => 24, 'ping_relay_id' => $nas->id, 'last_seen_at' => now()])->save();

        $map = NetworkMap::build();
        $nodes = collect($map['nodes'])->keyBy('id');
        $edges = collect($map['edges'])->keyBy('id');

        // Sites by public address; the one reaching the server over a private address is at the server's.
        $this->assertSame(['31.30.4.122', '89.24.10.5', '185.12.4.9'], $nodes->where('kind', 'site')->pluck('label')->values()->all());
        $this->assertSame('wan', $edges['internet>site:31.30.4.122']['type']);
        // One gateway (one MAC) for both networks of the home router.
        $gateway = 'gw:31.30.4.122|50:C7:BF:AA:BB:CC';
        $this->assertSame('192.168.1.1 · 192.168.77.1', $nodes[$gateway]['sub']);
        $this->assertTrue($edges->has("site:31.30.4.122>{$gateway}"));
        $this->assertTrue($edges->has("{$gateway}>net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24"));
        $this->assertTrue($edges->has("{$gateway}>net:gw:50:C7:BF:AA:BB:CC|192.168.77.0/24"));
        // No gateway reported: the site links to its network directly.
        $this->assertTrue($edges->has('site:89.24.10.5>net:89.24.10.5|10.0.0.0/24'));
        // A VPN spans the sites, over the internet; a /32 of a client is in its /24. Docker is left out.
        $this->assertSame('vpn', $nodes['net:vpn|10.8.0.0/24']['network']);
        $this->assertSame('tunnel', $edges['internet>net:vpn|10.8.0.0/24']['type']);
        $this->assertFalse($nodes->has('net:31.30.4.122|172.17.0.0/16'));

        // A device in several networks: a link to each, the disconnected one is down.
        $this->assertSame('lan', $edges["net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24>device:{$laptop->id}"]['type']);
        $this->assertFalse($edges["net:gw:50:C7:BF:AA:BB:CC|192.168.77.0/24>device:{$laptop->id}"]['up']);
        $this->assertSame('vpn', $edges["net:vpn|10.8.0.0/24>device:{$laptop->id}"]['type']);
        $this->assertTrue($edges->has("net:vpn|10.8.0.0/24>device:{$office->id}"));
        // The ping-only device is in the network of its address, its relay pings it.
        $this->assertTrue($edges->has("net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24>device:{$printer->id}"));
        $this->assertContains('pings 1', $nodes["device:{$nas->id}"]['badges']);
        // Offline, isolated (no other agent in its network).
        $this->assertSame('offline', $nodes["device:{$vps->id}"]['state']);
        $this->assertTrue($nodes["device:{$vps->id}"]['isolated']);
        $this->assertFalse($nodes["device:{$nas->id}"]['isolated']);
        // This server: in the network the agents reach it from over private addresses.
        $this->assertTrue($edges->has('net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24>server'));
        $this->assertSame('31.30.4.122', $nodes['site:31.30.4.122']['group']);

        // The list: busiest first, VPNs last.
        $this->assertSame('192.168.1.0/24', $map['networks'][0]['cidr']);
        $this->assertSame('vpn', collect($map['networks'])->last()['kind']);
        $this->assertSame('192.168.1.1', $map['networks'][0]['gateway']);
    }

    public function test_one_lan_is_one_network_whatever_address_its_devices_reach_the_server_from(): void
    {
        // The server is in the LAN; its name resolves to nothing public, MDM_PUBLIC_ADDRESS is not set.
        config(['mdm.public_address' => null, 'app.url' => 'http://localhost']);
        $gw = '50-C7-BF-AA-BB-CC';
        // One goes over the router's public address (hairpin NAT), the others from inside; one has
        // no gateway MAC (the router not in its ARP table).
        $asus = $this->agent('ASUS-PC-2', '62.141.23.141', [$this->nic('Ethernet', 'lan', '192.168.1.20', 24, true, '192.168.1.1', $gw)]);
        $docker = $this->agent('docker-host', '192.168.1.5', [$this->nic('ens18', 'lan', '192.168.1.5', 24, true, '192.168.1.1', $gw)]);
        $pihole = $this->agent('pi-hole', '192.168.1.6', [$this->nic('eth0', 'lan', '192.168.1.6', 24, true, '192.168.1.1')]);
        $office = $this->agent('OFFICE', '89.24.10.5', [$this->nic('eth0', 'lan', '192.168.1.50', 24, true, '192.168.1.1', 'AA-BB-CC-00-00-01')]);

        $map = NetworkMap::build();
        $networks = collect($map['networks']);

        // The home LAN once, with the public address of the router, and the office's own LAN.
        $home = $networks->firstWhere('id', 'net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24');
        $this->assertSame([$asus->id, $docker->id, $pihole->id], collect($home['devices'])->pluck('id')->sort()->values()->all());
        $this->assertSame('62.141.23.141', $home['site']);
        $this->assertSame('89.24.10.5', $networks->firstWhere('id', 'net:gw:AA:BB:CC:00:00:01|192.168.1.0/24')['site']);
        $this->assertCount(2, $networks);
        // This server is in it (its agents reach it from inside), at the router's address.
        $edges = collect($map['edges'])->keyBy('id');
        $this->assertTrue($edges->has('net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24>server'));
        $this->assertSame('public · this server', collect($map['nodes'])->firstWhere('id', 'site:62.141.23.141')['sub']);
    }

    public function test_a_device_on_a_vpn_to_the_server_is_behind_the_addresses_its_interfaces_reach_the_internet_from(): void
    {
        config(['mdm.public_address' => '62.141.23.141']);
        $nas = $this->agent('NAS', '192.168.1.5', [$this->nic('eth0', 'lan', '192.168.1.5', 24, true, '192.168.1.1', '50-C7-BF-AA-BB-CC')]);
        // At home (split VPN to the server): the server sees the tunnel's address; each connection
        // goes to the internet through its own router (agents 1.21.0+ report what the internet sees).
        $laptop = $this->agent('ELITEBOOK', '10.8.0.4', [
            $this->nic('Ethernet', 'lan', '192.168.1.52', 24, true, '192.168.1.1', 'AA-BB-CC-00-00-01') + ['PublicAddress' => '90.176.236.141'],
            $this->nic('Wi-Fi', 'wifi', '10.3.80.5', 21, true, '10.3.80.1', 'AA-BB-CC-00-00-02') + ['PublicAddress' => '78.80.1.2'],
            $this->nic('wg0', 'vpn', '10.8.0.4', 24),
        ]);
        // An older agent on the VPN: where it is is not known, it is not in the server's network.
        $old = $this->agent('OLD', '10.8.0.9', [$this->nic('eth0', 'lan', '192.168.50.9', 24, true, '192.168.50.1', 'AA-BB-CC-00-00-03'), $this->nic('wg0', 'vpn', '10.8.0.9', 24)]);

        $this->assertTrue($laptop->reachesOverVpn);
        $this->assertFalse($nas->reachesOverVpn);
        $this->assertSame(['90.176.236.141', '78.80.1.2'], NetworkSite::of($laptop));

        $map = NetworkMap::build();
        $networks = collect($map['networks'])->keyBy('id');
        $this->assertSame('90.176.236.141', $networks['net:gw:AA:BB:CC:00:00:01|192.168.1.0/24']['site']);
        $this->assertSame('78.80.1.2', $networks['net:gw:AA:BB:CC:00:00:02|10.3.80.0/21']['site']);
        $this->assertSame('?', $networks['net:gw:AA:BB:CC:00:00:03|192.168.50.0/24']['site']);
        $this->assertSame('62.141.23.141', $networks['net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24']['site']);
        $nodes = collect($map['nodes'])->keyBy('id');
        $this->assertSame('public · this server', $nodes['site:62.141.23.141']['sub']);
        $this->assertSame('public', $nodes['site:90.176.236.141']['sub']);
        // This server stays in its own network, not in the laptop's home LAN.
        $this->assertTrue(collect($map['edges'])->keyBy('id')->has('net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24>server'));
        $this->assertSame($old->id, collect($networks['net:gw:AA:BB:CC:00:00:03|192.168.50.0/24']['devices'])->pluck('id')->sole());
    }

    public function test_public_addresses_devices_were_behind_stay_faint(): void
    {
        config(['mdm.public_address' => '89.24.10.5']);
        $this->travelTo(now()->subDays(3));
        NetworkSite::record(['31.30.4.122', '89.24.10.5']);
        $this->travelBack();
        NetworkSite::record(['89.24.10.5']);
        $this->agent('OFFICE', '89.24.10.5', [$this->nic('eth0', 'lan', '10.0.0.2')]);

        $map = NetworkMap::build();
        $nodes = collect($map['nodes'])->keyBy('id');
        $this->assertSame('up', $nodes['site:89.24.10.5']['state']);
        $this->assertSame('down', $nodes['site:31.30.4.122']['state']);
        $this->assertSame(['0 devices'], $nodes['site:31.30.4.122']['badges']);
        $this->assertStringStartsWith('public · last seen 3 days ago', $nodes['site:31.30.4.122']['sub']);
        $this->assertFalse(collect($map['edges'])->keyBy('id')['internet>site:31.30.4.122']['up']);
        $this->assertSame(2, NetworkSite::query()->count());
    }

    public function test_a_device_without_the_gateway_mac_going_over_the_public_address_joins_its_lan(): void
    {
        // The LAN's public address is the server's (it is in it), here from MDM_PUBLIC_ADDRESS.
        config(['mdm.public_address' => '62.141.23.141', 'app.url' => 'http://localhost']);
        $gw = '50-C7-BF-AA-BB-CC';
        $docker = $this->agent('docker-host', '192.168.1.5', [$this->nic('ens18', 'lan', '192.168.1.5', 24, true, '192.168.1.1', $gw)]);
        // An old agent that does not report the router, offline, reaching the server over the router's address.
        $asus = $this->agent('ASUS-PC-2', '62.141.23.141', [$this->nic('Ethernet', 'lan', '192.168.1.104', 24)], online: false);
        // Another home that also uses 192.168.1.0/24 stays another network.
        $other = $this->agent('OTHER', '89.24.10.5', [$this->nic('eth0', 'lan', '192.168.1.9', 24)]);

        $networks = collect(NetworkMap::build()['networks']);

        $home = $networks->firstWhere('id', 'net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24');
        $this->assertSame([$docker->id, $asus->id], collect($home['devices'])->pluck('id')->sort()->values()->all());
        $this->assertCount(2, $networks);
        $this->assertSame([$other->id], collect($networks->firstWhere('id', 'net:89.24.10.5|192.168.1.0/24')['devices'])->pluck('id')->all());
    }

    public function test_the_server_asks_for_its_public_address_when_nothing_else_tells_it(): void
    {
        config(['mdm.detect_public_address' => true, 'mdm.public_address' => null, 'app.url' => 'http://localhost', 'mdm.public_address_url' => 'https://ifconfig.me/ip']);
        Http::fake(['ifconfig.me/*' => Http::response("203.0.113.9\n")]);

        $this->assertSame(['203.0.113.9'], NetworkMap::serverAddresses());
        $this->assertSame(['203.0.113.9'], NetworkMap::serverAddresses());
        Http::assertSentCount(1);

        // The map: the server's LAN (its agents reach it from inside) has that address.
        $this->agent('NAS', '192.168.1.5', [$this->nic('eth0', 'lan', '192.168.1.5', 24, true, '192.168.1.1', '50-C7-BF-AA-BB-CC')]);
        $site = collect(NetworkMap::build()['nodes'])->firstWhere('id', 'site:203.0.113.9');
        $this->assertSame(['203.0.113.9', 'public · this server'], [$site['label'], $site['sub']]);
    }

    public function test_the_public_address_lookup_is_careful(): void
    {
        config(['mdm.detect_public_address' => true, 'mdm.public_address' => null, 'app.url' => 'http://localhost']);

        // An answer that is no public address (an error page, a private address) is none, and not asked again for a while.
        Http::fake(['*' => Http::response('<html>Too many requests</html>', 429)]);
        $this->assertSame([], NetworkMap::serverAddresses());
        $this->assertSame([], NetworkMap::serverAddresses());
        Http::assertSentCount(1);

        Cache::flush();
        Http::fake(['*' => Http::response('192.168.1.1')]);
        $this->assertSame([], NetworkMap::serverAddresses());

        // Set by hand, or switched off: nothing is asked.
        Cache::flush();
        Http::fake();
        config(['mdm.public_address' => '198.51.100.7']);
        $this->assertSame(['198.51.100.7'], NetworkMap::serverAddresses());
        config(['mdm.public_address' => null, 'mdm.detect_public_address' => false]);
        $this->assertSame([], NetworkMap::serverAddresses());
        Http::assertNothingSent();
    }

    public function test_the_server_address_comes_from_its_name(): void
    {
        config(['mdm.public_address' => null, 'app.url' => 'https://203.0.113.10']);
        $this->assertSame(['203.0.113.10'], NetworkMap::serverAddresses());
        config(['app.url' => 'http://localhost']);
        $this->assertSame([], NetworkMap::serverAddresses());
    }

    public function test_the_networks_page_shows_the_map_and_the_list(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        $this->get('/networks')->assertOk()->assertSee('No devices yet');

        $this->agent('NAS', '31.30.4.122', [$this->nic('eth0', 'lan', '192.168.1.5', 24, true, '192.168.1.1')]);
        // The map (filling the window) or the list, one at a time.
        $this->get('/networks')->assertOk()->assertSeeHtml('class="network-map"')->assertDontSee('gateway 192.168.1.1');
        $this->get('/networks?view=list')->assertOk()->assertSee('192.168.1.0/24')->assertSee('gateway 192.168.1.1')->assertDontSeeHtml('class="network-map"');
        Livewire::test(Page::class)->set('view', 'nonsense')->assertSet('view', 'map');
        // A live update hands the map the new data.
        Livewire::test(Page::class)->call('devicesChanged')->assertDispatched('network-map-updated');
    }
}
