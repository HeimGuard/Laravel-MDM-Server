<?php

use App\View\Components\Widgets\SmartAlerts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The smart alerts widget next to the device status on the default dashboard (when it still exists). */
    public function up(): void
    {
        $dashboard = DB::table('dashboards')->where('name', 'Overview')->where('is_shared', true)->orderBy('id')->first();
        if ($dashboard === null || DB::table('dashboard_widgets')->where('dashboard_id', $dashboard->id)->where('type', 'SmartAlerts')->exists()) {
            return;
        }

        $now = now();
        $widgetId = DB::table('dashboard_widgets')->insertGetId([
            'dashboard_id' => $dashboard->id,
            'type' => class_basename(SmartAlerts::class),
            'config' => json_encode((new SmartAlerts)->default()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $body = json_decode((string) $dashboard->body, true) ?: [];
        if (isset($body[0]['items']) && is_array($body[0]['items'])) {
            $body[0]['items'][] = ['id' => $widgetId, 'width' => 8, 'height' => 2];
        } else {
            $body[] = ['type' => 'row', 'items' => [['id' => $widgetId, 'width' => 8, 'height' => 2]]];
        }
        DB::table('dashboards')->where('id', $dashboard->id)->update(['body' => json_encode($body)]);
    }

    public function down(): void
    {
        DB::table('dashboard_widgets')->where('type', 'SmartAlerts')->delete();
    }
};
