<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_failures');
    }
};
