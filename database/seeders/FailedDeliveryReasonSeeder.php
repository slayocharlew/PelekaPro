<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FailedDeliveryReasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $reasons = [
            'Customer not reachable',
            'Wrong location',
            'Customer refused',
            'Customer not available',
            'Payment issue',
            'Driver issue',
            'Package damaged',
            'Other',
        ];

        foreach ($reasons as $reason) {
            DB::table('failed_delivery_reasons')->updateOrInsert(
                ['name' => $reason],
                [
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
