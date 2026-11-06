<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Their history is in value_samples now (create_value_tables). */
    public function up(): void
    {
        Schema::dropIfExists('device_metrics');
        Schema::dropIfExists('ping_results');
    }

    public function down(): void
    {
        Schema::create('device_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->decimal('cpu', 5, 2);
            $table->unsignedBigInteger('memory_used');
            $table->unsignedBigInteger('memory_total');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['device_id', 'created_at']);
        });
        Schema::create('ping_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->boolean('up');
            $table->float('rtt')->nullable();
            $table->foreignId('relay_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['device_id', 'created_at']);
        });
    }
};
