<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEVICE = 'App\\Models\\Device';

    /**
     * The inventory of a model (software, processes, ports, services, ...): a definition is one thing (model, key = the
     * kind, name = which one) with its current state, the samples are its history, only the changes. The items of the
     * security inventory (security_inventory_items) are copied, a sample each as the first point of their history.
     */
    public function up(): void
    {
        Schema::create('inventory_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('inventorable_type');
            $table->unsignedBigInteger('inventorable_id');
            $table->string('key', 32);
            $table->string('name', 128)->default('');
            // The place of a thing in a list the order of which matters (the report of an agent); null: no order.
            $table->unsignedInteger('position')->nullable();
            // null: the thing is gone (a sample with null data says when).
            $table->longText('last_data')->nullable();
            $table->string('last_hash', 32)->nullable();
            $table->timestamp('last_at')->nullable();

            $table->unique(['inventorable_type', 'inventorable_id', 'key', 'name']);
        });

        Schema::create('inventory_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('definition_id')->constrained('inventory_definitions')->cascadeOnDelete();
            $table->longText('data')->nullable();
            $table->string('hash', 32)->nullable();
            $table->timestamp('recorded_at');

            $table->index(['definition_id', 'recorded_at']);
        });

        if (Schema::hasTable('security_inventory_items')) {
            $key = DB::connection()->getQueryGrammar()->wrap('key');
            DB::insert("insert into inventory_definitions (inventorable_type, inventorable_id, $key, name, last_data, last_hash, last_at)
                select ?, i.device_id, i.source, i.item_key, i.data, i.item_hash, coalesce(v.collected_at, current_timestamp)
                from security_inventory_items i left join security_inventories v on v.device_id = i.device_id order by i.id", [self::DEVICE]);
            DB::insert('insert into inventory_samples (definition_id, data, hash, recorded_at) select id, last_data, last_hash, last_at from inventory_definitions order by id');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_samples');
        Schema::dropIfExists('inventory_definitions');
    }
};
