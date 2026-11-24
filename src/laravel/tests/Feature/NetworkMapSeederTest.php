<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Support\NetworkMap;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The demo network of the seeder (php artisan db:seed): the router is the gateway with its ports, the devices are plugged into the ports. */
class NetworkMapSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_router_is_the_gateway_with_ports_and_devices_plugged_into_them(): void
    {
        $this->seed(DatabaseSeeder::class);

        $nodes = collect(NetworkMap::build()['nodes'])->keyBy('id');
        $gateway = $nodes->first(fn (array $node) => $node['kind'] === 'gateway' && $node['label'] === 'Turris');

        $this->assertNotNull($gateway);
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9, 12, 13], array_column($gateway['switch']['ports'], 'port'));
        // The devices of its ports point at the node of the gateway (the map links them round the node of the network).
        $plugs = $nodes->filter(fn (array $node) => ($node['plug']['switch'] ?? null) === $gateway['id'])->map(fn (array $node) => $node['plug']['port'])->sort()->values()->all();
        $this->assertSame([4, 13], $plugs);
        // The switches have their devices.
        $sw1 = Device::query()->where('token', 'seed-map-8')->firstOrFail();
        $this->assertSame([2, 3, 5], $nodes->filter(fn (array $node) => ($node['plug']['switch'] ?? null) === 'device:'.$sw1->id)->map(fn (array $node) => $node['plug']['port'])->sort()->values()->all());
        // The network says how many of its devices are offline (the seeder has some).
        $network = $nodes->first(fn (array $node) => $node['kind'] === 'network' && ($node['offline'] ?? 0) > 0);
        $this->assertNotNull($network);
        $this->assertStringContainsString((string) $network['offline'].' offline', $network['sub']);
        // Run again: replaced, not doubled.
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(1, Device::query()->where('name', 'Turris')->count());
    }
}
