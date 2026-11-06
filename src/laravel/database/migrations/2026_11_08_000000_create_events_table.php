<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEVICE = 'App\\Models\\Device';

    /**
     * What happened (App\Models\SecurityEvent): of any model (eventable), its type, what it came from (source_type: linux.auth,
     * syslog, ...), the message, how often, when, and in data what not every event has (the user, the address it came from (source_origin), the
     * parser that made it). The events of security_events are copied.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('eventable_type');
            $table->unsignedBigInteger('eventable_id');
            $table->string('type', 32);
            $table->string('source_type', 32)->default('');
            $table->string('message', 1000)->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('occurred_at');
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['eventable_type', 'eventable_id', 'occurred_at']);
            $table->index('occurred_at');
            $table->index('type');
        });

        if (Schema::hasTable('security_events')) {
            DB::table('security_events')->orderBy('id')->chunkById(500, function ($rows) {
                DB::table('events')->insert($rows->map(function ($row) {
                    // Where an old event came from is not known.
                    $data = array_filter(['user' => $row->user, 'source_origin' => $row->source], fn ($value) => $value !== null);

                    return [
                        'eventable_type' => self::DEVICE, 'eventable_id' => $row->device_id, 'type' => $row->type, 'source_type' => '',
                        'message' => $row->message, 'count' => $row->count, 'occurred_at' => $row->occurred_at,
                        'data' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
                    ];
                })->all());
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
