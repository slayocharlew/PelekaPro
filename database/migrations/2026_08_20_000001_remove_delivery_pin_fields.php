<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_proofs', function (Blueprint $table): void {
            $table->dropColumn(['pin_verified', 'entered_pin']);
        });

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn('delivery_pin');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('delivery_pin', 10)->nullable();
        });

        Schema::table('delivery_proofs', function (Blueprint $table): void {
            $table->boolean('pin_verified')->default(false);
            $table->string('entered_pin')->nullable();
        });
    }
};
