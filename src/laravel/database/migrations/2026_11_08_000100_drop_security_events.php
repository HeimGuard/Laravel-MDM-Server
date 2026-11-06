<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The events are in events now (create_events_table). */
    public function up(): void
    {
        Schema::dropIfExists('security_events');
    }

    public function down(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedInteger('count')->default(1);
            $table->string('user', 256)->nullable();
            $table->string('source', 256)->nullable();
            $table->string('message', 1000)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['device_id', 'occurred_at']);
            $table->index('occurred_at');
        });
        DB::table('events')->where('eventable_type', 'App\\Models\\Device')->orderBy('id')->chunkById(500, function ($rows) {
            // A device that is gone has no events to restore.
            $devices = DB::table('devices')->whereIn('id', $rows->pluck('eventable_id'))->pluck('id')->all();
            DB::table('security_events')->insert($rows->filter(fn ($row) => in_array($row->eventable_id, $devices))->map(function ($row) {
                $data = json_decode((string) $row->data, true) ?: [];

                return [
                    'device_id' => $row->eventable_id, 'type' => $row->type, 'count' => $row->count, 'user' => $data['user'] ?? null,
                    'source' => $data['source_origin'] ?? null, 'message' => $row->message, 'occurred_at' => $row->occurred_at,
                    'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
                ];
            })->values()->all());
        });
    }
};
