<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firebase_tracking_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error_type')->nullable();
            $table->timestamps();

            $table->unique(['delivery_id', 'event_type']);
            $table->index(['processed_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firebase_tracking_outbox');
    }
};
