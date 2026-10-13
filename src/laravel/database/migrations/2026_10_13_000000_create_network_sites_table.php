<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The public addresses the devices were seen behind: the network map keeps them (faint) when nobody is there. */
    public function up(): void
    {
        Schema::create('network_sites', function (Blueprint $table) {
            $table->id();
            $table->string('address', 45)->unique();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_sites');
    }
};
