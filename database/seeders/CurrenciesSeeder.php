<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrenciesSeeder extends Seeder
{
    public function run()
    {
        $currencies = [
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => 1.0000, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'GBP', 'name' => 'British Pound Sterling', 'symbol' => '£', 'exchange_rate' => 0.8500, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 1.0900, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'exchange_rate' => 0.9800, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'kr', 'exchange_rate' => 11.5000, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'kr', 'exchange_rate' => 11.8000, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'kr', 'exchange_rate' => 7.4500, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'PLN', 'name' => 'Polish Zloty', 'symbol' => 'zł', 'exchange_rate' => 4.5000, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'CZK', 'name' => 'Czech Koruna', 'symbol' => 'Kč', 'exchange_rate' => 24.5000, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'HUF', 'name' => 'Hungarian Forint', 'symbol' => 'Ft', 'exchange_rate' => 380.0000, 'decimal_places' => 0, 'is_active' => true],
            ['code' => 'RON', 'name' => 'Romanian Leu', 'symbol' => 'lei', 'exchange_rate' => 4.9500, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'BGN', 'name' => 'Bulgarian Lev', 'symbol' => 'лв', 'exchange_rate' => 1.9558, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'HRK', 'name' => 'Croatian Kuna', 'symbol' => 'kn', 'exchange_rate' => 7.5300, 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'RSD', 'name' => 'Serbian Dinar', 'symbol' => 'дин', 'exchange_rate' => 117.0000, 'decimal_places' => 0, 'is_active' => true],
            ['code' => 'ISK', 'name' => 'Icelandic Krona', 'symbol' => 'kr', 'exchange_rate' => 150.0000, 'decimal_places' => 0, 'is_active' => true],
        ];

        foreach ($currencies as $currency) {
            DB::table('currencies')->updateOrInsert(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}