<?php
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class VendorProductsSeeder extends Seeder
{
    public function run()
    {
        DB::table('vendor_products')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'vendor_id' => 1,
                'vendor_store_id' => 2,
                'magento_product_id' => 2001,
                'magento_sku' => 'APL-IP15PM-256-BLK',
                'sku' => 'IP15PM-256-BLK',
                'name' => 'Apple iPhone 15 Pro Max 256GB Black Titanium',
                'type_id' => 'simple',
                'attribute_set_id' => 4,
                'price' => 489999.00,
                'quantity' => 15,
                'status' => 'active',
                'sync_status' => 'synced',
                'last_synced_at' => Carbon::now(),
                'metadata' => json_encode([
                    'brand' => 'Apple',
                    'storage' => '256GB',
                    'color' => 'Black Titanium',
                    'category' => 'Smartphones'
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],

            [
                'uuid' => (string) Str::uuid(),
                'vendor_id' => 1,
                'vendor_store_id' => 2,
                'magento_product_id' => 2002,
                'magento_sku' => 'SMSNG-S24U-512-GRY',
                'sku' => 'S24U-512-GRY',
                'name' => 'Samsung Galaxy S24 Ultra 512GB Titanium Gray',
                'type_id' => 'simple',
                'attribute_set_id' => 4,
                'price' => 459999.00,
                'quantity' => 10,
                'status' => 'active',
                'sync_status' => 'pending',
                'last_synced_at' => null,
                'metadata' => json_encode([
                    'brand' => 'Samsung',
                    'storage' => '512GB',
                    'color' => 'Titanium Gray',
                    'category' => 'Smartphones'
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],

            [
                'uuid' => (string) Str::uuid(),
                'vendor_id' => 1,
                'vendor_store_id' => 2,
                'magento_product_id' => 2003,
                'magento_sku' => 'DELL-XPS13-I7-16GB',
                'sku' => 'XPS13-I7-16GB',
                'name' => 'Dell XPS 13 Laptop Intel i7 16GB RAM 512GB SSD',
                'type_id' => 'simple',
                'attribute_set_id' => 4,
                'price' => 389999.00,
                'quantity' => 7,
                'status' => 'active',
                'sync_status' => 'failed',
                'last_synced_at' => Carbon::now(),
                'sync_errors' => 'Stock mismatch during sync',
                'metadata' => json_encode([
                    'brand' => 'Dell',
                    'processor' => 'Intel i7',
                    'ram' => '16GB',
                    'storage' => '512GB SSD',
                    'category' => 'Laptops'
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],

            [
                'uuid' => (string) Str::uuid(),
                'vendor_id' => 1,
                'vendor_store_id' => 2,
                'magento_product_id' => 2004,
                'magento_sku' => 'NKE-AF1-WHT-42',
                'sku' => 'AF1-WHT-42',
                'name' => 'Nike Air Force 1 Low White Sneakers Size 42',
                'type_id' => 'simple',
                'attribute_set_id' => 4,
                'price' => 32999.00,
                'quantity' => 25,
                'status' => 'active',
                'sync_status' => 'synced',
                'last_synced_at' => Carbon::now(),
                'metadata' => json_encode([
                    'brand' => 'Nike',
                    'size' => '42',
                    'color' => 'White',
                    'category' => 'Footwear'
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],

            [
                'uuid' => (string) Str::uuid(),
                'vendor_id' => 1,
                'vendor_store_id' => 2,
                'magento_product_id' => 2005,
                'magento_sku' => 'SONY-WH1000XM5-BLK',
                'sku' => 'WH1000XM5-BLK',
                'name' => 'Sony WH-1000XM5 Wireless Noise Cancelling Headphones Black',
                'type_id' => 'simple',
                'attribute_set_id' => 4,
                'price' => 84999.00,
                'quantity' => 18,
                'status' => 'inactive',
                'sync_status' => 'synced',
                'last_synced_at' => Carbon::now(),
                'metadata' => json_encode([
                    'brand' => 'Sony',
                    'type' => 'Wireless Headphones',
                    'color' => 'Black',
                    'category' => 'Audio'
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}