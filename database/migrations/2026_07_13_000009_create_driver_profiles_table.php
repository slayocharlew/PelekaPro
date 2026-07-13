<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('business_branches')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('vehicle_type', ['bodaboda', 'bajaji', 'bicycle', 'car', 'van', 'truck', 'other'])->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('license_number')->nullable();
            $table->boolean('is_available')->default(true);
            $table->enum('current_status', ['available', 'assigned', 'on_delivery', 'offline', 'suspended'])->default('available');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('user_id');
            $table->index(['business_id', 'current_status']);
            $table->index(['branch_id', 'current_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
    }
};
