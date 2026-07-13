<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_reconciliations');
    }
};
