<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\LivewireActions;
use Tests\TestCase;

/**
 * The actions only system admins may call (marked admin in tests/Support/LivewireActions.php) answer a
 * normal user with 403. Here the ones that can be called with simple fixtures; the others (the components that
 * check the Gate when they are mounted, the ones that need a script run) are covered by the check of
 * LivewireActionsClassifiedTest that every admin action calls the Gate.
 */
class LivewireAdminActionsForbiddenTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function adminActions(): array
    {
        $cases = [];
        foreach (LivewireActions::forbiddenCalls() as $component => $actions) {
            foreach (array_keys($actions) as $action) {
                $cases["$component::$action"] = [$component, $action];
            }
        }

        return $cases;
    }

    private function device(): Device
    {
        $device = new Device();
        $device->token = hash('sha256', 'secret-token');
        $device->name = 'srv-secret-token';
        $device->data = json_encode(['machine' => ['Hostname' => 'srv-secret-token', 'Platform' => 'linux', 'Drives' => []]]);
        $device->save();
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    public function test_the_calls_are_all_admin_actions(): void
    {
        // The list of calls below only names actions the map marks admin.
        $map = LivewireActions::map();
        foreach (LivewireActions::forbiddenCalls() as $component => $actions) {
            foreach (array_keys($actions) as $action) {
                $this->assertSame(LivewireActions::ADMIN, $map[$component][$action] ?? null, "$component::$action");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminActions')]
    public function test_a_normal_user_cannot_call_an_admin_action(string $component, string $action): void
    {
        $device = $this->device();
        // APP_SYSTEM_ADMINS of the local .env would make the first users admins; the first user is thrown away.
        config(['boilerplate.system_admins' => []]);
        User::factory()->create();
        $this->actingAs(User::factory()->create());

        [$params, $arguments] = LivewireActions::forbiddenCalls()[$component][$action];

        Livewire::test($component, LivewireActions::resolve($params, $device->id))
            ->call($action, ...$arguments)
            ->assertForbidden();
    }
}
