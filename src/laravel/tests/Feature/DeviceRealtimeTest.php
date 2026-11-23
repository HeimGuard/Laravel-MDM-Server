<?php

namespace Tests\Feature;

use App\Events\DeviceCommandIssued;
use App\Livewire\DeviceCommands;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

class DeviceRealtimeTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'app-key',
                'secret' => 'app-secret',
                'app_id' => 'app-id',
                'options' => ['host' => 'mdm.test', 'port' => 443, 'scheme' => 'https', 'useTLS' => true],
            ],
        ]);

        // Channels were registered on the default (null) broadcaster during boot.
        require base_path('routes/channels.php');
    }

    private function createDevice(string $token): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->save();

        return $device;
    }

    public function test_agent_receives_realtime_connection_details(): void
    {
        $device = $this->createDevice('secret-token');

        $this->withToken('secret-token')->getJson('/api/device/realtime')
            ->assertOk()
            ->assertJson([
                'enabled' => true,
                'key' => 'app-key',
                'host' => 'mdm.test',
                'port' => 443,
                'scheme' => 'https',
                'channel' => 'private-device.'.$device->id,
            ]);
    }

    public function test_agents_connect_to_the_server_address_when_reverb_is_local(): void
    {
        // The Docker image publishes to Reverb inside the container (127.0.0.1:8080), agents come
        // in through the reverse proxy.
        config(['broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http']]);
        $this->createDevice('secret-token');

        $this->withToken('secret-token')->getJson('https://mdm.example.com/api/device/realtime')
            ->assertOk()
            ->assertJson(['host' => 'mdm.example.com', 'port' => 443, 'scheme' => 'https']);

        $this->withToken('secret-token')->getJson('http://mdm.local:3838/api/device/realtime')
            ->assertJson(['host' => 'mdm.local', 'port' => 3838, 'scheme' => 'http']);

        // TLS terminated by a proxy: the app sees plain HTTP, the agent (https -ServerUrl) uses 443.
        $this->withToken('secret-token')->withHeaders(['Host' => 'mdm.example.com'])->getJson('http://mdm.example.com/api/device/realtime')
            ->assertJson(['host' => 'mdm.example.com', 'port' => 80, 'scheme' => 'http']);
    }

    public function test_realtime_is_reported_disabled_without_reverb(): void
    {
        config(['broadcasting.default' => 'null']);
        $this->createDevice('secret-token');

        $this->withToken('secret-token')->getJson('/api/device/realtime')
            ->assertOk()
            ->assertExactJson(['enabled' => false]);
    }

    public function test_device_can_only_subscribe_to_its_own_channel(): void
    {
        $device = $this->createDevice('secret-token');
        $other = $this->createDevice('other-token');

        $this->withToken('secret-token')
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-device.'.$device->id])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->app['auth']->forgetGuards();
        $this->withToken('secret-token')
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-device.'.$other->id])
            ->assertForbidden();
    }

    public function test_channel_auth_requires_device_token(): void
    {
        $device = $this->createDevice('secret-token');

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-device.'.$device->id])
            ->assertUnauthorized();
    }

    public function test_sending_command_broadcasts_it_to_the_device(): void
    {
        Event::fake([DeviceCommandIssued::class]);
        $this->actingAs(User::factory()->create());
        $device = $this->registerDeviceKey($this->createDevice('secret-token'));

        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])
            ->call('sendCommandToDevice', 'restart');

        Event::assertDispatched(DeviceCommandIssued::class, fn ($event) => $event->device->is($device)
            && $event->command === 'restart'
            && $event->broadcastOn()->name === 'private-device.'.$device->id);
        $this->assertSame(['restart'], $device->fresh()->queuedCommands);
    }

    public function test_unknown_command_is_rejected(): void
    {
        Event::fake([DeviceCommandIssued::class]);
        $this->actingAs(User::factory()->create());
        $device = $this->createDevice('secret-token');

        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])
            ->call('sendCommandToDevice', 'format c:');

        Event::assertNotDispatched(DeviceCommandIssued::class);
        $this->assertSame([], $device->fresh()->queuedCommands);
    }

    public function test_command_is_queued_even_if_websocket_server_is_down(): void
    {
        config(['broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false]]);
        $this->actingAs(User::factory()->create());
        $device = $this->registerDeviceKey($this->createDevice('secret-token'));

        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])
            ->call('sendCommandToDevice', 'restart')
            ->assertOk();

        $this->assertSame(['restart'], $device->fresh()->queuedCommands);
    }

    public function test_agent_acknowledges_command(): void
    {
        $device = $this->createDevice('secret-token');
        $device->commands()->create(['command' => 'restart']);
        $device->commands()->create(['command' => 'doUpdates']);

        $this->withToken('secret-token')->postJson('/api/device/commands/ack', ['command' => 'restart'])
            ->assertOk()
            ->assertExactJson(['commands' => ['doUpdates']]);

        $this->assertSame(['doUpdates'], $device->fresh()->queuedCommands);
    }
}
