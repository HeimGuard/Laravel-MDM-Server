<?php

namespace Tests\Feature;

use App\Livewire\DeviceCommands;
use App\Livewire\DeviceDetail;
use App\Livewire\ShowDevices;
use App\Models\Device;
use App\Models\Enrolment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

class DeviceFlowTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    private array $payload = [
        'machine' => [
            'Hostname' => 'pc1',
            'os' => 'win',
            'uptime' => 3600,
            'RestartRequired' => 'false',
            'Drives' => [
                ['Size' => 100, 'SizeRemaining' => 40, 'DriveType' => 3, 'DriveLetter' => 'C'],
            ],
        ],
    ];

    public function test_user_can_log_in_and_see_devices(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['password' => 'secret']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret'])
            ->assertRedirect('/devices');

        $this->get('/devices')->assertOk();
    }

    public function test_device_enrolment_reporting_and_commands(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ShowDevices::class)->set('addDevice', true);
        $code = Enrolment::firstOrFail()->code;

        // A signing agent (1.7.0+) enrols with its public key, the request signed with that key.
        $response = $this->signedJson('POST', '/api/device/register', ['enrolment_code' => $code, 'public_key' => $this->devicePublicKey()])
            ->assertOk();
        $token = $response->json('token');
        $device = Device::firstOrFail();
        $this->assertSame($device->id, $response->json('device_id'));
        $this->assertSignedResponse($response, $device->id);
        $this->assertSame($this->devicePublicKey(), $device->public_key);

        $this->assertSignedResponse($this->signedJson('POST', '/api/device', $this->payload, $token)
            ->assertOk()
            ->assertJson(['commands' => []]), $device->id);

        $device->refresh();
        $this->assertSame('pc1', $device->name);

        $this->actingAs($user);

        $this->get('/devices?selectedDeviceId='.$device->id)
            ->assertOk()
            ->assertSee('pc1')
            ->assertSee('1 hour');

        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])
            ->call('sendCommandToDevice', 'restart');
        $this->assertSame(['restart'], $device->fresh()->queuedCommands);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->set('friendlyName', 'My PC')
            ->call('saveFriendlyName')
            ->assertSee('My PC');

        $this->signedJson('POST', '/api/device/commands/take', [], $token)
            ->assertExactJson(['commands' => ['restart'], 'tasks' => [['id' => $device->commands()->value('id'), 'command' => 'restart', 'params' => []]]]);
        $this->signedJson('POST', '/api/device', $this->payload, $token)
            ->assertJson(['commands' => []]);
    }

    public function test_agents_that_do_not_sign_only_get_the_agent_update(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(ShowDevices::class)->set('addDevice', true);

        // Agents before 1.7.0 enrol and report without signatures.
        $token = $this->postJson('/api/device/register', ['enrolment_code' => Enrolment::firstOrFail()->code])
            ->assertOk()
            ->json('token');
        $this->withToken($token)->postJson('/api/device', $this->payload)->assertOk();
        $device = Device::firstOrFail();
        $this->assertNull($device->public_key);

        $this->assertFalse($device->queueCommand('restart'));
        $this->assertTrue($device->queueCommand('updateAgent'));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/device', $this->payload)
            ->assertJson(['commands' => ['updateAgent']]);
    }

    public function test_enrolment_codes_are_random_and_long(): void
    {
        $codes = collect(range(1, 50))->map(fn () => Enrolment::generateCode());

        $this->assertTrue($codes->every(fn ($code) => preg_match('/^[A-HJKMNP-Z2-9]{8}$/', $code) === 1));
        $this->assertGreaterThan(45, $codes->unique()->count());
    }

    public function test_guessing_enrolment_codes_is_throttled_and_drops_the_open_ones(): void
    {
        $code = Enrolment::generateCode();
        $enrolment = new Enrolment;
        $enrolment->code = $code;
        $enrolment->expire_at = now()->addMinutes(15);
        $enrolment->save();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/device/register', ['enrolment_code' => 'WRONG'.$i])->assertStatus(422);
        }
        // Per client: the 11th request in a minute is refused, even with the right code.
        $this->postJson('/api/device/register', ['enrolment_code' => $code])->assertStatus(429);
        $this->assertSame(1, Enrolment::count());

        // Many wrong codes (from anywhere) drop the open codes.
        $this->app['cache']->flush();
        for ($i = 0; $i < Enrolment::MAX_FAILURES; $i++) {
            RateLimiter::hit('enrolment-failures', 900);
        }
        $this->postJson('/api/device/register', ['enrolment_code' => 'WRONG'])->assertStatus(422);
        $this->assertSame(0, Enrolment::count());
    }

    public function test_invalid_enrolment_code_is_rejected(): void
    {
        $this->postJson('/api/device/register', ['enrolment_code' => '0000'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'invalid_enrolment_code']);
        $this->assertSame(0, Device::count());
    }

    public function test_device_api_requires_token(): void
    {
        $this->postJson('/api/device', $this->payload)->assertUnauthorized();
    }

    public function test_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'a@b.c'])->assertNotFound();
    }

    public function test_deleting_device_resets_selection(): void
    {
        $this->actingAs(User::factory()->create());
        $device = new Device;
        $device->token = hash('sha256', 'token');
        $device->save();

        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])
            ->call('deleteDevice')
            ->assertDispatched('device-deleted');

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);

        Livewire::test(ShowDevices::class, ['selectedDeviceId' => $device->id])
            ->dispatch('device-deleted')
            ->assertSet('selectedDeviceId', null);
    }

    public function test_first_device_is_opened_without_selection(): void
    {
        $this->actingAs(User::factory()->create());
        $devices = collect(['a', 'b'])->map(function ($token) {
            $device = new Device;
            $device->token = hash('sha256', $token);
            $device->save();

            return $device;
        });

        Livewire::test(ShowDevices::class)
            ->assertSet('selectedDeviceId', $devices[0]->id);
        Livewire::test(ShowDevices::class, ['selectedDeviceId' => $devices[1]->id])
            ->assertSet('selectedDeviceId', $devices[1]->id);
        // A link to a deleted device opens the first one instead of an empty page.
        Livewire::test(ShowDevices::class, ['selectedDeviceId' => 999])
            ->assertSet('selectedDeviceId', $devices[0]->id);

        $devices[0]->delete();
        Livewire::test(ShowDevices::class, ['selectedDeviceId' => $devices[0]->id])
            ->dispatch('device-deleted')
            ->assertSet('selectedDeviceId', $devices[1]->id);
    }
}
