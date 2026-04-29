<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CouriersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $couriers = [
            [
                'name' => 'DHL Express',
                'code' => 'DHL',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'api_key' => 'dhl_live_key_12345',
                    'api_secret' => 'secret_67890',
                    'account_number' => 'DHL-ACC-001',
                    'environment' => 'production'
                ]),
                'countries' => json_encode([
                    'US', 'GB', 'DE', 'FR', 'IT', 'ES', 'CA', 'AU', 'JP', 'CN'
                ]),
                'service_levels' => json_encode([
                    ['code' => 'express_easy', 'name' => 'Express Easy', 'delivery_days' => '1-3'],
                    ['code' => 'express_9am', 'name' => 'Express 9:00', 'delivery_days' => '1'],
                    ['code' => 'express_12pm', 'name' => 'Express 12:00', 'delivery_days' => '1'],
                    ['code' => 'economy_select', 'name' => 'Economy Select', 'delivery_days' => '2-5']
                ]),
                'tracking_url_template' => 'https://www.dhl.com/en/express/tracking.html?AWB={tracking_number}',
                'logo_url' => 'https://example.com/logos/dhl.png',
                'support_contact' => json_encode([
                    'phone' => '+1-800-225-5345',
                    'email' => 'support@dhl.com',
                    'website' => 'https://www.dhl.com'
                ]),
                'settlement_contact' => json_encode([
                    'finance_email' => 'finance@dhl.com',
                    'billing_address' => 'DHL Headquarters, Germany'
                ]),
                'weight_limit_kg' => 70.00,
                'insurance_options' => json_encode([
                    ['type' => 'basic', 'coverage' => 100, 'price' => 0],
                    ['type' => 'premium', 'coverage' => 500, 'price' => 19.99],
                    ['type' => 'full', 'coverage' => 2000, 'price' => 49.99]
                ]),
                'data_processing_agreement' => 1,
                'contract_reference' => 'DHL-CONTRACT-2024-001',
                'settlement_due_day' => 15,
                'is_active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                'name' => 'FedEx',
                'code' => 'FEDEX',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'api_key' => 'fedex_live_key_54321',
                    'api_secret' => 'secret_09876',
                    'account_number' => 'FEDEX-ACC-002',
                    'meter_number' => 'METER-123456'
                ]),
                'countries' => json_encode([
                    'US', 'CA', 'MX', 'GB', 'DE', 'FR', 'IT', 'ES', 'AU', 'JP', 'BR', 'IN'
                ]),
                'service_levels' => json_encode([
                    ['code' => 'priority_overnight', 'name' => 'Priority Overnight', 'delivery_days' => '1'],
                    ['code' => 'standard_overnight', 'name' => 'Standard Overnight', 'delivery_days' => '1'],
                    ['code' => '2day', 'name' => '2Day', 'delivery_days' => '2'],
                    ['code' => 'express_saver', 'name' => 'Express Saver', 'delivery_days' => '3'],
                    ['code' => 'ground', 'name' => 'Ground', 'delivery_days' => '1-5']
                ]),
                'tracking_url_template' => 'https://www.fedex.com/fedextrack/?trknbr={tracking_number}',
                'logo_url' => 'https://example.com/logos/fedex.png',
                'support_contact' => json_encode([
                    'phone' => '+1-800-463-3339',
                    'email' => 'support@fedex.com',
                    'website' => 'https://www.fedex.com'
                ]),
                'settlement_contact' => json_encode([
                    'finance_email' => 'finance@fedex.com',
                    'billing_address' => 'FedEx World Headquarters, USA'
                ]),
                'weight_limit_kg' => 68.00,
                'insurance_options' => json_encode([
                    ['type' => 'declared_value', 'coverage' => 100, 'price' => 0],
                    ['type' => 'additional', 'coverage' => 500, 'price' => 15.00],
                    ['type' => 'full_protection', 'coverage' => 1000, 'price' => 29.99]
                ]),
                'data_processing_agreement' => 1,
                'contract_reference' => 'FEDEX-CONTRACT-2024-002',
                'settlement_due_day' => 20,
                'is_active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                'name' => 'UPS',
                'code' => 'UPS',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'api_key' => 'ups_live_key_11111',
                    'api_secret' => 'secret_22222',
                    'account_number' => 'UPS-ACC-003',
                    'shipper_number' => 'SHIPPER-789'
                ]),
                'countries' => json_encode([
                    'US', 'CA', 'MX', 'GB', 'DE', 'FR', 'IT', 'ES', 'AU', 'JP', 'KR', 'SG'
                ]),
                'service_levels' => json_encode([
                    ['code' => 'next_day_air', 'name' => 'Next Day Air', 'delivery_days' => '1'],
                    ['code' => '2nd_day_air', 'name' => '2nd Day Air', 'delivery_days' => '2'],
                    ['code' => '3_day_select', 'name' => '3 Day Select', 'delivery_days' => '3'],
                    ['code' => 'ground', 'name' => 'Ground', 'delivery_days' => '1-5'],
                    ['code' => 'worldwide_express', 'name' => 'Worldwide Express', 'delivery_days' => '1-3']
                ]),
                'tracking_url_template' => 'https://www.ups.com/track?tracknum={tracking_number}',
                'logo_url' => 'https://example.com/logos/ups.png',
                'support_contact' => json_encode([
                    'phone' => '+1-800-742-5877',
                    'email' => 'support@ups.com',
                    'website' => 'https://www.ups.com'
                ]),
                'settlement_contact' => json_encode([
                    'finance_email' => 'finance@ups.com',
                    'billing_address' => 'UPS Headquarters, USA'
                ]),
                'weight_limit_kg' => 70.00,
                'insurance_options' => json_encode([
                    ['type' => 'basic', 'coverage' => 100, 'price' => 0],
                    ['type' => 'enhanced', 'coverage' => 300, 'price' => 12.50],
                    ['type' => 'premium', 'coverage' => 1000, 'price' => 39.99]
                ]),
                'data_processing_agreement' => 1,
                'contract_reference' => 'UPS-CONTRACT-2024-003',
                'settlement_due_day' => 15,
                'is_active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                'name' => 'USPS',
                'code' => 'USPS',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'api_key' => 'usps_live_key_33333',
                    'api_secret' => 'secret_44444',
                    'username' => 'usps_user_001'
                ]),
                'countries' => json_encode([
                    'US', 'PR', 'GU', 'VI', 'MP', 'AS'
                ]),
                'service_levels' => json_encode([
                    ['code' => 'priority_express', 'name' => 'Priority Express', 'delivery_days' => '1-2'],
                    ['code' => 'priority', 'name' => 'Priority Mail', 'delivery_days' => '1-3'],
                    ['code' => 'first_class', 'name' => 'First Class', 'delivery_days' => '1-5'],
                    ['code' => 'media_mail', 'name' => 'Media Mail', 'delivery_days' => '2-8'],
                    ['code' => 'ground_advantage', 'name' => 'Ground Advantage', 'delivery_days' => '2-5']
                ]),
                'tracking_url_template' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking_number}',
                'logo_url' => 'https://example.com/logos/usps.png',
                'support_contact' => json_encode([
                    'phone' => '+1-800-275-8777',
                    'email' => 'support@usps.com',
                    'website' => 'https://www.usps.com'
                ]),
                'settlement_contact' => json_encode([
                    'finance_email' => 'finance@usps.gov',
                    'billing_address' => 'USPS Headquarters, USA'
                ]),
                'weight_limit_kg' => 31.50,
                'insurance_options' => json_encode([
                    ['type' => 'standard', 'coverage' => 100, 'price' => 0],
                    ['type' => 'additional', 'coverage' => 500, 'price' => 15.00],
                    ['type' => 'registered', 'coverage' => 50000, 'price' => 45.00]
                ]),
                'data_processing_agreement' => 0,
                'contract_reference' => 'USPS-CONTRACT-2024-004',
                'settlement_due_day' => 30,
                'is_active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                'name' => 'Canada Post',
                'code' => 'CANPOST',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'api_key' => 'canpost_live_key_55555',
                    'api_secret' => 'secret_66666',
                    'customer_number' => 'CP-USER-001'
                ]),
                'countries' => json_encode([
                    'CA', 'US', 'GB', 'AU', 'FR', 'DE'
                ]),
                'service_levels' => json_encode([
                    ['code' => 'priority_next_day', 'name' => 'Priority Next Day', 'delivery_days' => '1'],
                    ['code' => 'xpresspost', 'name' => 'Xpresspost', 'delivery_days' => '1-2'],
                    ['code' => 'expedited', 'name' => 'Expedited', 'delivery_days' => '2-7'],
                    ['code' => 'regular', 'name' => 'Regular Parcel', 'delivery_days' => '2-9']
                ]),
                'tracking_url_template' => 'https://www.canadapost.ca/trackweb/en#/details/{tracking_number}',
                'logo_url' => 'https://example.com/logos/canadapost.png',
                'support_contact' => json_encode([
                    'phone' => '+1-866-607-6301',
                    'email' => 'support@canadapost.ca',
                    'website' => 'https://www.canadapost.ca'
                ]),
                'settlement_contact' => json_encode([
                    'finance_email' => 'finance@canadapost.ca',
                    'billing_address' => 'Canada Post Headquarters, Canada'
                ]),
                'weight_limit_kg' => 30.00,
                'insurance_options' => json_encode([
                    ['type' => 'basic_coverage', 'coverage' => 100, 'price' => 0],
                    ['type' => 'additional_coverage', 'coverage' => 1000, 'price' => 25.00]
                ]),
                'data_processing_agreement' => 1,
                'contract_reference' => 'CANPOST-CONTRACT-2024-005',
                'settlement_due_day' => 20,
                'is_active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                'name' => 'Aramex',
                'code' => 'ARAMEX',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'api_key' => 'aramex_live_key_77777',
                    'api_secret' => 'secret_88888',
                    'account_number' => 'ARAMEX-ACC-001',
                    'account_pin' => '1234'
                ]),
                'countries' => json_encode([
                    'AE', 'SA', 'KW', 'QA', 'BH', 'OM', 'JO', 'EG', 'LB', 'IN', 'PK'
                ]),
                'service_levels' => json_encode([
                    ['code' => 'express', 'name' => 'Express', 'delivery_days' => '1-3'],
                    ['code' => 'economy', 'name' => 'Economy', 'delivery_days' => '3-5'],
                    ['code' => 'freight', 'name' => 'Freight', 'delivery_days' => '5-10']
                ]),
                'tracking_url_template' => 'https://www.aramex.com/track/results.aspx?ShipmentNumber={tracking_number}',
                'logo_url' => 'https://example.com/logos/aramex.png',
                'support_contact' => json_encode([
                    'phone' => '+971-4-222-1111',
                    'email' => 'support@aramex.com',
                    'website' => 'https://www.aramex.com'
                ]),
                'settlement_contact' => json_encode([
                    'finance_email' => 'finance@aramex.com',
                    'billing_address' => 'Aramex Headquarters, UAE'
                ]),
                'weight_limit_kg' => 50.00,
                'insurance_options' => json_encode([
                    ['type' => 'standard', 'coverage' => 100, 'price' => 0],
                    ['type' => 'declared_value', 'coverage' => 500, 'price' => 10.00],
                    ['type' => 'full_value', 'coverage' => 2000, 'price' => 35.00]
                ]),
                'data_processing_agreement' => 1,
                'contract_reference' => 'ARAMEX-CONTRACT-2024-006',
                'settlement_due_day' => 15,
                'is_active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
        ];

        foreach ($couriers as $courier) {
            $courier['uuid'] = Str::uuid();
            DB::table('couriers')->insert($courier);
        }
        
        $this->command->info('Couriers seeded successfully!');
    }
}