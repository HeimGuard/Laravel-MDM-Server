<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The script the AI wrote for the findings of a rule: asking again updates it instead of writing another one. */
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->foreignId('security_rule_id')->nullable()->after('manual_remediation')->constrained('security_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('security_rule_id');
        });
    }
};
