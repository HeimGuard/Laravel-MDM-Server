<?php

use App\Models\Device;
use App\Models\InventoryDefinition;
use App\Services\ReportInventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The states of services and containers an agent sends with its heartbeat are inventory now (a change is a sample), not part
     * of devices.live_state. One that is older than the last report is not applied (the report is newer, as the accessor decided).
     */
    public function up(): void
    {
        DB::table('devices')->whereNotNull('live_state')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $state = json_decode((string) $row->live_state, true);
                if (! is_array($state) || ! (isset($state['services']) || isset($state['containers']))) {
                    continue;
                }
                $current = $row->live_state_at !== null && ($row->updated_at === null || $row->live_state_at >= $row->updated_at);
                if ($current) {
                    ReportInventoryService::applyLiveStates($row->id, $state);
                }
                $rest = array_diff_key($state, ['services' => 1, 'containers' => 1]);
                DB::table('devices')->where('id', $row->id)->update(['live_state' => $rest === [] ? null : json_encode($rest)]);
            }
        });
    }

    public function down(): void
    {
        $type = (new Device)->getMorphClass();
        $ids = InventoryDefinition::query()->where('inventorable_type', $type)->whereIn('key', ['service', 'container'])->whereNotNull('last_data')->distinct()->pluck('inventorable_id');
        foreach ($ids as $id) {
            $row = DB::table('devices')->where('id', $id)->first(['live_state', 'live_state_at']);
            if ($row === null || $row->live_state_at === null) {
                continue;
            }
            $state = json_decode((string) ($row->live_state ?? ''), true) ?: [];
            foreach (['services' => 'service', 'containers' => 'container'] as $list => $kind) {
                $things = [];
                foreach (\App\Services\InventoryService::snapshot($type, $id, $kind) as $name => $thing) {
                    $stateOf = $thing['data']['State'] ?? null;
                    $things[$name] = ($stateOf === 'running' && ($thing['data']['Health'] ?? null) === 'unhealthy') ? 'unhealthy' : $stateOf;
                }
                if ($things !== []) {
                    $state[$list] = $things;
                }
            }
            DB::table('devices')->where('id', $id)->update(['live_state' => json_encode($state)]);
        }
    }
};
