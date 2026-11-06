<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The items are inventory definitions now (create_inventory_tables). */
    public function up(): void
    {
        Schema::dropIfExists('security_inventory_items');
    }

    public function down(): void
    {
        Schema::create('security_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('item_key', 32);
            $table->string('item_hash', 32);
            $table->text('data');
            $table->unique(['device_id', 'source', 'item_key']);
        });
        $key = DB::connection()->getQueryGrammar()->wrap('key');
        // What is there now (the history is not copied back).
        DB::insert("insert into security_inventory_items (device_id, source, item_key, item_hash, data)
            select d.inventorable_id, d.$key, d.name, d.last_hash, d.last_data from inventory_definitions d
            where d.inventorable_type = ? and d.last_data is not null order by d.id", ['App\\Models\\Device']);
    }
};
