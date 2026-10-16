<?php

namespace Tests\Feature;

use App\Livewire\Networks\Page;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\NetworkNeighbour;
use App\Models\User;
use App\Support\AlertEvaluator;
use App\Support\NetworkMap;
use App\Support\SmartAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/** Network discovery: the agents' ARP tables and scans, unknown devices on the Networks page. */
class NetworkDiscoveryTest extends TestCase
{
    use RefreshDatabase;
    use SignsDeviceRequests;

    private function machine(string $level = 'neighbours', string $version = '1.16.0'): array
    {
        return ['Hostname' => 'nas', 'AgentVersion' => $version, 'Platform' => 'linux', 'Drives' => [], 'NetworkDiscovery' => $level, 'Networks' => [
            ['Name' => 'eth0', 'Type' => 'lan', 'Connected' => true, 'Status' => 'Up', 'Mac' => 'AA-00-00-00-00-01', 'IPAddresses' => ['192.168.1.5'],
                'Addresses' => [['Address' => '192.168.1.5', 'PrefixLength' => 24]], 'Gateway' => '192.168.1.1', 'GatewayMac' => '50-C7-BF-AA-BB-CC'],
            ['Name' => 'docker0', 'Type' => 'docker', 'Connected' => true, 'Status' => 'Up', 'Mac' => '02-42-00-00-00-01', 'IPAddresses' => ['172.17.0.1'],
                'Addresses' => [['Address' => '172.17.0.1', 'PrefixLength' => 16]]],
        ]];
    }

    private function agent(string $level = 'neighbours', string $token = 'secret-token', string $version = '1.16.0'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->name = 'nas';
        $device->public_ip = '31.30.4.122';
        $device->data = json_encode(['machine' => $this->machine($level, $version)]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    private function report(string $level, array $neighbours): void
    {
        $this->signedJson('POST', '/api/device', ['machine' => $this->machine($level), 'neighbours' => $neighbours], 'secret-token', ['server' => ['REMOTE_ADDR' => '31.30.4.122']])->assertOk();
    }

    public function test_the_report_records_the_neighbours_of_its_networks(): void
    {
        $this->agent();
        $this->report('neighbours', [
            ['Ip' => '192.168.1.50', 'Mac' => 'b8-27-eb-12-34-56', 'Hostname' => null],
            ['Ip' => '192.168.1.1', 'Mac' => '50-C7-BF-AA-BB-CC'],
            // Not its networks (Docker, another subnet), its own address, multicast, broadcast.
            ['Ip' => '172.17.0.2', 'Mac' => '02:42:ac:11:00:02'],
            ['Ip' => '10.0.0.9', 'Mac' => '00:11:22:33:44:55'],
            ['Ip' => '192.168.1.5', 'Mac' => '00:11:22:33:44:66'],
            ['Ip' => '192.168.1.60', 'Mac' => '01:00:5e:00:00:fb'],
            ['Ip' => '192.168.1.255', 'Mac' => 'ff:ff:ff:ff:ff:ff'],
            ['Ip' => 'nonsense', 'Mac' => 'nonsense'],
        ]);

        $this->assertSame(['192.168.1.1', '192.168.1.50'], NetworkNeighbour::query()->orderBy('ip')->pluck('ip')->all());
        $pi = NetworkNeighbour::query()->where('ip', '192.168.1.50')->firstOrFail();
        $this->assertSame(['gw:50:C7:BF:AA:BB:CC', '192.168.1.0/24', 'B8:27:EB:12:34:56'], [$pi->site, $pi->network, $pi->mac]);
        $this->assertFalse($pi->randomMac);

        // Seen again at another address: the same neighbour (by its MAC), with a name from a scan.
        $this->travel(5)->minutes();
        $this->report('neighbours', [['Ip' => '192.168.1.51', 'Mac' => 'B8:27:EB:12:34:56', 'Hostname' => 'raspberrypi.lan']]);
        $pi->refresh();
        $this->assertSame(['192.168.1.51', 'raspberrypi.lan'], [$pi->ip, $pi->hostname]);
        $this->assertSame(2, NetworkNeighbour::count());
    }

    public function test_nothing_is_recorded_when_discovery_is_off_on_the_device_or_in_the_portal(): void
    {
        $this->agent('off');
        $this->report('off', [['Ip' => '192.168.1.50', 'Mac' => 'b8-27-eb-12-34-56']]);
        $this->assertSame(0, NetworkNeighbour::count());

        NetworkNeighbour::setEnabled(false);
        $this->report('scan', [['Ip' => '192.168.1.50', 'Mac' => 'b8-27-eb-12-34-56']]);
        $this->assertSame(0, NetworkNeighbour::count());
        $this->assertSame(__('Network discovery is turned off in the portal'), Device::first()->commandRefusal('scanNetwork', ['cidr' => '192.168.1.0/24']));

        NetworkNeighbour::setEnabled(true);
        $this->report('scan', [['Ip' => '192.168.1.50', 'Mac' => 'b8-27-eb-12-34-56']]);
        $this->assertSame(1, NetworkNeighbour::count());
    }

    public function test_a_new_address_of_a_ping_only_device_is_confirmed_in_a_smart_alert(): void
    {
        $user = User::factory()->create();
        $rule = AlertRule::query()->create(['user_id' => $user->id, 'type' => 'address_changed', 'target' => [], 'channels' => [], 'enabled' => true]);
        $this->agent();
        $printer = new Device;
        $printer->forceFill(['kind' => 'ping', 'name' => 'Printer', 'os' => '', 'token' => hash('sha256', 'p'), 'ping_address' => '192.168.1.30', 'ping_prefix' => 24, 'ping_mac' => 'AA:BB:CC:00:00:30'])->save();

        $this->report('neighbours', [['Ip' => '192.168.1.31', 'Mac' => 'aa-bb-cc-00-00-30']]);

        // Not moved by itself: the rule is told, the device asks.
        $this->assertSame('192.168.1.30', $printer->fresh()->ping_address);
        $this->assertSame(1, $rule->events()->where('device_id', $printer->id)->count());
        $this->assertStringContainsString('is at 192.168.1.31 now, the portal pings 192.168.1.30', $rule->events()->first()->message);
        $alert = collect(SmartAlerts::for($printer->fresh()))->firstWhere('key', 'address');
        $this->assertSame('moveAddress', $alert['action']['command']);
        $this->assertSame(['ip' => '192.168.1.31'], $alert['action']['params']);
        $this->assertNotNull($alert['action']['confirm']);
        // The same address again: told once.
        $this->report('neighbours', [['Ip' => '192.168.1.31', 'Mac' => 'aa-bb-cc-00-00-30']]);
        $this->assertSame(1, $rule->events()->count());

        // On the device page too (ping-only devices have no report, but alerts of their own).
        $this->actingAs($user);
        Livewire::test(\App\Livewire\DeviceDetail::class, ['selectedDeviceId' => $printer->id])->assertSeeLivewire('device-alerts');
        Livewire::test(\App\Livewire\DeviceAlerts::class, ['selectedDeviceId' => $printer->id])->assertSee('The device has another address')->assertSee('Use 192.168.1.31');

        // Confirmed: pinged there, the alert is gone.
        $this->assertNull(SmartAlerts::run($printer->fresh(), 'address', $user));
        $this->assertSame('192.168.1.31', $printer->fresh()->ping_address);
        $this->assertNull(collect(SmartAlerts::for($printer->fresh()))->firstWhere('key', 'address'));
        // Known: not shown as unknown.
        $this->assertCount(0, NetworkNeighbour::unknown());
    }

    public function test_an_old_entry_of_the_arp_table_does_not_flip_the_address(): void
    {
        $user = User::factory()->create();
        $rule = AlertRule::query()->create(['user_id' => $user->id, 'type' => 'address_changed', 'target' => [], 'channels' => [], 'enabled' => true]);
        $this->agent();
        $switch = new Device;
        $switch->forceFill(['kind' => 'ping', 'name' => 'SW3', 'os' => '', 'token' => hash('sha256', 's'), 'ping_address' => '192.168.1.192', 'ping_prefix' => 24, 'ping_mac' => 'CC:BA:BD:68:2E:91'])->save();
        $seenAt = fn () => NetworkNeighbour::query()->where('mac', 'CC:BA:BD:68:2E:91')->sole()->ip;

        // Readdressed: the new address answered lately, the old one is still in the table.
        $this->report('neighbours', [
            ['Ip' => '192.168.1.192', 'Mac' => 'cc-ba-bd-68-2e-91', 'State' => 'stale'],
            ['Ip' => '192.168.1.9', 'Mac' => 'cc-ba-bd-68-2e-91', 'State' => 'fresh'],
        ]);
        $this->assertSame('192.168.1.9', $seenAt());
        $this->assertSame(1, $rule->events()->count());
        $this->assertSame('192.168.1.192', $switch->fresh()->ping_address);
        // An agent that has only the old entry left: it stays at the new one, nobody is told.
        $this->report('neighbours', [['Ip' => '192.168.1.192', 'Mac' => 'cc-ba-bd-68-2e-91', 'State' => 'stale']]);
        $this->assertSame('192.168.1.9', $seenAt());
        // An older agent (no state) with both addresses: it stays where it was seen.
        $this->report('neighbours', [
            ['Ip' => '192.168.1.192', 'Mac' => 'cc-ba-bd-68-2e-91'],
            ['Ip' => '192.168.1.9', 'Mac' => 'cc-ba-bd-68-2e-91'],
        ]);
        $this->assertSame('192.168.1.9', $seenAt());
        $this->assertSame(1, $rule->events()->count());
        $this->assertSame('192.168.1.9', collect(SmartAlerts::for($switch->fresh()))->firstWhere('key', 'address')['action']['params']['ip']);

        // Nothing talks to the new address, the table only has an old entry of it, and the device
        // does not answer where the portal pings it: that is where it went.
        $sw1 = new Device;
        $sw1->forceFill(['kind' => 'ping', 'name' => 'SW1', 'os' => '', 'token' => hash('sha256', 'w'), 'ping_address' => '192.168.1.241', 'ping_prefix' => 24, 'ping_mac' => 'E0:D3:62:8B:43:2C'])->save();
        NetworkNeighbour::query()->create(['site' => 'gw:50:C7:BF:AA:BB:CC', 'mac' => 'E0:D3:62:8B:43:2C', 'ip' => '192.168.1.241', 'network' => '192.168.1.0/24', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subHour()]);
        $this->report('neighbours', [['Ip' => '192.168.1.8', 'Mac' => 'e0-d3-62-8b-43-2c', 'State' => 'stale']]);
        $this->assertSame('192.168.1.8', NetworkNeighbour::query()->where('mac', 'E0:D3:62:8B:43:2C')->sole()->ip);
        $this->assertSame(1, $rule->events()->where('device_id', $sw1->id)->count());
        $this->assertSame('192.168.1.241', $sw1->fresh()->ping_address);
    }

    public function test_unknown_devices_are_on_the_map_and_can_be_added_or_ignored(): void
    {
        config(['mdm.public_address' => '31.30.4.122']);
        $this->agent();
        $this->report('neighbours', [
            ['Ip' => '192.168.1.50', 'Mac' => 'b8-27-eb-12-34-56', 'Hostname' => 'raspberrypi.lan'],
            ['Ip' => '192.168.1.1', 'Mac' => '50-C7-BF-AA-BB-CC'],
            ['Ip' => '192.168.1.77', 'Mac' => '7a:11:22:33:44:55'],
        ]);
        // The report signed in as the device.
        $this->actingAs(User::factory()->create());
        $pi = NetworkNeighbour::query()->where('ip', '192.168.1.50')->firstOrFail();
        $phone = NetworkNeighbour::query()->where('ip', '192.168.1.77')->firstOrFail();
        $this->assertTrue($phone->randomMac);

        $map = NetworkMap::build();
        $nodes = collect($map['nodes'])->keyBy('id');
        $edges = collect($map['edges'])->keyBy('id');
        // The gateway is drawn as the gateway, not as an unknown device.
        // All of a network's unknown devices are one card; a click opens the network's card in the list.
        $key = 'net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24';
        $this->assertSame(['2 unknown devices'], $nodes->where('kind', 'unknown')->pluck('label')->values()->all());
        $this->assertSame('unknown', $edges["{$key}>unknown:{$key}"]['type']);
        $this->assertStringContainsString('/networks?view=list#network-', $nodes["unknown:{$key}"]['url']);
        $this->assertSame([$pi->id, $phone->id], array_column($map['networks'][0]['unknown'], 'id'));

        Livewire::withQueryParams(['view' => 'list'])->test(Page::class)->assertSee('2 unknown devices')->assertSee('raspberrypi.lan')
            ->call('ignore', $phone->id)->assertSee('1 ignored device')
            ->call('add', $pi->id)->assertRedirect();

        $added = Device::query()->where('kind', 'ping')->firstOrFail();
        $this->assertSame(['raspberrypi', '192.168.1.50', 24, 'B8:27:EB:12:34:56'], [$added->name, $added->ping_address, $added->ping_prefix, $added->ping_mac]);
        $this->assertCount(0, NetworkNeighbour::unknown());
    }

    public function test_many_unknown_devices_are_still_one_node(): void
    {
        config(['mdm.public_address' => '31.30.4.122']);
        $this->agent();
        $this->report('neighbours', collect(range(1, 12))->map(fn ($i) => ['Ip' => '192.168.1.'.(100 + $i), 'Mac' => sprintf('00:11:22:33:44:%02X', $i)])->all());

        $nodes = collect(NetworkMap::build()['nodes'])->where('kind', 'unknown');

        $this->assertCount(1, $nodes);
        $this->assertSame('12 unknown devices', $nodes->first()['label']);
    }

    public function test_a_scan_goes_to_an_agent_in_the_network_that_allows_it(): void
    {
        config(['mdm.public_address' => '31.30.4.122']);
        $this->actingAs(User::factory()->create());
        $agent = $this->agent('neighbours');
        $key = 'net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24';

        // Not allowed on the device: no scan.
        $network = collect(NetworkMap::build()['networks'])->firstWhere('id', $key);
        $this->assertNull($network['scan']['agent']);
        $this->assertStringContainsString('network_discovery', $network['scan']['refusal']);
        Livewire::test(Page::class)->call('scan', $key)->assertHasErrors();
        $this->assertSame(0, $agent->commands()->count());

        $agent->forceFill(['data' => json_encode(['machine' => $this->machine('scan')])])->save();
        Livewire::test(Page::class)->call('scan', $key)->assertHasNoErrors();
        $command = $agent->commands()->firstOrFail();
        $this->assertSame(['scanNetwork', ['cidr' => '192.168.1.0/24'], 'scan:192.168.1.0/24'], [$command->command, $command->params, $command->target]);
        $this->assertSame($command->id, collect(NetworkMap::build()['networks'])->firstWhere('id', $key)['scan']['command']['id']);

        // Only networks of /22 and smaller, in the right notation.
        $this->assertNull(\App\Models\DeviceCommand::sanitizeParams('scanNetwork', ['cidr' => '10.0.0.0/16']));
        $this->assertNull(\App\Models\DeviceCommand::sanitizeParams('scanNetwork', ['cidr' => '192.168.1.7/24']));
        // Older agents cannot.
        $old = $this->agent('scan', 'old-token', '1.15.0');
        $this->assertSame(__('Needs agent :version or newer', ['version' => '1.16.0']), $old->commandRefusal('scanNetwork', ['cidr' => '192.168.1.0/24']));
    }

    public function test_system_admins_turn_discovery_off_for_the_portal(): void
    {
        User::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->agent();
        Livewire::test(Page::class)->call('toggleDiscovery')->assertForbidden();

        config(['boilerplate.system_admins' => [(string) $user->id]]);
        $this->actingAs($user->fresh());
        Livewire::test(Page::class)->call('toggleDiscovery');
        $this->assertFalse(NetworkNeighbour::enabled());
    }

    public function test_an_unknown_device_alert_is_sent_once(): void
    {
        $user = User::factory()->create();
        $rule = AlertRule::query()->create(['user_id' => $user->id, 'type' => 'unknown_device', 'target' => [], 'channels' => [], 'enabled' => true]);
        $this->agent();
        $this->report('neighbours', [['Ip' => '192.168.1.50', 'Mac' => 'b8-27-eb-12-34-56']]);

        AlertEvaluator::run();
        AlertEvaluator::run();

        $this->assertSame(1, $rule->events()->count());
        $this->assertStringContainsString('192.168.1.50 appeared in 192.168.1.0/24', $rule->events()->first()->message);
    }

    public function test_installations_start_with_a_script_that_allows_scans(): void
    {
        $script = \App\Models\Script::query()->where('name', 'Allow network scans')->firstOrFail();

        // Detection only on a run: the remediation waits for Remediate.
        $this->assertTrue($script->manual_remediation);
        $this->assertSame('all', $script->platform);
        $this->assertSame($script->fingerprint, \App\Models\Script::fingerprintOf('all', 60, $script->detection, $script->remediation));
        // It passes the checks of the script form.
        $this->assertSame([], \App\Support\PowerShellCheck::errors($script->detection));
        $this->assertSame([], \App\Support\PowerShellCheck::errors($script->remediation));
        $this->assertTrue(\App\Support\PowerShellCheck::exits($script->detection, 0));
        $this->assertTrue(\App\Support\PowerShellCheck::exits($script->detection, 1));
        $this->assertStringContainsString("network_discovery -NotePropertyValue 'scan'", $script->remediation);
    }

    public function test_the_network_card_says_why_each_agent_cannot_scan(): void
    {
        config(['mdm.public_address' => '31.30.4.122']);
        $this->agent('neighbours');
        $old = $this->agent('scan', 'old-token', '1.15.0');
        $old->forceFill(['name' => 'old-pc'])->save();

        $refusal = collect(NetworkMap::build()['networks'])->firstWhere('id', 'net:gw:50:C7:BF:AA:BB:CC|192.168.1.0/24')['scan']['refusal'];

        // The level as of the last report (a remediation shows with the next one), and the old agent.
        $this->assertSame('nas: network_discovery "neighbours" · old-pc: needs agent 1.16.0', $refusal);
    }

    public function test_the_device_menu_scans_its_own_networks(): void
    {
        $this->actingAs(User::factory()->create());
        $agent = $this->agent('neighbours');
        // Docker networks and larger ones than /22 are not offered.
        $this->assertSame(['192.168.1.0/24'], $agent->scannableNetworks);

        // Not allowed on the device: offered with the reason, not sent.
        Livewire::test(\App\Livewire\DeviceCommands::class, ['selectedDeviceId' => $agent->id])
            ->assertSee('Scan 192.168.1.0/24')->assertSee('network_discovery is')
            ->call('scan', '192.168.1.0/24')->assertHasErrors('command');

        $agent->forceFill(['data' => json_encode(['machine' => $this->machine('scan')])])->save();
        Livewire::test(\App\Livewire\DeviceCommands::class, ['selectedDeviceId' => $agent->id])
            ->call('scan', '10.0.0.0/24')->assertHasNoErrors()
            ->call('scan', '192.168.1.0/24')->assertHasNoErrors();
        $this->assertSame([['cidr' => '192.168.1.0/24']], $agent->commands()->where('command', 'scanNetwork')->pluck('params')->all());
    }

    public function test_a_neighbour_seen_from_one_lan_is_one_device_whatever_address_the_agents_report_from(): void
    {
        $outside = $this->agent('neighbours');
        $inside = $this->agent('neighbours', 'inside-token');
        $inside->forceFill(['public_ip' => '192.168.1.7'])->save();

        NetworkNeighbour::record($outside, [['Ip' => '192.168.1.50', 'Mac' => 'b8-27-eb-12-34-56']]);
        NetworkNeighbour::record($inside->fresh(), [['Ip' => '192.168.1.50', 'Mac' => 'b8-27-eb-12-34-56']]);

        $this->assertSame(['gw:50:C7:BF:AA:BB:CC'], NetworkNeighbour::query()->pluck('site')->all());
    }

    public function test_old_neighbours_move_to_their_gateway_and_stay_ignored(): void
    {
        $agent = $this->agent('neighbours');
        $make = fn (string $site, ?string $ignored) => NetworkNeighbour::query()->create([
            'site' => $site, 'network' => '192.168.1.0/24', 'mac' => 'B8:27:EB:12:34:56', 'ip' => '192.168.1.50', 'seen_by' => $agent->id,
            'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subHour(), 'ignored_at' => $ignored,
        ]);
        $make('31.30.4.122', now()->subDay());
        $make('?', null);

        (require database_path('migrations/2026_10_08_000000_key_network_neighbours_by_gateway.php'))->up();

        $row = NetworkNeighbour::query()->sole();
        $this->assertSame('gw:50:C7:BF:AA:BB:CC', $row->site);
        $this->assertNotNull($row->ignored_at);
    }
}
