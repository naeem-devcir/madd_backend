<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AttributeGroupMapping;
use App\Models\AttributeSets;
use App\Models\Vendor\Vendor;

class DefaultAttributeSetSeeder extends Seeder
{
    public function run()
    {
        // Get the vendor
        $vendor = Vendor::where('uuid', '7bb5b8ed-1053-4dcd-aeab-7f47c55bda75')->first();
        
        if (!$vendor) {
            $this->command->error('Vendor not found!');
            return;
        }
        
        // Get the default attribute set (magento_attr_set_id = 4)
        $attributeSet = AttributeSets::where('magento_attr_set_id', 4)->first();
        
        if (!$attributeSet) {
            $this->command->error('Default attribute set (ID: 4) not found in local database!');
            return;
        }
        
        $this->command->info('Seeding mappings for default attribute set: ' . $attributeSet->attribute_set_name);
        
        // Clear existing mappings
        AttributeGroupMapping::where('attribute_set_id', $attributeSet->id)->delete();
        
        $mappings = [];
        
        // ==================== Product Details (Group ID: 7) ====================
        // From jstree: id="#_7" - Product Details
        // system-leaf = system attribute (cannot be unassigned)
        // leaf = custom attribute (can be unassigned)
        
        $productDetailsAttributes = [
            // System attributes (class="system-leaf")
            ['id' => 97, 'code' => 'status', 'label' => 'status', 'is_system' => true, 'is_required' => true],
            ['id' => 73, 'code' => 'name', 'label' => 'name', 'is_system' => true, 'is_required' => true],
            ['id' => 74, 'code' => 'sku', 'label' => 'sku', 'is_system' => true, 'is_required' => true],
            ['id' => 77, 'code' => 'price', 'label' => 'price', 'is_system' => true, 'is_required' => true],
            ['id' => 129, 'code' => 'price_type', 'label' => 'price_type', 'is_system' => true, 'is_required' => false],
            ['id' => 136, 'code' => 'tax_class_id', 'label' => 'tax_class_id', 'is_system' => true, 'is_required' => false],
            ['id' => 115, 'code' => 'quantity_and_stock_status', 'label' => 'quantity_and_stock_status', 'is_system' => true, 'is_required' => false],
            ['id' => 82, 'code' => 'weight', 'label' => 'weight', 'is_system' => true, 'is_required' => false],
            ['id' => 105, 'code' => 'category_ids', 'label' => 'category_ids', 'is_system' => true, 'is_required' => false],
            ['id' => 99, 'code' => 'visibility', 'label' => 'visibility', 'is_system' => true, 'is_required' => false],
            
            // Custom attributes (class="leaf" without system)
            ['id' => 130, 'code' => 'sku_type', 'label' => 'sku_type', 'is_system' => false, 'is_required' => false],
            ['id' => 131, 'code' => 'weight_type', 'label' => 'weight_type', 'is_system' => false, 'is_required' => false],
            ['id' => 94, 'code' => 'news_from_date', 'label' => 'news_from_date', 'is_system' => false, 'is_required' => false],
            ['id' => 95, 'code' => 'news_to_date', 'label' => 'news_to_date', 'is_system' => false, 'is_required' => false],
            ['id' => 114, 'code' => 'country_of_manufacture', 'label' => 'country_of_manufacture', 'is_system' => false, 'is_required' => false],
            ['id' => 93, 'code' => 'color', 'label' => 'color', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($productDetailsAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 7,  // Product Details group ID
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Content (Group ID: 13) ====================
        // From jstree: id="#_13" - Content
        $contentAttributes = [
            ['id' => 76, 'code' => 'short_description', 'label' => 'short_description', 'is_system' => true, 'is_required' => false],
            ['id' => 75, 'code' => 'description', 'label' => 'description', 'is_system' => true, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($contentAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 13,  // Content group ID
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Bundle Items (Group ID: 19) ====================
        // From jstree: id="#_19" - Bundle Items
        $bundleAttributes = [
            ['id' => 133, 'code' => 'shipment_type', 'label' => 'shipment_type', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($bundleAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 19,  // Bundle Items group ID
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Images (Group ID: 10) ====================
        // From jstree: id="#_10" - Images
        $imagesAttributes = [
            // System attribute
            ['id' => 87, 'code' => 'image', 'label' => 'image', 'is_system' => true, 'is_required' => false],
            // Custom attributes
            ['id' => 88, 'code' => 'small_image', 'label' => 'small_image', 'is_system' => false, 'is_required' => false],
            ['id' => 89, 'code' => 'thumbnail', 'label' => 'thumbnail', 'is_system' => false, 'is_required' => false],
            ['id' => 135, 'code' => 'swatch_image', 'label' => 'swatch_image', 'is_system' => false, 'is_required' => false],
            ['id' => 90, 'code' => 'media_gallery', 'label' => 'media_gallery', 'is_system' => false, 'is_required' => false],
            ['id' => 96, 'code' => 'gallery', 'label' => 'gallery', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($imagesAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 10,  // Images group ID
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Search Engine Optimization (Group ID: 9) ====================
        // From jstree: id="#_9" - Search Engine Optimization
        $seoAttributes = [
            ['id' => 121, 'code' => 'url_key', 'label' => 'url_key', 'is_system' => false, 'is_required' => false],
            ['id' => 84, 'code' => 'meta_title', 'label' => 'meta_title', 'is_system' => false, 'is_required' => false],
            ['id' => 85, 'code' => 'meta_keyword', 'label' => 'meta_keyword', 'is_system' => false, 'is_required' => false],
            ['id' => 86, 'code' => 'meta_description', 'label' => 'meta_description', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($seoAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 9,  // Search Engine Optimization group ID
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Advanced Pricing (Group ID: 8) ====================
        // From jstree: id="#_8" - Advanced Pricing
        $pricingAttributes = [
            // Custom attributes
            ['id' => 78, 'code' => 'special_price', 'label' => 'special_price', 'is_system' => false, 'is_required' => false],
            ['id' => 79, 'code' => 'special_from_date', 'label' => 'special_from_date', 'is_system' => false, 'is_required' => false],
            ['id' => 80, 'code' => 'special_to_date', 'label' => 'special_to_date', 'is_system' => false, 'is_required' => false],
            ['id' => 81, 'code' => 'cost', 'label' => 'cost', 'is_system' => false, 'is_required' => false],
            ['id' => 123, 'code' => 'msrp', 'label' => 'msrp', 'is_system' => false, 'is_required' => false],
            ['id' => 124, 'code' => 'msrp_display_actual_price_type', 'label' => 'msrp_display_actual_price_type', 'is_system' => false, 'is_required' => false],
            // System attributes
            ['id' => 92, 'code' => 'tier_price', 'label' => 'tier_price', 'is_system' => true, 'is_required' => false],
            ['id' => 132, 'code' => 'price_view', 'label' => 'price_view', 'is_system' => true, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($pricingAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 8,  // Advanced Pricing group ID
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Design (Group ID: 11) ====================
        // From jstree: id="#_11" - Design
        $designAttributes = [
            ['id' => 104, 'code' => 'page_layout', 'label' => 'page_layout', 'is_system' => false, 'is_required' => false],
            ['id' => 106, 'code' => 'options_container', 'label' => 'options_container', 'is_system' => false, 'is_required' => false],
            ['id' => 117, 'code' => 'custom_layout_update_file', 'label' => 'custom_layout_update_file', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($designAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 11,  // Design group ID
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Schedule Design Update (Group ID: 14) ====================
        // From jstree: id="#_14" - Schedule Design Update
        $scheduleAttributes = [
            ['id' => 101, 'code' => 'custom_design_from', 'label' => 'custom_design_from', 'is_system' => false, 'is_required' => false],
            ['id' => 102, 'code' => 'custom_design_to', 'label' => 'custom_design_to', 'is_system' => false, 'is_required' => false],
            ['id' => 100, 'code' => 'custom_design', 'label' => 'custom_design', 'is_system' => false, 'is_required' => false],
            ['id' => 116, 'code' => 'custom_layout', 'label' => 'custom_layout', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($scheduleAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 14,  // Schedule Design Update group ID
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Autosettings (Group ID: 12) ====================
        // From jstree: id="#_12" - Autosettings (no attributes)
        // No attributes in this group
        
        // ==================== Gift Options (Group ID: 20) ====================
        // From jstree: id="#_20" - Gift Options
        $giftAttributes = [
            ['id' => 134, 'code' => 'gift_message_available', 'label' => 'gift_message_available', 'is_system' => true, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($giftAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 20,  // Gift Options group ID
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // Insert all mappings
        foreach ($mappings as $mapping) {
            AttributeGroupMapping::updateOrCreate(
                [
                    'vendor_id' => $mapping['vendor_id'],
                    'attribute_set_id' => $mapping['attribute_set_id'],
                    'attribute_id' => $mapping['attribute_id'],
                ],
                $mapping
            );
        }
        
        // Update the attribute_group_data for the default set with correct group IDs from jstree
        $defaultGroupData = [
            ['attribute_group_id' => 7, 'attribute_group_name' => 'Product Details', 'attribute_set_id' => 4, 'sort_order' => 10],
            ['attribute_group_id' => 13, 'attribute_group_name' => 'Content', 'attribute_set_id' => 4, 'sort_order' => 20],
            ['attribute_group_id' => 19, 'attribute_group_name' => 'Bundle Items', 'attribute_set_id' => 4, 'sort_order' => 30],
            ['attribute_group_id' => 10, 'attribute_group_name' => 'Images', 'attribute_set_id' => 4, 'sort_order' => 40],
            ['attribute_group_id' => 9, 'attribute_group_name' => 'Search Engine Optimization', 'attribute_set_id' => 4, 'sort_order' => 50],
            ['attribute_group_id' => 8, 'attribute_group_name' => 'Advanced Pricing', 'attribute_set_id' => 4, 'sort_order' => 60],
            ['attribute_group_id' => 11, 'attribute_group_name' => 'Design', 'attribute_set_id' => 4, 'sort_order' => 70],
            ['attribute_group_id' => 14, 'attribute_group_name' => 'Schedule Design Update', 'attribute_set_id' => 4, 'sort_order' => 80],
            ['attribute_group_id' => 12, 'attribute_group_name' => 'Autosettings', 'attribute_set_id' => 4, 'sort_order' => 90],
            ['attribute_group_id' => 20, 'attribute_group_name' => 'Gift Options', 'attribute_set_id' => 4, 'sort_order' => 100],
        ];
        
        $attributeSet->update([
            'attribute_group_data' => $defaultGroupData,
            'assigned_attribute_ids' => collect($mappings)->pluck('attribute_id')->toArray(),
        ]);
        
        $this->command->info('Successfully seeded ' . count($mappings) . ' attribute mappings for default attribute set!');
        
        // Display summary
        $systemCount = collect($mappings)->where('is_system', true)->count();
        $customCount = collect($mappings)->where('is_system', false)->count();
        
        $this->command->info("\n=== Summary ===");
        $this->command->info("System Attributes (🔒): {$systemCount}");
        $this->command->info("Custom Attributes (🗑️): {$customCount}");
        $this->command->info("Total: " . count($mappings) . " attributes");
    }
}