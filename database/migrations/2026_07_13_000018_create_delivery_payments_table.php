<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_payments');
    }
};
