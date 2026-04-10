<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    public function run()
    {
        // Clear existing data
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('model_has_roles')->truncate();
        DB::table('roles')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Also clear Spatie permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $roles = [
            [
                'name'         => 'super_admin',
                'display_name' => 'Super Admin',
                'description'  => 'Full unrestricted access to the entire platform',
                'guard_name'   => 'web',
                'is_system'    => true,
                'level'        => 100,
            ],
            [
                'name'         => 'admin',
                'display_name' => 'Admin',
                'description'  => 'Platform management — users, vendors, orders, settlements',
                'guard_name'   => 'web',
                'is_system'    => true,
                'level'        => 80,
            ],
            [
                'name'         => 'vendor',
                'display_name' => 'Vendor',
                'description'  => 'Seller account — manages stores, products, and orders',
                'guard_name'   => 'web',
                'is_system'    => true,
                'level'        => 50,
            ],
            [
                'name'         => 'mlm_agent',
                'display_name' => 'MLM Agent',
                'description'  => 'Referral agent — earns commissions by onboarding vendors',
                'guard_name'   => 'web',
                'is_system'    => true,
                'level'        => 30,
            ],
            [
                'name'         => 'customer',
                'display_name' => 'Customer',
                'description'  => 'Buyer account — browses catalog and places orders',
                'guard_name'   => 'web',
                'is_system'    => true,
                'level'        => 10,
            ],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }

        $this->command->info('✅ Roles seeded successfully — 5 roles created.');
    }
}