<?php

use App\Models\User;
use App\View\Components\Widgets\DeviceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A default dashboard, shared with everyone, so /dashboard is not empty on a new installation.
     * Users add their own dashboards and widgets in the editor.
     */
    public function up(): void
    {
        if (DB::table('dashboards')->exists()) {
            return;
        }

        // The first system admin owns it; before any user exists, nobody does (the column has no
        // foreign key) and system admins can still edit it.
        $admins = array_filter(config('boilerplate.system_admins', []));
        $owner = User::query()->whereIn('id', $admins)->orderBy('id')->value('id')
            ?? User::query()->orderBy('id')->value('id')
            ?? 0;

        $now = now();
        $dashboardId = DB::table('dashboards')->insertGetId([
            'name' => 'Overview',
            'user_id' => $owner,
            'is_shared' => true,
            'body' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $widgetId = DB::table('dashboard_widgets')->insertGetId([
            'dashboard_id' => $dashboardId,
            'type' => class_basename(DeviceStatus::class),
            'config' => json_encode((new DeviceStatus)->default()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('dashboards')->where('id', $dashboardId)->update([
            'body' => json_encode([
                ['type' => 'row', 'items' => [['id' => $widgetId, 'width' => 4, 'height' => 1]]],
            ]),
        ]);
    }

    public function down(): void
    {
        $dashboard = DB::table('dashboards')->where('name', 'Overview')->where('is_shared', true)->orderBy('id')->first();
        if ($dashboard) {
            DB::table('dashboard_widgets')->where('dashboard_id', $dashboard->id)->delete();
            DB::table('dashboards')->where('id', $dashboard->id)->delete();
        }
    }
};
