<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEVICE = 'App\\Models\\Device';

    /**
     * Measured values (CPU, memory, ping, later disks and more) of any model: a definition says what is measured (one
     * row per model, key and name, e.g. device 9, disk.used_pct, C:), the samples are its history. The CPU, memory
     * and ping history of the devices is copied from device_metrics and ping_results.
     */
    public function up(): void
    {
        Schema::create('value_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('valuable_type');
            $table->unsignedBigInteger('valuable_id');
            $table->string('key', 64);
            // Of which one (a disk, an interface); empty for what there is one of (cpu). Not null: a unique index does not see nulls as equal.
            $table->string('name', 128)->default('');
            $table->string('unit', 16)->nullable();
            // The newest sample, so the current value costs no query of the history.
            $table->double('last_value')->nullable();
            $table->timestamp('last_at')->nullable();

            $table->unique(['valuable_type', 'valuable_id', 'key', 'name']);
        });

        Schema::create('value_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('definition_id')->constrained('value_definitions')->cascadeOnDelete();
            $table->double('value');
            $table->timestamp('recorded_at');

            $table->index(['definition_id', 'recorded_at']);
        });

        $define = function (int $deviceId, string $key, ?string $unit): int {
            return DB::table('value_definitions')->insertGetId(['valuable_type' => self::DEVICE, 'valuable_id' => $deviceId, 'key' => $key, 'name' => '', 'unit' => $unit]);
        };
        $last = function (int $definition, string $table, int $deviceId, string $column, string $where = '') {
            $row = DB::table($table)->where('device_id', $deviceId)->when($where !== '', fn ($query) => $query->whereRaw($where))->orderByDesc('created_at')->first([$column, 'created_at']);
            if ($row !== null) {
                DB::table('value_definitions')->where('id', $definition)->update(['last_value' => (float) $row->$column, 'last_at' => $row->created_at]);
            }
        };

        if (Schema::hasTable('device_metrics')) {
            foreach (DB::table('device_metrics')->distinct()->pluck('device_id') as $deviceId) {
                foreach (['cpu' => ['cpu', '%'], 'memory.used' => ['memory_used', 'bytes'], 'memory.total' => ['memory_total', 'bytes']] as $key => [$column, $unit]) {
                    $definition = $define($deviceId, $key, $unit);
                    // $column is one of the three above.
                    DB::insert("insert into value_samples (definition_id, value, recorded_at) select ?, $column, created_at from device_metrics where device_id = ?", [$definition, $deviceId]);
                    $last($definition, 'device_metrics', $deviceId, $column);
                }
            }
        }
        if (Schema::hasTable('ping_results')) {
            foreach (DB::table('ping_results')->distinct()->pluck('device_id') as $deviceId) {
                $up = $define($deviceId, 'ping.up', null);
                DB::insert('insert into value_samples (definition_id, value, recorded_at) select ?, case when up then 1 else 0 end, created_at from ping_results where device_id = ?', [$up, $deviceId]);
                $newest = DB::table('ping_results')->where('device_id', $deviceId)->orderByDesc('created_at')->first(['up', 'created_at']);
                DB::table('value_definitions')->where('id', $up)->update(['last_value' => $newest->up ? 1 : 0, 'last_at' => $newest->created_at]);

                // The response time only of the pings that were answered.
                if (DB::table('ping_results')->where('device_id', $deviceId)->whereNotNull('rtt')->exists()) {
                    $rtt = $define($deviceId, 'ping.rtt', 'ms');
                    DB::insert('insert into value_samples (definition_id, value, recorded_at) select ?, rtt, created_at from ping_results where device_id = ? and rtt is not null', [$rtt, $deviceId]);
                    $last($rtt, 'ping_results', $deviceId, 'rtt', 'rtt is not null');
                }
                // The agent that made each ping.
                if (DB::table('ping_results')->where('device_id', $deviceId)->whereNotNull('relay_id')->exists()) {
                    $relay = $define($deviceId, 'ping.relay_id', null);
                    DB::insert('insert into value_samples (definition_id, value, recorded_at) select ?, relay_id, created_at from ping_results where device_id = ? and relay_id is not null', [$relay, $deviceId]);
                    $last($relay, 'ping_results', $deviceId, 'relay_id', 'relay_id is not null');
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('value_samples');
        Schema::dropIfExists('value_definitions');
    }
};
