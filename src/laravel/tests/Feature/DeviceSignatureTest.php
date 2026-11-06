<?php

namespace Tests\Feature;

use App\Events\DeviceCommandIssued;
use App\Livewire\DeviceAlerts;
use App\Livewire\DeviceDetail;
use App\Models\User;
use Livewire\Livewire;
use App\Listeners\RecordDeviceHeartbeat;
use App\Models\Device;
use App\Support\Signing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

class DeviceSignatureTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    private array $report = ['machine' => ['Hostname' => 'srv1', 'Drives' => []]];

    private function createDevice(bool $withKey = true): Device
    {
        $device = new Device();
        $device->token = hash('sha256', 'secret-token');
        $device->save();

        return $withKey ? $this->registerDeviceKey($device) : $device;
    }

    public function test_signed_request_gets_a_signed_response(): void
    {
        $device = $this->createDevice();

        $this->assertSignedResponse($this->signedJson('POST', '/api/device', $this->report, 'secret-token')->assertOk(), $device->id);
        $this->assertSame('srv1', $device->fresh()->name);
    }

    public function test_device_with_a_key_must_sign(): void
    {
        $this->createDevice();

        $this->withToken('secret-token')->postJson('/api/device', $this->report)
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'signature_required']);
        $this->assertNull(Device::first()->name);
    }

    public function test_tampered_replayed_and_stale_requests_are_rejected(): void
    {
        $device = $this->createDevice();

        $this->signedJson('POST', '/api/device', $this->report, 'secret-token', ['signature' => base64_encode(random_bytes(256))])
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_signature']);

        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->signedJson('POST', '/api/device', $this->report, 'secret-token', ['key' => $otherKey])
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_signature']);

        $this->signedJson('POST', '/api/device', $this->report, 'secret-token', ['timestamp' => time() - 3600])
            ->assertUnauthorized()->assertExactJson(['error' => 'clock_skew']);

        $nonce = bin2hex(random_bytes(16));
        $this->signedJson('POST', '/api/device', $this->report, 'secret-token', ['nonce' => $nonce])->assertOk();
        $this->assertSignedResponse(
            $this->signedJson('POST', '/api/device', $this->report, 'secret-token', ['nonce' => $nonce])
                ->assertUnauthorized()->assertExactJson(['error' => 'replayed']),
            $device->id,
        );
    }

    public function test_rejections_and_unknown_tokens_are_signed_too(): void
    {
        $this->createDevice();

        $response = $this->signedJson('POST', '/api/device', $this->report, 'wrong-token')->assertUnauthorized();
        $this->assertSignedResponse($response, 0);
    }

    public function test_updated_agent_registers_its_key_once(): void
    {
        $device = $this->createDevice(withKey: false);

        // An agent that signs but whose key the server does not know yet.
        $this->signedJson('POST', '/api/device', $this->report, 'secret-token')
            ->assertUnauthorized()->assertExactJson(['error' => 'key_not_registered']);

        $this->assertSignedResponse(
            $this->signedJson('POST', '/api/device/key', ['public_key' => $this->devicePublicKey()], 'secret-token')
                ->assertOk()->assertExactJson(['device_id' => $device->id]),
            $device->id,
        );
        $this->assertSame($this->devicePublicKey(), $device->fresh()->public_key);

        // Nobody replaces it with the device token alone, not even the device itself.
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->signedJson('POST', '/api/device/key', ['public_key' => $this->devicePublicKey($otherKey)], 'secret-token', ['key' => $otherKey])
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_signature']);
        $this->signedJson('POST', '/api/device/key', ['public_key' => $this->devicePublicKey()], 'secret-token')
            ->assertStatus(409);
        $this->assertSame($this->devicePublicKey(), $device->fresh()->public_key);
    }

    public function test_key_registration_needs_proof_of_the_private_key(): void
    {
        $device = $this->createDevice(withKey: false);
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        // The body names one key, the request is signed with another.
        $this->signedJson('POST', '/api/device/key', ['public_key' => $this->devicePublicKey($otherKey)], 'secret-token')
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_signature']);
        $this->signedJson('POST', '/api/device/key', ['public_key' => ['n' => 'AQAB', 'e' => 'AQAB']], 'secret-token')
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_public_key']);
        $this->assertNull($device->fresh()->public_key);
    }

    public function test_websocket_channel_authorization_is_signed(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'app-key', 'secret' => 'app-secret', 'app_id' => 'app-id',
            'options' => ['host' => 'mdm.test', 'port' => 443, 'scheme' => 'https', 'useTLS' => true],
        ]]);
        require base_path('routes/channels.php');
        $device = $this->createDevice();
        $body = ['socket_id' => '1.2', 'channel_name' => 'private-device.'.$device->id];

        $this->withToken('secret-token')->postJson('/api/broadcasting/auth', $body)
            ->assertUnauthorized()->assertExactJson(['error' => 'signature_required']);
        $this->assertSignedResponse($this->signedJson('POST', '/api/broadcasting/auth', $body, 'secret-token')->assertOk(), $device->id);
    }

    public function test_signed_agents_can_be_required(): void
    {
        config(['mdm.require_signed_agents' => true]);
        $this->createDevice(withKey: false);

        $this->withToken('secret-token')->postJson('/api/device', $this->report)
            ->assertUnauthorized()->assertExactJson(['error' => 'signature_required']);
    }

    public function test_command_event_is_signed_for_the_device(): void
    {
        $device = $this->createDevice();

        $data = (new DeviceCommandIssued($device, 'restart'))->broadcastWith();

        $this->assertSame('restart', $data['command']);
        $this->assertTrue(Signing::verify(Signing::publicKey(), 'MDM1-WS', $data['p'], $data['sig']));
        $payload = json_decode($data['p'], true);
        $this->assertSame($device->id, $payload['device_id']);
        $this->assertSame('restart', $payload['command']);
        $this->assertEqualsWithDelta(time(), $payload['ts'], 5);
    }

    private function signedHeartbeat(Device $device, array $overrides = []): array
    {
        $payload = json_encode($overrides + [
            'device_id' => $device->id,
            'ts' => time(),
            'nonce' => bin2hex(random_bytes(16)),
            'metrics' => ['cpu' => 12.5, 'memory_used' => 4, 'memory_total' => 8],
            'state' => ['restart_required' => true],
        ]);
        openssl_sign("MDM1-HB\n".$payload, $signature, $this->deviceKey(), OPENSSL_ALGO_SHA256);

        return ['p' => $payload, 'sig' => base64_encode($signature)];
    }

    public function test_websocket_heartbeat_is_verified(): void
    {
        $device = $this->createDevice();
        $heartbeat = $this->signedHeartbeat($device);

        $data = RecordDeviceHeartbeat::verifiedData($device->id, $heartbeat);
        $this->assertSame(12.5, $data['cpu']);
        $this->assertSame(['restart_required' => true], $data['state']);

        // Replayed, tampered, for another device, stale or unsigned.
        $this->assertNull(RecordDeviceHeartbeat::verifiedData($device->id, $heartbeat));
        $tampered = $this->signedHeartbeat($device);
        $tampered['p'] = str_replace('12.5', '99', $tampered['p']);
        $this->assertNull(RecordDeviceHeartbeat::verifiedData($device->id, $tampered));
        $this->assertNull(RecordDeviceHeartbeat::verifiedData($device->id, $this->signedHeartbeat($device, ['device_id' => $device->id + 1])));
        $this->assertNull(RecordDeviceHeartbeat::verifiedData($device->id, $this->signedHeartbeat($device, ['ts' => time() - 3600])));
        $this->assertNull(RecordDeviceHeartbeat::verifiedData($device->id, ['cpu' => 1, 'memory_used' => 1, 'memory_total' => 2]));
    }

    public function test_unsigned_heartbeat_only_from_agents_without_a_key(): void
    {
        $device = $this->createDevice(withKey: false);
        $metrics = ['cpu' => 1, 'memory_used' => 1, 'memory_total' => 2];

        $this->assertSame($metrics, RecordDeviceHeartbeat::verifiedData($device->id, $metrics));

        config(['mdm.require_signed_agents' => true]);
        $this->assertNull(RecordDeviceHeartbeat::verifiedData($device->id, $metrics));
    }

    public function test_public_keys_are_validated(): void
    {
        $this->assertNull(Signing::normalizePublicKey(null));
        $this->assertNull(Signing::normalizePublicKey(['n' => 'not base64!', 'e' => 'AQAB']));
        // 1024 bits is too weak.
        $weak = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]));
        $this->assertNull(Signing::normalizePublicKey(['n' => base64_encode($weak['rsa']['n']), 'e' => base64_encode($weak['rsa']['e'])]));
        $this->assertSame($this->devicePublicKey(), Signing::normalizePublicKey($this->devicePublicKey()));
    }

    public function test_detail_shows_signing_and_admins_reset_the_key(): void
    {
        $device = $this->createDevice();
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);

        $this->actingAs(User::factory()->create());
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->set('tab', 'agent')
            ->assertSee('Signed')
            ->assertDontSee('Reset device key')
            ->call('resetDeviceKey')
            ->assertForbidden();
        $this->assertNotNull($device->fresh()->public_key);

        $this->actingAs($admin);
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->set('tab', 'agent')
            ->call('resetDeviceKey')
            ->assertSee('Unsigned agent');
        $this->assertNull($device->fresh()->public_key);
    }
}
