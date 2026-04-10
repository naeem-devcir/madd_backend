<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Vendor\Vendor;

class DummyVendorStoresSeeder extends Seeder
{
    public function run()
    {
        // Get first vendor (using UUID)
        $vendor = Vendor::first();
        
        if (!$vendor) {
            $this->command->error('No vendor found! Please create a vendor first.');
            return;
        }
        
        $vendorUuid = $vendor->uuid; // This is the UUID field
        
        $this->command->info("Using Vendor UUID: {$vendorUuid}");
        
        $dummyStores = [
            [
                'store_name' => 'Digital Paradise',
                'store_slug' => 'digital-paradise',
                'description' => 'Your one-stop shop for digital products and software',
                'country_code' => 'US',
                'currency_code' => 'USD',
                'language_code' => 'en',
                'timezone' => 'America/New_York',
                'subdomain' => 'digital-paradise',
                'status' => 'active',
                'contact_email' => 'digital@paradise.com',
                'contact_phone' => '+15551234567',
                'primary_color' => '#9b59b6',
                'secondary_color' => '#3498db',
                'logo_url' => 'https://via.placeholder.com/200x200?text=Digital+Paradise',
                'banner_url' => 'https://via.placeholder.com/1200x400?text=Digital+Paradise+Banner',
                'seo_meta_title' => 'Digital Paradise - Best Digital Products',
                'seo_meta_description' => 'Shop the best digital products and software online',
                'is_demo' => true,
            ],
            [
                'store_name' => 'Fitness Pro Shop',
                'store_slug' => 'fitness-pro-shop',
                'description' => 'Professional fitness equipment and supplements',
                'country_code' => 'US',
                'currency_code' => 'USD',
                'language_code' => 'en',
                'timezone' => 'America/Chicago',
                'subdomain' => 'fitness-pro',
                'status' => 'active',
                'contact_email' => 'info@fitnesspro.com',
                'contact_phone' => '+15557654321',
                'primary_color' => '#e74c3c',
                'secondary_color' => '#2c3e50',
                'logo_url' => 'https://via.placeholder.com/200x200?text=Fitness+Pro',
                'banner_url' => 'https://via.placeholder.com/1200x400?text=Fitness+Pro+Banner',
                'seo_meta_title' => 'Fitness Pro - Premium Fitness Equipment',
                'seo_meta_description' => 'Get the best fitness equipment and supplements',
                'is_demo' => true,
            ],
            [
                'store_name' => 'Pet Paradise',
                'store_slug' => 'pet-paradise',
                'description' => 'Everything your furry friends need',
                'country_code' => 'CA',
                'currency_code' => 'CAD',
                'language_code' => 'en',
                'timezone' => 'America/Toronto',
                'subdomain' => 'pet-paradise',
                'status' => 'active',
                'contact_email' => 'hello@petparadise.com',
                'contact_phone' => '+15559876543',
                'primary_color' => '#f39c12',
                'secondary_color' => '#16a085',
                'logo_url' => 'https://via.placeholder.com/200x200?text=Pet+Paradise',
                'banner_url' => 'https://via.placeholder.com/1200x400?text=Pet+Paradise+Banner',
                'seo_meta_title' => 'Pet Paradise - Best Pet Supplies',
                'seo_meta_description' => 'Shop quality products for your pets',
                'is_demo' => true,
            ],
            [
                'store_name' => 'Garden Oasis',
                'store_slug' => 'garden-oasis',
                'description' => 'Plants, tools, and garden accessories',
                'country_code' => 'UK',
                'currency_code' => 'GBP',
                'language_code' => 'en',
                'timezone' => 'Europe/London',
                'subdomain' => 'garden-oasis',
                'status' => 'active',
                'contact_email' => 'contact@gardenoasis.com',
                'contact_phone' => '+442012345678',
                'primary_color' => '#27ae60',
                'secondary_color' => '#f1c40f',
                'logo_url' => 'https://via.placeholder.com/200x200?text=Garden+Oasis',
                'banner_url' => 'https://via.placeholder.com/1200x400?text=Garden+Oasis+Banner',
                'seo_meta_title' => 'Garden Oasis - Garden Supplies',
                'seo_meta_description' => 'Transform your garden with our products',
                'is_demo' => true,
            ],
            [
                'store_name' => 'Toy Kingdom',
                'store_slug' => 'toy-kingdom',
                'description' => 'Toys for kids of all ages',
                'country_code' => 'US',
                'currency_code' => 'USD',
                'language_code' => 'en',
                'timezone' => 'America/New_York',
                'subdomain' => 'toy-kingdom',
                'status' => 'active',
                'contact_email' => 'support@toykingdom.com',
                'contact_phone' => '+15551112222',
                'primary_color' => '#e84393',
                'secondary_color' => '#fd79a8',
                'logo_url' => 'https://via.placeholder.com/200x200?text=Toy+Kingdom',
                'banner_url' => 'https://via.placeholder.com/1200x400?text=Toy+Kingdom+Banner',
                'seo_meta_title' => 'Toy Kingdom - Best Toys for Kids',
                'seo_meta_description' => 'Wide range of toys for all ages',
                'is_demo' => true,
            ],
        ];
        
        foreach ($dummyStores as $storeData) {
            $storeId = DB::table('vendor_stores')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'vendor_id' => $vendorUuid, // UUID from vendors table
                'store_name' => $storeData['store_name'],
                'store_slug' => $storeData['store_slug'],
                'country_code' => $storeData['country_code'],
                'language_code' => $storeData['language_code'],
                'currency_code' => $storeData['currency_code'],
                'timezone' => $storeData['timezone'],
                'subdomain' => $storeData['subdomain'],
                'status' => $storeData['status'],
                'description' => $storeData['description'],
                'logo_url' => $storeData['logo_url'],
                'banner_url' => $storeData['banner_url'],
                'primary_color' => $storeData['primary_color'],
                'secondary_color' => $storeData['secondary_color'],
                'contact_email' => $storeData['contact_email'],
                'contact_phone' => $storeData['contact_phone'],
                'seo_meta_title' => $storeData['seo_meta_title'],
                'seo_meta_description' => $storeData['seo_meta_description'],
                'is_demo' => $storeData['is_demo'],
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $this->command->info("✓ Created store: {$storeData['store_name']} (ID: {$storeId})");
            $this->command->info("  Vendor UUID: {$vendorUuid}");
        }
        
        $this->command->info("\n✅ Successfully created " . count($dummyStores) . " dummy stores!");
    }
}