<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEVICE = 'App\\Models\\Device';

    /** column => [settings key, type (SettingDataType: 0 text, 1 number), kind of device] */
    private const COLUMNS = [
        'ping_address' => ['lan.address', '0', 'ping'],
        'ping_prefix' => ['lan.prefix', '1', 'ping'],
        'ping_mac' => ['lan.mac', '0', 'ping'],
        'ping_via_id' => ['lan.via_device_id', '1', 'ping'],
        'wake_mac' => ['lan.mac', '0', 'agent'],
        'wake_address' => ['lan.address', '0', 'agent'],
        'wake_prefix' => ['lan.prefix', '1', 'agent'],
    ];

    /**
     * Where a ping-only device is pinged (address, prefix, MAC, the agent to ping through) and the Wake-on-LAN target of an
     * agent are settings of the device (the settings table, keys lan.*), not columns of devices.
     */
    public function up(): void
    {
        foreach (self::COLUMNS as $column => [$index, $type, $kind]) {
            // An empty string is only compared with a text column (PostgreSQL does not compare it with a number).
            DB::table('devices')->whereNotNull($column)->when($type === '0', fn ($query) => $query->where($column, '!=', ''))->where('kind', $kind)->orderBy('id')->chunkById(200, function ($devices) use ($column, $index, $type) {
                DB::table('settings')->insertOrIgnore($devices->map(fn ($device) => [
                    'settable_type' => self::DEVICE, 'settable_id' => $device->id, 'index' => $index, 'type' => $type, 'value' => (string) $device->$column,
                ])->all());
            });
        }
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::COLUMNS));
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('ping_address', 45)->nullable();
            $table->unsignedTinyInteger('ping_prefix')->nullable();
            $table->string('ping_mac', 17)->nullable();
            $table->unsignedBigInteger('ping_via_id')->nullable();
            $table->string('wake_mac', 17)->nullable();
            $table->string('wake_address', 15)->nullable();
            $table->unsignedTinyInteger('wake_prefix')->nullable();
        });
        foreach (self::COLUMNS as $column => [$index, $type, $kind]) {
            foreach (DB::table('settings')->where('settable_type', self::DEVICE)->where('index', $index)->get(['settable_id', 'value']) as $setting) {
                DB::table('devices')->where('id', $setting->settable_id)->where('kind', $kind)->update([$column => $setting->value]);
            }
        }
        DB::table('settings')->where('settable_type', self::DEVICE)->whereIn('index', array_unique(array_column(self::COLUMNS, 0)))->delete();
    }
};
