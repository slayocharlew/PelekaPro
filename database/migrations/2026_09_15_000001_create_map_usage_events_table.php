<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_usage_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->string('surface', 40);
            $table->string('provider', 20);
            $table->dateTime('loaded_at')->index();
            $table->index(['surface', 'loaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_usage_events');
    }
};
