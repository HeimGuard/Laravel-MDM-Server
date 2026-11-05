<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When the portal last received syslog from the device (the icon of a device that sends it). */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('syslog_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('syslog_at');
        });
    }
};
