<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_tracking_locations', function (Blueprint $table): void {
            $table->enum('point_type', ['start', 'end'])->nullable()->after('driver_id');
            $table->unique(
                ['tracking_session_id', 'point_type'],
                'tracking_locations_session_point_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('delivery_tracking_locations', function (Blueprint $table): void {
            $table->dropUnique('tracking_locations_session_point_type_unique');
            $table->dropColumn('point_type');
        });
    }
};
