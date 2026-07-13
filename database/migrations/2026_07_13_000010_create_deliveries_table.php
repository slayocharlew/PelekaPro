<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('business_branches')->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_address_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_number')->unique();
            $table->string('tracking_code')->unique();
            $table->string('public_tracking_token', 80)->unique();
            $table->string('delivery_pin', 10)->nullable();
            $table->enum('status', ['created', 'location_pending', 'location_confirmed', 'assigned', 'accepted', 'on_the_way', 'arrived', 'delivered', 'failed', 'cancelled'])->default('created');
            $table->string('pickup_name')->nullable();
            $table->string('pickup_phone')->nullable();
            $table->text('pickup_address')->nullable();
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->string('dropoff_name')->nullable();
            $table->string('dropoff_phone')->nullable();
            $table->text('dropoff_address')->nullable();
            $table->decimal('dropoff_latitude', 10, 7)->nullable();
            $table->decimal('dropoff_longitude', 10, 7)->nullable();
            $table->enum('payment_method', ['cash_on_delivery', 'prepaid', 'mobile_money', 'bank', 'none'])->default('cash_on_delivery');
            $table->decimal('amount_to_collect', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->text('special_instruction')->nullable();
            $table->timestamp('customer_location_confirmed_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status']);
            $table->index(['assigned_driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
