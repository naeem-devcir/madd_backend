<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run()
    {
        $user = User::create([
            'uuid' => Str::uuid(),
            'email' => 'superadmin@madd.com',
            'password' => Hash::make('87654321'),
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
        ]);
        
        // Assign role if you're using spatie/laravel-permission
        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            $user->assignRole('super_admin');
        }
        
        $this->command->info('Super admin created successfully!');
        $this->command->info('Email: superadmin@madd.com');
        $this->command->info('Password: 87654321');
    }
}