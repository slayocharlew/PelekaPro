<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'phone']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('region')->nullable();
            $table->string('district')->nullable();
            $table->string('ward')->nullable();
            $table->string('street')->nullable();
            $table->text('landmark')->nullable();
            $table->text('building_instruction')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'customer_id']);
        });

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
            $table->enum('status', [
                'created',
                'location_pending',
                'location_confirmed',
                'assigned',
                'accepted',
                'on_the_way',
                'arrived',
                'delivered',
                'failed',
                'cancelled',
            ])->default('created');
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

        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('item_name');
            $table->integer('quantity')->default(1);
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['delivery_id', 'created_at']);
        });

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

        Schema::create('delivery_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->boolean('pin_verified')->default(false);
            $table->string('entered_pin')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->decimal('delivered_latitude', 10, 7)->nullable();
            $table->decimal('delivered_longitude', 10, 7)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique('delivery_id');
        });

        Schema::create('failed_delivery_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('delivery_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('failed_delivery_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason_note')->nullable();
            $table->decimal('failed_latitude', 10, 7)->nullable();
            $table->decimal('failed_longitude', 10, 7)->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['delivery_id', 'failed_at']);
        });

        Schema::create('delivery_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('payment_method', ['cash', 'mobile_money', 'bank', 'prepaid', 'none'])->default('cash');
            $table->decimal('expected_amount', 12, 2)->default(0);
            $table->decimal('collected_amount', 12, 2)->default(0);
            $table->enum('payment_status', ['pending', 'collected', 'partial', 'failed', 'not_required'])->default('pending');
            $table->string('reference_number')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'payment_status']);
            $table->index(['driver_id', 'payment_status']);
        });

        Schema::create('cash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reconciliation_date');
            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('cash_returned', 12, 2)->default(0);
            $table->decimal('difference', 12, 2)->default(0);
            $table->enum('status', ['pending', 'balanced', 'shortage', 'excess'])->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'driver_id', 'reconciliation_date'], 'cash_recon_business_driver_date_index');
        });

        Schema::create('cash_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_payment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['cash_reconciliation_id', 'delivery_payment_id'], 'cash_recon_items_recon_payment_unique');
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_email')->nullable();
            $table->enum('channel', ['sms', 'whatsapp', 'email', 'push'])->default('sms');
            $table->text('message');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->decimal('cost', 10, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['delivery_id', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('cash_reconciliation_items');
        Schema::dropIfExists('cash_reconciliations');
        Schema::dropIfExists('delivery_payments');
        Schema::dropIfExists('delivery_failures');
        Schema::dropIfExists('failed_delivery_reasons');
        Schema::dropIfExists('delivery_proofs');
        Schema::dropIfExists('delivery_tracking_locations');
        Schema::dropIfExists('delivery_tracking_sessions');
        Schema::dropIfExists('delivery_status_logs');
        Schema::dropIfExists('delivery_items');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('driver_profiles');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
