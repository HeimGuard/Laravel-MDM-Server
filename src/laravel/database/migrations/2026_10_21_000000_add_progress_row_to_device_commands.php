<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            // An update of many packages (doUpdates): the row being installed and the finished ones,
            // as targets of the update rows or "group:<provider>" (agents 1.22.0+).
            $table->string('current', 300)->nullable();
            $table->json('done')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->dropColumn(['current', 'done']);
        });
    }
};
