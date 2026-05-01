<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run()
    {
        $defaultSettings = [
            // General settings
            ['group_name' => 'general', 'key_name' => 'site_name', 'value' => 'MADD Commerce', 'type' => 'string'],
            ['group_name' => 'general', 'key_name' => 'contact_email', 'value' => 'admin@example.com', 'type' => 'string'],
            ['group_name' => 'general', 'key_name' => 'default_currency', 'value' => 'EUR', 'type' => 'string'],
            ['group_name' => 'general', 'key_name' => 'default_language', 'value' => 'en', 'type' => 'string'],
            ['group_name' => 'general', 'key_name' => 'timezone', 'value' => 'UTC', 'type' => 'string'],
            
            // Payment settings
            ['group_name' => 'payment', 'key_name' => 'stripe_enabled', 'value' => '0', 'type' => 'boolean'],
            ['group_name' => 'payment', 'key_name' => 'paypal_enabled', 'value' => '0', 'type' => 'boolean'],
            ['group_name' => 'payment', 'key_name' => 'paypal_mode', 'value' => 'sandbox', 'type' => 'string'],
            
            // API settings
            ['group_name' => 'api', 'key_name' => 'api_rate_limit', 'value' => '100', 'type' => 'integer'],
            ['group_name' => 'api', 'key_name' => 'enable_api_logging', 'value' => '1', 'type' => 'boolean'],
            
            // Security settings
            ['group_name' => 'security', 'key_name' => 'two_factor_required', 'value' => '0', 'type' => 'boolean'],
            ['group_name' => 'security', 'key_name' => 'session_timeout', 'value' => '120', 'type' => 'integer'],
        ];
        
        foreach ($defaultSettings as $setting) {
            SystemSetting::updateOrCreate(
                ['key_name' => $setting['key_name']],
                $setting
            );
        }
    }
}