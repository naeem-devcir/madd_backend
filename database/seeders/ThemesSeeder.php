<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ThemesSeeder extends Seeder
{
    public function run()
    {
        $themes = [
            [
                'name' => 'Modern Commerce',
                'slug' => 'modern-commerce',
                'description' => 'A modern, clean, and responsive theme for e-commerce stores',
                'preview_url' => 'https://example.com/themes/modern-commerce/preview.jpg',
                'screenshot_url' => 'https://example.com/themes/modern-commerce/screenshot.jpg',
                'category' => 'fashion',
                'config_schema' => json_encode([
                    'colors' => ['primary' => '#14B8A6', 'secondary' => '#10B981'],
                    'layout' => 'full-width',
                    'sidebar_position' => 'right'
                ]),
                'is_active' => true,
                'is_premium' => false,
                'price' => 0,
            ],
            [
                'name' => 'Minimal Store',
                'slug' => 'minimal-store',
                'description' => 'A minimalist theme focused on product presentation',
                'preview_url' => 'https://example.com/themes/minimal-store/preview.jpg',
                'screenshot_url' => 'https://example.com/themes/minimal-store/screenshot.jpg',
                'category' => 'minimal',
                'config_schema' => json_encode([
                    'colors' => ['primary' => '#000000', 'secondary' => '#ffffff'],
                    'layout' => 'boxed',
                    'sidebar_position' => 'left'
                ]),
                'is_active' => true,
                'is_premium' => false,
                'price' => 0,
            ],
            [
                'name' => 'Luxury Boutique',
                'slug' => 'luxury-boutique',
                'description' => 'Premium theme for luxury brands and high-end products',
                'preview_url' => 'https://example.com/themes/luxury-boutique/preview.jpg',
                'screenshot_url' => 'https://example.com/themes/luxury-boutique/screenshot.jpg',
                'category' => 'luxury',
                'config_schema' => json_encode([
                    'colors' => ['primary' => '#D4AF37', 'secondary' => '#1C1C1C'],
                    'layout' => 'full-width',
                    'sidebar_position' => 'right'
                ]),
                'is_active' => true,
                'is_premium' => true,
                'price' => 299.00,
            ],
            [
                'name' => 'Electro Hub',
                'slug' => 'electro-hub',
                'description' => 'Theme designed for electronics and gadgets stores',
                'preview_url' => 'https://example.com/themes/electro-hub/preview.jpg',
                'screenshot_url' => 'https://example.com/themes/electro-hub/screenshot.jpg',
                'category' => 'electronics',
                'config_schema' => json_encode([
                    'colors' => ['primary' => '#2563EB', 'secondary' => '#DBEAFE'],
                    'layout' => 'full-width',
                    'sidebar_position' => 'left'
                ]),
                'is_active' => true,
                'is_premium' => false,
                'price' => 0,
            ],
            [
                'name' => 'Organic Market',
                'slug' => 'organic-market',
                'description' => 'Earthy theme for organic food and natural products',
                'preview_url' => 'https://example.com/themes/organic-market/preview.jpg',
                'screenshot_url' => 'https://example.com/themes/organic-market/screenshot.jpg',
                'category' => 'food',
                'config_schema' => json_encode([
                    'colors' => ['primary' => '#059669', 'secondary' => '#A7F3D0'],
                    'layout' => 'boxed',
                    'sidebar_position' => 'right'
                ]),
                'is_active' => true,
                'is_premium' => false,
                'price' => 0,
            ],
            [
                'name' => 'Sports Zone',
                'slug' => 'sports-zone',
                'description' => 'Dynamic theme for sports and fitness equipment',
                'preview_url' => 'https://example.com/themes/sports-zone/preview.jpg',
                'screenshot_url' => 'https://example.com/themes/sports-zone/screenshot.jpg',
                'category' => 'sports',
                'config_schema' => json_encode([
                    'colors' => ['primary' => '#DC2626', 'secondary' => '#FEE2E2'],
                    'layout' => 'full-width',
                    'sidebar_position' => 'right'
                ]),
                'is_active' => true,
                'is_premium' => true,
                'price' => 149.00,
            ],
        ];

        foreach ($themes as $theme) {
            DB::table('themes')->insert($theme);
        }
    }
}