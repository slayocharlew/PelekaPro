<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_delivery_request_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_delivery_request_id');
            $table->string('item_name');
            $table->integer('quantity')->default(1);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('customer_delivery_request_id', 'cdr_items_request_idx');
            $table->foreign('customer_delivery_request_id', 'cdr_items_request_fk')
                ->references('id')
                ->on('customer_delivery_requests')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_delivery_request_items');
    }
};
