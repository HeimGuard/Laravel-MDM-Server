<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\View\Components\Widgets\DeviceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function createDevice(bool $online): Device
    {
        $device = new Device;
        $device->token = hash('sha256', uniqid('', true));
        $device->save();
        if ($online) {
            Device::recordHeartbeat($device->id);
        } else {
            $device->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        }

        return $device->fresh();
    }

    public function test_migration_creates_a_shared_default_dashboard(): void
    {
        $dashboard = DB::table('dashboards')->first();

        $this->assertSame('Overview', $dashboard->name);
        $this->assertEquals(1, $dashboard->is_shared);
        [$widget, $alerts] = DB::table('dashboard_widgets')->where('dashboard_id', $dashboard->id)->orderBy('id')->get()->all();
        $this->assertSame('DeviceStatus', $widget->type);
        $this->assertSame('SmartAlerts', $alerts->type);
        $this->assertSame([['type' => 'row', 'items' => [
            ['id' => $widget->id, 'width' => 4, 'height' => 1],
            ['id' => $alerts->id, 'width' => 8, 'height' => 2],
        ]]], json_decode($dashboard->body, true));
    }

    public function test_dashboard_page_renders_for_any_user(): void
    {
        // Not the owner of the shared default dashboard and not a system admin: may view it.
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertOk()->assertSee('Overview');
        $this->get('/dashboard/editor')->assertOk();
    }

    public function test_dashboard_requires_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_device_status_widget_counts_online_and_offline_devices(): void
    {
        $this->createDevice(online: true);
        $this->createDevice(online: true);
        $offline = $this->createDevice(online: false);

        $html = Blade::render('<x-widgets.DeviceStatus :config="$config" />', ['config' => ['list' => '5']]);

        $this->assertMatchesRegularExpression('/>\s*2\s*<\/div>\s*<small[^>]*>Online/', $html);
        $this->assertMatchesRegularExpression('/>\s*1\s*<\/div>\s*<small[^>]*>Offline/', $html);
        $this->assertStringContainsString('selectedDeviceId='.$offline->id, $html);
        $this->assertStringContainsString('x-on:mdm-devices-changed.window', $html);
    }

    public function test_widget_config_keeps_only_known_keys(): void
    {
        $widget = new DeviceStatus(['name' => 'Fleet', 'bogus' => 'x']);

        $this->assertSame('Fleet', $widget->config['name']);
        $this->assertArrayNotHasKey('bogus', $widget->config);
    }

    public function test_owners_and_system_admins_may_edit_dashboards(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $other = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);
        $dashboardClass = config('boilerplate-dashboard.models.dashboard');
        $shared = $dashboardClass::query()->first();
        $private = new $dashboardClass(['name' => 'Mine', 'is_shared' => false, 'body' => []]);
        $private->user_id = $owner->id;
        $private->save();

        $this->assertTrue($owner->fresh()->can('update', $private));
        $this->assertTrue($admin->fresh()->can('update', $private));
        $this->assertTrue($admin->fresh()->can('update', $shared));
        $this->assertFalse($other->fresh()->can('update', $shared));
        $this->assertFalse($other->fresh()->can('view', $private));
        $this->assertTrue($other->fresh()->can('view', $shared));

        $this->actingAs($admin->fresh())->get('/dashboard/editor?dashboard='.$shared->id)->assertOk();
        $this->actingAs($other->fresh())->get('/dashboard/editor?dashboard='.$shared->id)->assertForbidden();
    }
}
