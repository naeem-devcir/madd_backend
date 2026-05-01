<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CouriersSeeder extends Seeder
{
    public function run()
    {
        $couriers = [
            [
                'uuid' => Str::uuid(),
                'name' => 'DPD',
                'code' => 'dpd',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'api_key' => 'test_key',
                    'api_secret' => 'test_secret',
                ]),
                'countries' => json_encode(['GB', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'PL', 'CZ', 'AT']),
                'service_levels' => json_encode([
                    ['name' => 'Standard', 'code' => 'standard', 'delivery_days' => 3],
                    ['name' => 'Express', 'code' => 'express', 'delivery_days' => 1],
                    ['name' => 'Saturday', 'code' => 'saturday', 'delivery_days' => 1],
                ]),
                'tracking_url_template' => 'https://tracking.dpd.com/{tracking_number}',
                'logo_url' => 'https://example.com/logos/dpd.png',
                'support_contact' => json_encode(['email' => 'support@dpd.com', 'phone' => '+44 123 456 789']),
                'settlement_contact' => json_encode(['email' => 'settlements@dpd.com', 'department' => 'Finance']),
                'weight_limit_kg' => 31.5,
                'insurance_options' => json_encode(['basic' => 25, 'premium' => 50]),
                'data_processing_agreement' => true,
                'contract_reference' => 'DPD-2024-001',
                'settlement_due_day' => 30,
                'is_active' => true,
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'DHL Express',
                'code' => 'dhl',
                'api_type' => 'soap',
                'credentials' => json_encode([
                    'api_key' => 'test_key',
                    'account_number' => '12345678',
                ]),
                'countries' => json_encode(['GB', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'AT', 'CH', 'SE', 'DK', 'NO', 'FI']),
                'service_levels' => json_encode([
                    ['name' => 'Express Easy', 'code' => 'easy', 'delivery_days' => 2],
                    ['name' => 'Express 9:00', 'code' => '9am', 'delivery_days' => 1],
                    ['name' => 'Express 12:00', 'code' => '12pm', 'delivery_days' => 1],
                    ['name' => 'Economy Select', 'code' => 'economy', 'delivery_days' => 4],
                ]),
                'tracking_url_template' => 'https://www.dhl.com/track/{tracking_number}',
                'logo_url' => 'https://example.com/logos/dhl.png',
                'support_contact' => json_encode(['email' => 'business@dhl.com', 'phone' => '0844 248 0844']),
                'settlement_contact' => json_encode(['email' => 'billing@dhl.com', 'department' => 'Billing']),
                'weight_limit_kg' => 70,
                'insurance_options' => json_encode(['standard' => 0, 'enhanced' => 1.5, 'full' => 3]),
                'data_processing_agreement' => true,
                'contract_reference' => 'DHL-2024-002',
                'settlement_due_day' => 30,
                'is_active' => true,
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'UPS',
                'code' => 'ups',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'client_id' => 'test_client',
                    'client_secret' => 'test_secret',
                    'account_number' => '12345',
                ]),
                'countries' => json_encode(['US', 'GB', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'AT', 'CH', 'SE', 'DK', 'NO', 'FI', 'PL', 'CZ']),
                'service_levels' => json_encode([
                    ['name' => 'UPS Standard', 'code' => 'standard', 'delivery_days' => 3],
                    ['name' => 'UPS Express', 'code' => 'express', 'delivery_days' => 2],
                    ['name' => 'UPS Express Plus', 'code' => 'plus', 'delivery_days' => 1],
                    ['name' => 'UPS Expedited', 'code' => 'expedited', 'delivery_days' => 2],
                ]),
                'tracking_url_template' => 'https://www.ups.com/track/{tracking_number}',
                'logo_url' => 'https://example.com/logos/ups.png',
                'support_contact' => json_encode(['email' => 'customer.service@ups.com', 'phone' => '03457 877 877']),
                'settlement_contact' => json_encode(['email' => 'billing@ups.com', 'department' => 'Accounts']),
                'weight_limit_kg' => 70,
                'insurance_options' => json_encode(['basic' => 0, 'increased' => 2]),
                'data_processing_agreement' => true,
                'contract_reference' => 'UPS-2024-003',
                'settlement_due_day' => 30,
                'is_active' => true,
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'Royal Mail',
                'code' => 'royal-mail',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'api_key' => 'test_key',
                    'client_id' => 'test_client',
                ]),
                'countries' => json_encode(['GB', 'IE']),
                'service_levels' => json_encode([
                    ['name' => '1st Class', 'code' => 'first', 'delivery_days' => 1],
                    ['name' => '2nd Class', 'code' => 'second', 'delivery_days' => 3],
                    ['name' => 'Special Delivery', 'code' => 'special', 'delivery_days' => 1],
                    ['name' => 'Tracked 24', 'code' => 'tracked24', 'delivery_days' => 1],
                    ['name' => 'Tracked 48', 'code' => 'tracked48', 'delivery_days' => 2],
                ]),
                'tracking_url_template' => 'https://www.royalmail.com/track/{tracking_number}',
                'logo_url' => 'https://example.com/logos/royalmail.png',
                'support_contact' => json_encode(['email' => 'business@royalmail.com', 'phone' => '03457 950 950']),
                'settlement_contact' => json_encode(['email' => 'billing@royalmail.com', 'department' => 'Accounts']),
                'weight_limit_kg' => 20,
                'insurance_options' => json_encode(['standard' => 0, 'enhanced' => 2.5]),
                'data_processing_agreement' => true,
                'contract_reference' => 'RM-2024-004',
                'settlement_due_day' => 30,
                'is_active' => true,
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'FedEx',
                'code' => 'fedex',
                'api_type' => 'rest',
                'credentials' => json_encode([
                    'api_key' => 'test_key',
                    'api_secret' => 'test_secret',
                    'account_number' => '123456',
                ]),
                'countries' => json_encode(['US', 'GB', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'AT', 'CH', 'SE', 'DK', 'NO', 'FI']),
                'service_levels' => json_encode([
                    ['name' => 'FedEx Express', 'code' => 'express', 'delivery_days' => 2],
                    ['name' => 'FedEx Priority', 'code' => 'priority', 'delivery_days' => 1],
                    ['name' => 'FedEx Economy', 'code' => 'economy', 'delivery_days' => 4],
                ]),
                'tracking_url_template' => 'https://www.fedex.com/track/{tracking_number}',
                'logo_url' => 'https://example.com/logos/fedex.png',
                'support_contact' => json_encode(['email' => 'customer.service@fedex.com', 'phone' => '0345 607 0809']),
                'settlement_contact' => json_encode(['email' => 'billing@fedex.com', 'department' => 'Finance']),
                'weight_limit_kg' => 68,
                'insurance_options' => json_encode(['basic' => 0, 'premium' => 2]),
                'data_processing_agreement' => true,
                'contract_reference' => 'FDX-2024-005',
                'settlement_due_day' => 30,
                'is_active' => true,
            ],
        ];

        foreach ($couriers as $courier) {
            DB::table('couriers')->insert($courier);
        }
    }
}