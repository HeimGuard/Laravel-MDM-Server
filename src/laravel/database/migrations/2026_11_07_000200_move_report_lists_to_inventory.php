<?php

use App\Models\Device;
use App\Models\InventoryDefinition;
use App\Services\ReportInventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The kinds of inventory the lists of the report are stored as. */
    private const KINDS = ['service', 'docker', 'container', 'os_update', 'package_update', 'module_update', 'disk_health'];

    /**
     * The lists of the report of an agent (services, containers, updates, disk health) are the inventory of the device now; the
     * ARP table (neighbours) is not kept. What stays in devices.data is the rest of the report.
     */
    public function up(): void
    {
        DB::table('devices')->whereNotNull('data')->orderBy('id')->chunkById(50, function ($rows) {
            foreach ($rows as $row) {
                $report = json_decode((string) $row->data, true);
                if (! is_array($report)) {
                    continue;
                }
                [$core, $sections] = ReportInventoryService::split($report);
                $device = Device::query()->without(['settings', 'pingValues'])->find($row->id);
                if ($device !== null && $sections !== []) {
                    ReportInventoryService::store($device, $sections);
                }
                if ($core !== $report) {
                    DB::table('devices')->where('id', $row->id)->update(['data' => json_encode($core)]);
                }
            }
        });
    }

    public function down(): void
    {
        $devices = InventoryDefinition::query()->where('inventorable_type', (new Device)->getMorphClass())->whereIn('key', self::KINDS)->distinct()->pluck('inventorable_id');
        foreach ($devices as $id) {
            $row = DB::table('devices')->where('id', $id)->first(['data']);
            $report = json_decode((string) ($row->data ?? ''), true);
            $device = Device::query()->without(['settings', 'pingValues'])->find($id);
            if (! is_array($report) || $device === null) {
                continue;
            }
            foreach (ReportInventoryService::readMany([$device])[$id] ?? [] as $section => $value) {
                if ($value !== null) {
                    $report[$section] = json_decode(json_encode($value), true);
                }
            }
            DB::table('devices')->where('id', $id)->update(['data' => json_encode($report)]);
        }
        InventoryDefinition::query()->whereIn('key', self::KINDS)->delete();
    }
};
