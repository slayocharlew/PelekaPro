<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $roles = [
            ['name' => 'super_admin', 'display_name' => 'Super Admin'],
            ['name' => 'business_owner', 'display_name' => 'Business Owner'],
            ['name' => 'business_admin', 'display_name' => 'Business Admin'],
            ['name' => 'driver', 'display_name' => 'Driver'],
            ['name' => 'customer', 'display_name' => 'Customer'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                [
                    'display_name' => $role['display_name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
