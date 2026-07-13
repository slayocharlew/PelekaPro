<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
