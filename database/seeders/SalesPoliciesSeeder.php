<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalesPoliciesSeeder extends Seeder
{
    public function run()
    {
        $policies = [
            [
                'country_code' => 'GB',
                'name' => 'UK Standard Sales Policy',
                'payment_methods' => json_encode(['credit_card', 'paypal', 'bank_transfer']),
                'shipping_methods' => json_encode(['standard', 'express', 'next_day']),
                'allowed_currencies' => json_encode(['GBP', 'EUR', 'USD']),
                'tax_class' => 'standard',
                'min_order_amount' => 0,
                'guest_checkout_allowed' => true,
                'return_window_days' => 30,
                'terms_url' => 'https://example.com/terms',
                'privacy_policy_url' => 'https://example.com/privacy',
                'withdrawal_right_text' => 'You have the right to cancel your order within 14 days without giving any reason.',
                'is_active' => true,
            ],
            [
                'country_code' => 'DE',
                'name' => 'German Standard Sales Policy',
                'payment_methods' => json_encode(['credit_card', 'paypal', 'sofort', 'bank_transfer']),
                'shipping_methods' => json_encode(['standard', 'express', 'dhl']),
                'allowed_currencies' => json_encode(['EUR', 'GBP', 'USD']),
                'tax_class' => 'standard',
                'min_order_amount' => 0,
                'guest_checkout_allowed' => true,
                'return_window_days' => 14,
                'terms_url' => 'https://example.de/terms',
                'privacy_policy_url' => 'https://example.de/privacy',
                'withdrawal_right_text' => 'Sie haben das Recht, binnen 14 Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen.',
                'is_active' => true,
            ],
            [
                'country_code' => 'FR',
                'name' => 'French Standard Sales Policy',
                'payment_methods' => json_encode(['credit_card', 'paypal', 'cb', 'bank_transfer']),
                'shipping_methods' => json_encode(['standard', 'express', 'chronopost']),
                'allowed_currencies' => json_encode(['EUR', 'GBP', 'USD']),
                'tax_class' => 'standard',
                'min_order_amount' => 0,
                'guest_checkout_allowed' => true,
                'return_window_days' => 14,
                'terms_url' => 'https://example.fr/terms',
                'privacy_policy_url' => 'https://example.fr/privacy',
                'withdrawal_right_text' => 'Vous disposez d\'un délai de 14 jours pour exercer votre droit de rétractation.',
                'is_active' => true,
            ],
            [
                'country_code' => 'ES',
                'name' => 'Spanish Standard Sales Policy',
                'payment_methods' => json_encode(['credit_card', 'paypal', 'bank_transfer']),
                'shipping_methods' => json_encode(['standard', 'express']),
                'allowed_currencies' => json_encode(['EUR', 'GBP', 'USD']),
                'tax_class' => 'standard',
                'min_order_amount' => 50,
                'guest_checkout_allowed' => true,
                'return_window_days' => 14,
                'terms_url' => 'https://example.es/terms',
                'privacy_policy_url' => 'https://example.es/privacy',
                'withdrawal_right_text' => 'Tiene derecho a desistir del contrato en un plazo de 14 días sin indicar el motivo.',
                'is_active' => true,
            ],
            [
                'country_code' => 'IT',
                'name' => 'Italian Standard Sales Policy',
                'payment_methods' => json_encode(['credit_card', 'paypal', 'bank_transfer']),
                'shipping_methods' => json_encode(['standard', 'express']),
                'allowed_currencies' => json_encode(['EUR', 'GBP', 'USD']),
                'tax_class' => 'standard',
                'min_order_amount' => 0,
                'guest_checkout_allowed' => true,
                'return_window_days' => 14,
                'terms_url' => 'https://example.it/terms',
                'privacy_policy_url' => 'https://example.it/privacy',
                'withdrawal_right_text' => 'Hai il diritto di recedere dal contratto entro 14 giorni senza fornire alcuna motivazione.',
                'is_active' => true,
            ],
            [
                'country_code' => 'NL',
                'name' => 'Dutch Standard Sales Policy',
                'payment_methods' => json_encode(['credit_card', 'paypal', 'ideal', 'bank_transfer']),
                'shipping_methods' => json_encode(['standard', 'express', 'postnl']),
                'allowed_currencies' => json_encode(['EUR', 'GBP', 'USD']),
                'tax_class' => 'standard',
                'min_order_amount' => 0,
                'guest_checkout_allowed' => true,
                'return_window_days' => 30,
                'terms_url' => 'https://example.nl/terms',
                'privacy_policy_url' => 'https://example.nl/privacy',
                'withdrawal_right_text' => 'U heeft het recht om binnen 14 dagen zonder opgave van redenen de overeenkomst te herroepen.',
                'is_active' => true,
            ],
            [
                'country_code' => 'SE',
                'name' => 'Swedish Standard Sales Policy',
                'payment_methods' => json_encode(['credit_card', 'paypal', 'swish', 'bank_transfer']),
                'shipping_methods' => json_encode(['standard', 'express', 'postnord']),
                'allowed_currencies' => json_encode(['SEK', 'EUR', 'GBP']),
                'tax_class' => 'standard',
                'min_order_amount' => 0,
                'guest_checkout_allowed' => true,
                'return_window_days' => 14,
                'terms_url' => 'https://example.se/terms',
                'privacy_policy_url' => 'https://example.se/privacy',
                'withdrawal_right_text' => 'Du har rätt att ångra ditt köp inom 14 dagar utan att ange någon anledning.',
                'is_active' => true,
            ],
        ];

        foreach ($policies as $policy) {
            DB::table('sales_policies')->insert($policy);
        }
    }
}