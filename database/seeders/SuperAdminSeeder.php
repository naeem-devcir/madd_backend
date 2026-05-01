<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure role exists
        $adminRole = Role::where('name', 'super_admin')->first();

        if (!$adminRole) {
            $this->command->error('Admin role not found. Run RolesAndPermissionsSeeder first.');
            return;
        }

        // Create or update user (SAFE)
        $user = User::updateOrCreate(
            ['email' => 'superadmin@madd.com'], // unique key
            [
                'uuid' => Str::uuid(),
                'password' => Hash::make('Password123!'), // bcrypt ✅
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'user_type' => 'super_admin',
                'status' => 'active',
                'country_code' => 'US',
                'locale' => 'en',
                'timezone' => 'UTC',
                'email_verified_at' => now(),
                'login_attempts' => 0,
                'gdpr_consent_at' => now(),
                'marketing_opt_in' => false,
            ]
        );

        // Assign role safely
        if (!$user->hasRole('super_admin')) {
            $user->assignRole($adminRole);
        }

        $this->command->info('✅ Super Admin seeded successfully.');
    }
}