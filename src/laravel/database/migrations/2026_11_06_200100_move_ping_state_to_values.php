<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEVICE = 'App\\Models\\Device';

    /**
     * The last ping of a device (the response time, the agent that pinged) is read from its measured values (ping.rtt,
     * ping.relay_id) like the rest of the pings, not kept in two columns of devices.
     */
    public function up(): void
    {
        // A relay that no ping history has (the columns are the only place): its current value.
        DB::table('devices')->whereNotNull('ping_relay_id')->orderBy('id')->each(function ($device) {
            $exists = DB::table('value_definitions')->where('valuable_type', self::DEVICE)->where('valuable_id', $device->id)->where('key', 'ping.relay_id')->where('name', '')->exists();
            if (! $exists) {
                DB::table('value_definitions')->insert([
                    'valuable_type' => self::DEVICE, 'valuable_id' => $device->id, 'key' => 'ping.relay_id', 'name' => '', 'unit' => null,
                    'last_value' => (float) $device->ping_relay_id, 'last_at' => $device->last_seen_at ?? now(),
                ]);
            }
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['ping_rtt', 'ping_relay_id']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->float('ping_rtt')->nullable();
            $table->unsignedBigInteger('ping_relay_id')->nullable();
        });
        $current = fn (int $deviceId, string $key) => DB::table('value_definitions')->where('valuable_type', self::DEVICE)->where('valuable_id', $deviceId)->where('key', $key)->where('name', '')->first(['last_value', 'last_at']);
        foreach (DB::table('value_definitions')->where('valuable_type', self::DEVICE)->whereIn('key', ['ping.up', 'ping.rtt', 'ping.relay_id'])->distinct()->pluck('valuable_id') as $deviceId) {
            $up = $current($deviceId, 'ping.up');
            $rtt = $current($deviceId, 'ping.rtt');
            $relay = $current($deviceId, 'ping.relay_id');
            DB::table('devices')->where('id', $deviceId)->update([
                'ping_rtt' => $up && (float) $up->last_value >= 0.5 && $rtt ? $rtt->last_value : null,
                'ping_relay_id' => $relay?->last_value === null ? null : (int) $relay->last_value,
            ]);
        }
    }
};
