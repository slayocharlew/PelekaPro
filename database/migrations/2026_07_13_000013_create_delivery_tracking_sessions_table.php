<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_tracking_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['active', 'stopped'])->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->enum('stop_reason', ['delivered', 'failed', 'cancelled', 'manual_stop'])->nullable();
            $table->timestamps();

            $table->index(['delivery_id', 'status']);
            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_tracking_sessions');
    }
};
