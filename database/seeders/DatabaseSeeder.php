<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            CountriesSeeder::class,
            CountryConfigsSeeder::class,
            CurrenciesSeeder::class,
            LanguagesSeeder::class,
            SalesPoliciesSeeder::class,
            ThemesSeeder::class,
            CouriersSeeder::class,
        ]);
    }
}
