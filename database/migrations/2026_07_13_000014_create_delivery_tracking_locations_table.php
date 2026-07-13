<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_tracking_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracking_session_id')->nullable()->constrained('delivery_tracking_sessions')->nullOnDelete();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('speed', 8, 2)->nullable();
            $table->decimal('heading', 5, 2)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->integer('battery_level')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['delivery_id', 'recorded_at']);
            $table->index(['driver_id', 'recorded_at']);
            $table->index(['tracking_session_id', 'recorded_at'], 'tracking_locations_session_recorded_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_tracking_locations');
    }
};
