<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountriesSeeder extends Seeder
{
    public function run()
    {
        $countries = [
            // Western Europe
            ['name' => 'United Kingdom', 'iso2' => 'GB', 'iso3' => 'GBR', 'phone_code' => '44', 'currency_code' => 'GBP', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'London', 'flag' => '🇬🇧', 'is_active' => true],
            ['name' => 'France', 'iso2' => 'FR', 'iso3' => 'FRA', 'phone_code' => '33', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Western Europe', 'capital' => 'Paris', 'flag' => '🇫🇷', 'is_active' => true],
            ['name' => 'Germany', 'iso2' => 'DE', 'iso3' => 'DEU', 'phone_code' => '49', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Western Europe', 'capital' => 'Berlin', 'flag' => '🇩🇪', 'is_active' => true],
            ['name' => 'Italy', 'iso2' => 'IT', 'iso3' => 'ITA', 'phone_code' => '39', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Southern Europe', 'capital' => 'Rome', 'flag' => '🇮🇹', 'is_active' => true],
            ['name' => 'Spain', 'iso2' => 'ES', 'iso3' => 'ESP', 'phone_code' => '34', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Southern Europe', 'capital' => 'Madrid', 'flag' => '🇪🇸', 'is_active' => true],
            ['name' => 'Portugal', 'iso2' => 'PT', 'iso3' => 'PRT', 'phone_code' => '351', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Southern Europe', 'capital' => 'Lisbon', 'flag' => '🇵🇹', 'is_active' => true],
            ['name' => 'Netherlands', 'iso2' => 'NL', 'iso3' => 'NLD', 'phone_code' => '31', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Western Europe', 'capital' => 'Amsterdam', 'flag' => '🇳🇱', 'is_active' => true],
            ['name' => 'Belgium', 'iso2' => 'BE', 'iso3' => 'BEL', 'phone_code' => '32', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Western Europe', 'capital' => 'Brussels', 'flag' => '🇧🇪', 'is_active' => true],
            ['name' => 'Austria', 'iso2' => 'AT', 'iso3' => 'AUT', 'phone_code' => '43', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Western Europe', 'capital' => 'Vienna', 'flag' => '🇦🇹', 'is_active' => true],
            ['name' => 'Switzerland', 'iso2' => 'CH', 'iso3' => 'CHE', 'phone_code' => '41', 'currency_code' => 'CHF', 'region' => 'Europe', 'subregion' => 'Western Europe', 'capital' => 'Bern', 'flag' => '🇨🇭', 'is_active' => true],
            
            // Northern Europe
            ['name' => 'Sweden', 'iso2' => 'SE', 'iso3' => 'SWE', 'phone_code' => '46', 'currency_code' => 'SEK', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'Stockholm', 'flag' => '🇸🇪', 'is_active' => true],
            ['name' => 'Norway', 'iso2' => 'NO', 'iso3' => 'NOR', 'phone_code' => '47', 'currency_code' => 'NOK', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'Oslo', 'flag' => '🇳🇴', 'is_active' => true],
            ['name' => 'Denmark', 'iso2' => 'DK', 'iso3' => 'DNK', 'phone_code' => '45', 'currency_code' => 'DKK', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'Copenhagen', 'flag' => '🇩🇰', 'is_active' => true],
            ['name' => 'Finland', 'iso2' => 'FI', 'iso3' => 'FIN', 'phone_code' => '358', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'Helsinki', 'flag' => '🇫🇮', 'is_active' => true],
            ['name' => 'Ireland', 'iso2' => 'IE', 'iso3' => 'IRL', 'phone_code' => '353', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'Dublin', 'flag' => '🇮🇪', 'is_active' => true],
            
            // Eastern Europe
            ['name' => 'Poland', 'iso2' => 'PL', 'iso3' => 'POL', 'phone_code' => '48', 'currency_code' => 'PLN', 'region' => 'Europe', 'subregion' => 'Eastern Europe', 'capital' => 'Warsaw', 'flag' => '🇵🇱', 'is_active' => true],
            ['name' => 'Czech Republic', 'iso2' => 'CZ', 'iso3' => 'CZE', 'phone_code' => '420', 'currency_code' => 'CZK', 'region' => 'Europe', 'subregion' => 'Eastern Europe', 'capital' => 'Prague', 'flag' => '🇨🇿', 'is_active' => true],
            ['name' => 'Hungary', 'iso2' => 'HU', 'iso3' => 'HUN', 'phone_code' => '36', 'currency_code' => 'HUF', 'region' => 'Europe', 'subregion' => 'Eastern Europe', 'capital' => 'Budapest', 'flag' => '🇭🇺', 'is_active' => true],
            ['name' => 'Romania', 'iso2' => 'RO', 'iso3' => 'ROU', 'phone_code' => '40', 'currency_code' => 'RON', 'region' => 'Europe', 'subregion' => 'Eastern Europe', 'capital' => 'Bucharest', 'flag' => '🇷🇴', 'is_active' => true],
            ['name' => 'Bulgaria', 'iso2' => 'BG', 'iso3' => 'BGR', 'phone_code' => '359', 'currency_code' => 'BGN', 'region' => 'Europe', 'subregion' => 'Eastern Europe', 'capital' => 'Sofia', 'flag' => '🇧🇬', 'is_active' => true],
            
            // Southern Europe
            ['name' => 'Greece', 'iso2' => 'GR', 'iso3' => 'GRC', 'phone_code' => '30', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Southern Europe', 'capital' => 'Athens', 'flag' => '🇬🇷', 'is_active' => true],
            ['name' => 'Croatia', 'iso2' => 'HR', 'iso3' => 'HRV', 'phone_code' => '385', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Southern Europe', 'capital' => 'Zagreb', 'flag' => '🇭🇷', 'is_active' => true],
            ['name' => 'Serbia', 'iso2' => 'RS', 'iso3' => 'SRB', 'phone_code' => '381', 'currency_code' => 'RSD', 'region' => 'Europe', 'subregion' => 'Southern Europe', 'capital' => 'Belgrade', 'flag' => '🇷🇸', 'is_active' => true],
            ['name' => 'Slovenia', 'iso2' => 'SI', 'iso3' => 'SVN', 'phone_code' => '386', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Southern Europe', 'capital' => 'Ljubljana', 'flag' => '🇸🇮', 'is_active' => true],
            ['name' => 'Slovakia', 'iso2' => 'SK', 'iso3' => 'SVK', 'phone_code' => '421', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Eastern Europe', 'capital' => 'Bratislava', 'flag' => '🇸🇰', 'is_active' => true],
            ['name' => 'Estonia', 'iso2' => 'EE', 'iso3' => 'EST', 'phone_code' => '372', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'Tallinn', 'flag' => '🇪🇪', 'is_active' => true],
            ['name' => 'Latvia', 'iso2' => 'LV', 'iso3' => 'LVA', 'phone_code' => '371', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'Riga', 'flag' => '🇱🇻', 'is_active' => true],
            ['name' => 'Lithuania', 'iso2' => 'LT', 'iso3' => 'LTU', 'phone_code' => '370', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'Vilnius', 'flag' => '🇱🇹', 'is_active' => true],
            ['name' => 'Cyprus', 'iso2' => 'CY', 'iso3' => 'CYP', 'phone_code' => '357', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Southern Europe', 'capital' => 'Nicosia', 'flag' => '🇨🇾', 'is_active' => true],
            ['name' => 'Malta', 'iso2' => 'MT', 'iso3' => 'MLT', 'phone_code' => '356', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Southern Europe', 'capital' => 'Valletta', 'flag' => '🇲🇹', 'is_active' => true],
            ['name' => 'Luxembourg', 'iso2' => 'LU', 'iso3' => 'LUX', 'phone_code' => '352', 'currency_code' => 'EUR', 'region' => 'Europe', 'subregion' => 'Western Europe', 'capital' => 'Luxembourg', 'flag' => '🇱🇺', 'is_active' => true],
            ['name' => 'Iceland', 'iso2' => 'IS', 'iso3' => 'ISL', 'phone_code' => '354', 'currency_code' => 'ISK', 'region' => 'Europe', 'subregion' => 'Northern Europe', 'capital' => 'Reykjavik', 'flag' => '🇮🇸', 'is_active' => true],
        ];

        foreach ($countries as $country) {
            DB::table('countries')->updateOrInsert(
                ['iso2' => $country['iso2']],
                $country
            );
        }
    }
}