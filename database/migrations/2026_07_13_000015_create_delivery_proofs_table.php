<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_proofs');
    }
};
