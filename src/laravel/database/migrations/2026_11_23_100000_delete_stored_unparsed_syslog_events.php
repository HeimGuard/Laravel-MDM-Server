<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The syslog messages no parser reads were stored as events of the type "syslog" for a short time; they are only kept in the cache for a
     * day now (SyslogUnparsedService), so the rows are deleted. The events the parsers make have their own types.
     */
    public function up(): void
    {
        DB::table('events')->where('type', 'syslog')->where('source_type', 'syslog')->delete();
    }

    /** Raw messages are not stored: nothing to restore. */
    public function down(): void {}
};
