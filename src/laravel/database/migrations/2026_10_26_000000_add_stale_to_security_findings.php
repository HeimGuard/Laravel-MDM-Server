<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A finding of a device that has not reported for days is stale: it may no longer be true, nobody can tell. */
    public function up(): void
    {
        Schema::table('security_findings', function (Blueprint $table) {
            $table->timestamp('stale_at')->nullable()->after('acknowledged_by');
        });
    }

    public function down(): void
    {
        Schema::table('security_findings', function (Blueprint $table) {
            $table->dropColumn('stale_at');
        });
    }
};
