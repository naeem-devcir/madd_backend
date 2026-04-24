<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddProducts extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            [
                'sku' => 'SKU-001',
                'magento_sku' => 'MAG-SKU-001',
                'name' => 'Premium Wireless Headphones',
                'price' => 99.9900,
                'quantity' => 50,
            ],
            [
                'sku' => 'SKU-002',
                'magento_sku' => 'MAG-SKU-002',
                'name' => 'Ultra HD Smart TV 55"',
                'price' => 599.9900,
                'quantity' => 25,
            ],
            [
                'sku' => 'SKU-003',
                'magento_sku' => 'MAG-SKU-003',
                'name' => 'Wireless Gaming Mouse',
                'price' => 49.9900,
                'quantity' => 100,
            ],
            [
                'sku' => 'SKU-004',
                'magento_sku' => 'MAG-SKU-004',
                'name' => 'Mechanical Keyboard RGB',
                'price' => 89.9900,
                'quantity' => 75,
            ],
            [
                'sku' => 'SKU-005',
                'magento_sku' => 'MAG-SKU-005',
                'name' => 'USB-C Fast Charger 65W',
                'price' => 29.9900,
                'quantity' => 200,
            ],
            [
                'sku' => 'SKU-006',
                'magento_sku' => 'MAG-SKU-006',
                'name' => 'Noise Cancelling Earbuds',
                'price' => 79.9900,
                'quantity' => 150,
            ],
            [
                'sku' => 'SKU-007',
                'magento_sku' => 'MAG-SKU-007',
                'name' => 'Smart Fitness Watch',
                'price' => 149.9900,
                'quantity' => 40,
            ],
            [
                'sku' => 'SKU-008',
                'magento_sku' => 'MAG-SKU-008',
                'name' => 'Portable External SSD 1TB',
                'price' => 129.9900,
                'quantity' => 60,
            ],
            [
                'sku' => 'SKU-009',
                'magento_sku' => 'MAG-SKU-009',
                'name' => '4K Action Camera',
                'price' => 199.9900,
                'quantity' => 30,
            ],
            [
                'sku' => 'SKU-010',
                'magento_sku' => 'MAG-SKU-010',
                'name' => 'Bluetooth Speaker Portable',
                'price' => 59.9900,
                'quantity' => 120,
            ],
        ];

        $now = now();

        foreach ($products as $index => $product) {
            DB::table('vendor_products')->insert([
                'uuid' => (string) Str::uuid(),
                'vendor_id' => 1,
                'vendor_store_id' => 2,
                'magento_product_id' => $index + 1,
                'magento_sku' => $product['magento_sku'],
                'sku' => $product['sku'],
                'name' => $product['name'],
                'type_id' => 'simple',
                'attribute_set_id' => 4,
                'price' => $product['price'],
                'quantity' => $product['quantity'],
                'status' => 'active',
                'sync_status' => 'synced',
                'last_synced_at' => $now,
                'sync_errors' => null,
                'metadata' => json_encode([
                    'weight' => rand(100, 5000) / 1000,
                    'dimensions' => [
                        'length' => rand(5, 50),
                        'width' => rand(5, 50),
                        'height' => rand(1, 30),
                    ],
                    'tags' => ['featured', 'best-seller'],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }
    }
}