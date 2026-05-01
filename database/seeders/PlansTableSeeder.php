<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PlansTableSeeder extends Seeder
{
    public function run()
    {
        DB::table('vendor_plans')->insert([
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Basic plan for individuals and small projects',
                'price_monthly' => 9.99,
                'price_yearly' => 99.99,
                'setup_fee' => 0.00,
                'transaction_fee_percentage' => 2.50,
                'transaction_fee_fixed' => 0.30,
                'commission_rate' => 5.00,
                'max_products' => 50,
                'max_stores' => 1,
                'max_users' => 1,
                'bandwidth_limit_mb' => 10240, // 10GB
                'storage_limit_mb' => 5120,    // 5GB
                'features' => json_encode([
                    'Basic Analytics',
                    'Email Support',
                    'Single Store',
                ]),
                'is_active' => 1,
                'is_default' => 1,
                'sort_order' => 1,
                'trial_period_days' => 7,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],

            [
                'name' => 'Growth',
                'slug' => 'growth',
                'description' => 'Perfect for growing businesses',
                'price_monthly' => 29.99,
                'price_yearly' => 299.99,
                'setup_fee' => 9.99,
                'transaction_fee_percentage' => 2.00,
                'transaction_fee_fixed' => 0.25,
                'commission_rate' => 4.00,
                'max_products' => 500,
                'max_stores' => 2,
                'max_users' => 5,
                'bandwidth_limit_mb' => 51200, // 50GB
                'storage_limit_mb' => 20480,   // 20GB
                'features' => json_encode([
                    'Advanced Analytics',
                    'Priority Email Support',
                    'Multiple Stores',
                    'Discount Codes',
                ]),
                'is_active' => 1,
                'is_default' => 0,
                'sort_order' => 2,
                'trial_period_days' => 14,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],

            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Advanced features for scaling businesses',
                'price_monthly' => 79.99,
                'price_yearly' => 799.99,
                'setup_fee' => 19.99,
                'transaction_fee_percentage' => 1.50,
                'transaction_fee_fixed' => 0.20,
                'commission_rate' => 3.00,
                'max_products' => 5000,
                'max_stores' => 5,
                'max_users' => 15,
                'bandwidth_limit_mb' => 204800, // 200GB
                'storage_limit_mb' => 102400,   // 100GB
                'features' => json_encode([
                    'Full Analytics Suite',
                    'Priority Support',
                    'API Access',
                    'Multi-store Management',
                ]),
                'is_active' => 1,
                'is_default' => 0,
                'sort_order' => 3,
                'trial_period_days' => 30,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],

            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Custom solutions for large enterprises',
                'price_monthly' => 199.99,
                'price_yearly' => 1999.99,
                'setup_fee' => 49.99,
                'transaction_fee_percentage' => 1.00,
                'transaction_fee_fixed' => 0.10,
                'commission_rate' => 2.00,
                'max_products' => 999999,
                'max_stores' => 50,
                'max_users' => 100,
                'bandwidth_limit_mb' => null, // unlimited
                'storage_limit_mb' => null,   // unlimited
                'features' => json_encode([
                    'Dedicated Account Manager',
                    '24/7 Support',
                    'Custom Integrations',
                    'Unlimited Scaling',
                ]),
                'is_active' => 1,
                'is_default' => 0,
                'sort_order' => 4,
                'trial_period_days' => 30,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}