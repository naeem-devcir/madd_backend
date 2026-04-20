<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SettlementSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('settlements')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'payable_type' => 'App\\Models\\Vendor\\Vendor',
                'payable_id' => 2,
                'vendor_id' => 2,
                'madd_company_id' => null,
                'approved_by' => null,

                'period_start' => Carbon::now()->subDays(30),
                'period_end' => Carbon::now(),
                'period_days' => 30,

                'gross_sales' => 5000,
                'total_refunds' => 200,
                'total_commissions' => 300,
                'total_shipping_fees' => 100,
                'total_tax_collected' => 150,
                'adjustment_amount' => 0,
                'gateway_fees' => 50,

                'net_payout' => 4200,

                'currency_code' => 'USD',
                'exchange_rate' => 1,

                'status' => 'pending',

                'payment_method' => null,
                'payment_reference' => null,
                'statement_pdf_path' => null,

                'approved_at' => null,
                'paid_at' => null,

                'notes' => 'Test settlement seed data',

                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}