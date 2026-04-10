<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class StoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        // Check if vendor exists
        $vendorExists = DB::table('vendors')
            ->where('uuid', '09dc3d9d-169a-49a9-957c-d0a6657c5e6c')
            ->exists();
        
        if (!$vendorExists) {
            $this->command->error('Vendor with UUID 09dc3d9d-169a-49a9-957c-d0a6657c5e6c not found!');
            $this->command->info('Available vendor UUIDs:');
            $vendors = DB::table('vendors')->pluck('uuid');
            foreach ($vendors as $uuid) {
                $this->command->info("  - {$uuid}");
            }
            return;
        }
        
        $stores = [
            [
                'store_name' => 'Tech World',
                'store_slug' => 'tech-world',
                'country_code' => 'PK',
                'currency_code' => 'PKR',
                'primary_color' => '#000000',
                'secondary_color' => '#ffffff',
                'contact_email' => 'tech@example.com',
                'contact_phone' => '03001234567',
                'description' => 'Latest tech gadgets and electronics store in Pakistan', // Added description
            ],
            [
                'store_name' => 'Fashion Hub',
                'store_slug' => 'fashion-hub',
                'country_code' => 'PK',
                'currency_code' => 'PKR',
                'primary_color' => '#ff5733',
                'secondary_color' => '#333333',
                'contact_email' => 'fashion@example.com',
                'contact_phone' => '03111234567',
                'description' => 'Trendy fashion clothing and accessories', // Added description
            ],
            [
                'store_name' => 'Home Decor Plus',
                'store_slug' => 'home-decor-plus',
                'country_code' => 'PK',
                'currency_code' => 'PKR',
                'primary_color' => '#27ae60',
                'secondary_color' => '#f39c12',
                'contact_email' => 'home@example.com',
                'contact_phone' => '03211234567',
                'description' => 'Beautiful home decor and furniture',
            ],
            [
                'store_name' => 'Sports Arena',
                'store_slug' => 'sports-arena',
                'country_code' => 'PK',
                'currency_code' => 'PKR',
                'primary_color' => '#e74c3c',
                'secondary_color' => '#ecf0f1',
                'contact_email' => 'sports@example.com',
                'contact_phone' => '03331234567',
                'description' => 'Sports equipment and activewear',
            ],
            [
                'store_name' => 'Organic Bazaar',
                'store_slug' => 'organic-bazaar',
                'country_code' => 'PK',
                'currency_code' => 'PKR',
                'primary_color' => '#2ecc71',
                'secondary_color' => '#f1c40f',
                'contact_email' => 'organic@example.com',
                'contact_phone' => '03451234567',
                'description' => 'Fresh organic food and products',
            ],
        ];
        
        foreach ($stores as $storeData) {
            // Extract description if exists
            $description = $storeData['description'] ?? null;
            unset($storeData['description']);
            
            $storeRecord = array_merge([
                'uuid' => Str::uuid(),
                'vendor_id' => '09dc3d9d-169a-49a9-957c-d0a6657c5e6c',
                'language_code' => 'en',
                'timezone' => 'Asia/Karachi',
                'domain_id' => null,
                'subdomain' => null,
                'magento_store_id' => null,
                'magento_store_group_id' => null,
                'magento_website_id' => null,
                'theme_id' => null,
                'status' => 'active',
                'sales_policy_id' => null,
                'logo_url' => null,
                'favicon_url' => null,
                'banner_url' => null,
                'seo_meta_title' => null,
                'seo_meta_description' => $description, // Using description as meta description
                'seo_settings' => null,
                'payment_methods' => null,
                'shipping_methods' => null,
                'tax_settings' => null,
                'social_links' => null,
                'google_analytics_id' => null,
                'facebook_pixel_id' => null,
                'custom_css' => null,
                'custom_js' => null,
                'is_demo' => 1,
                'address' => json_encode([
                    'street' => 'Sample Street',
                    'city' => 'Karachi',
                    'state' => 'Sindh',
                    'postal_code' => '12345',
                    'country' => 'Pakistan'
                ]),
                'metadata' => json_encode([
                    'created_by' => 'seeder',
                    'version' => '1.0'
                ]),
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ], $storeData);
            
            DB::table('vendor_stores')->insert($storeRecord);
            $this->command->info("✓ Store created: {$storeData['store_name']}");
        }
        
        $this->command->info("\n✅ Successfully created " . count($stores) . " stores!");
    }
}