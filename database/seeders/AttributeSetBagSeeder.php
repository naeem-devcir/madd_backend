<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AttributeGroupMapping;
use App\Models\AttributeSets;
use App\Models\Vendor\Vendor;

class AttributeSetBagSeeder extends Seeder
{
    public function run()
    {
        // Get the vendor (adjust UUID as needed)
        $vendor = Vendor::where('uuid', '7bb5b8ed-1053-4dcd-aeab-7f47c55bda75')->first();
        
        // Get the attribute set for Bag (magento_attr_set_id = 15)
        $attributeSet = AttributeSets::where('magento_attr_set_id', 15)->first();
        
        if (!$vendor || !$attributeSet) {
            $this->command->error('Vendor or Attribute Set not found!');
            $this->command->error('Vendor UUID: 7bb5b8ed-1053-4dcd-aeab-7f47c55bda75');
            $this->command->error('Attribute Set Magento ID: 15 (Bag)');
            return;
        }
        
        $this->command->info('Seeding mappings for attribute set: ' . $attributeSet->attribute_set_name);
        
        // Clear existing mappings
        AttributeGroupMapping::where('attribute_set_id', $attributeSet->id)->delete();
        
        $mappings = [];
        
        // ==================== Product Details (Group ID: 90) ====================
        // Based on jstree HTML - system-leaf class = system attribute
        $productDetailsAttributes = [
            // System attributes (class="system-leaf")
            ['id' => 97, 'code' => 'status', 'label' => 'Enable Product', 'is_system' => true, 'is_required' => true],
            ['id' => 73, 'code' => 'name', 'label' => 'Product Name', 'is_system' => true, 'is_required' => true],
            ['id' => 74, 'code' => 'sku', 'label' => 'SKU', 'is_system' => true, 'is_required' => true],
            ['id' => 77, 'code' => 'price', 'label' => 'Price', 'is_system' => true, 'is_required' => true],
            ['id' => 129, 'code' => 'price_type', 'label' => 'Dynamic Price', 'is_system' => true, 'is_required' => false],
            ['id' => 136, 'code' => 'tax_class_id', 'label' => 'Tax Class', 'is_system' => true, 'is_required' => false],
            ['id' => 115, 'code' => 'quantity_and_stock_status', 'label' => 'Quantity', 'is_system' => true, 'is_required' => false],
            ['id' => 82, 'code' => 'weight', 'label' => 'Weight', 'is_system' => true, 'is_required' => false],
            ['id' => 105, 'code' => 'category_ids', 'label' => 'Categories', 'is_system' => true, 'is_required' => false],
            ['id' => 99, 'code' => 'visibility', 'label' => 'Visibility', 'is_system' => true, 'is_required' => false],
            
            // Custom attributes (class="leaf" without system - these have is_system = false)
            ['id' => 130, 'code' => 'sku_type', 'label' => 'Dynamic SKU', 'is_system' => false, 'is_required' => false],
            ['id' => 131, 'code' => 'weight_type', 'label' => 'Dynamic Weight', 'is_system' => false, 'is_required' => false],
            ['id' => 94, 'code' => 'news_from_date', 'label' => 'Set Product as New from Date', 'is_system' => false, 'is_required' => false],
            ['id' => 95, 'code' => 'news_to_date', 'label' => 'Set Product as New to Date', 'is_system' => false, 'is_required' => false],
            ['id' => 114, 'code' => 'country_of_manufacture', 'label' => 'Country of Manufacture', 'is_system' => false, 'is_required' => false],
            ['id' => 137, 'code' => 'activity', 'label' => 'Activity', 'is_system' => false, 'is_required' => false],
            ['id' => 138, 'code' => 'style_bags', 'label' => 'Style Bags', 'is_system' => false, 'is_required' => false],
            ['id' => 139, 'code' => 'material', 'label' => 'Material', 'is_system' => false, 'is_required' => false],
            ['id' => 93, 'code' => 'color', 'label' => 'Color', 'is_system' => false, 'is_required' => false],
            ['id' => 140, 'code' => 'strap_bags', 'label' => 'Strap/Handle', 'is_system' => false, 'is_required' => false],
            ['id' => 141, 'code' => 'features_bags', 'label' => 'Features', 'is_system' => false, 'is_required' => false],
            ['id' => 145, 'code' => 'eco_collection', 'label' => 'Eco Collection', 'is_system' => false, 'is_required' => false],
            ['id' => 146, 'code' => 'performance_fabric', 'label' => 'Performance Fabric', 'is_system' => false, 'is_required' => false],
            ['id' => 147, 'code' => 'erin_recommends', 'label' => 'Erin Recommends', 'is_system' => false, 'is_required' => false],
            ['id' => 149, 'code' => 'sale', 'label' => 'Sale', 'is_system' => false, 'is_required' => false],
            ['id' => 148, 'code' => 'new', 'label' => 'New', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($productDetailsAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 90,
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Content (Group ID: 89) ====================
        // Both are system attributes (class="system-leaf")
        $contentAttributes = [
            ['id' => 76, 'code' => 'short_description', 'label' => 'Short Description', 'is_system' => true, 'is_required' => false],
            ['id' => 75, 'code' => 'description', 'label' => 'Description', 'is_system' => true, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($contentAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 89,
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Bundle Items (Group ID: 88) ====================
        // This is a custom attribute (class="leaf" without system)
        $bundleAttributes = [
            ['id' => 133, 'code' => 'shipment_type', 'label' => 'Ship Bundle Items', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($bundleAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 88,
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Images (Group ID: 87) ====================
        $imagesAttributes = [
            // System attribute (class="system-leaf")
            ['id' => 87, 'code' => 'image', 'label' => 'Base', 'is_system' => true, 'is_required' => false],
            // Custom attributes (class="leaf" without system)
            ['id' => 88, 'code' => 'small_image', 'label' => 'Small', 'is_system' => false, 'is_required' => false],
            ['id' => 89, 'code' => 'thumbnail', 'label' => 'Thumbnail', 'is_system' => false, 'is_required' => false],
            ['id' => 135, 'code' => 'swatch_image', 'label' => 'Swatch', 'is_system' => false, 'is_required' => false],
            ['id' => 90, 'code' => 'media_gallery', 'label' => 'Media Gallery', 'is_system' => false, 'is_required' => false],
            ['id' => 96, 'code' => 'gallery', 'label' => 'Image Gallery', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($imagesAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 87,
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Search Engine Optimization (Group ID: 86) ====================
        // All are custom attributes (class="leaf" without system)
        $seoAttributes = [
            ['id' => 121, 'code' => 'url_key', 'label' => 'URL Key', 'is_system' => false, 'is_required' => false],
            ['id' => 84, 'code' => 'meta_title', 'label' => 'Meta Title', 'is_system' => false, 'is_required' => false],
            ['id' => 85, 'code' => 'meta_keyword', 'label' => 'Meta Keywords', 'is_system' => false, 'is_required' => false],
            ['id' => 86, 'code' => 'meta_description', 'label' => 'Meta Description', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($seoAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 86,
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Advanced Pricing (Group ID: 85) ====================
        $pricingAttributes = [
            // Custom attributes (class="leaf" without system)
            ['id' => 78, 'code' => 'special_price', 'label' => 'Special Price', 'is_system' => false, 'is_required' => false],
            ['id' => 79, 'code' => 'special_from_date', 'label' => 'Special Price From Date', 'is_system' => false, 'is_required' => false],
            ['id' => 80, 'code' => 'special_to_date', 'label' => 'Special Price To Date', 'is_system' => false, 'is_required' => false],
            ['id' => 81, 'code' => 'cost', 'label' => 'Cost', 'is_system' => false, 'is_required' => false],
            ['id' => 123, 'code' => 'msrp', 'label' => 'Minimum Advertised Price', 'is_system' => false, 'is_required' => false],
            ['id' => 124, 'code' => 'msrp_display_actual_price_type', 'label' => 'Display Actual Price', 'is_system' => false, 'is_required' => false],
            // System attributes (class="system-leaf")
            ['id' => 92, 'code' => 'tier_price', 'label' => 'Tier Price', 'is_system' => true, 'is_required' => false],
            ['id' => 132, 'code' => 'price_view', 'label' => 'Price View', 'is_system' => true, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($pricingAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 85,
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Design (Group ID: 84) ====================
        // All are custom attributes (class="leaf" without system)
        $designAttributes = [
            ['id' => 104, 'code' => 'page_layout', 'label' => 'Layout', 'is_system' => false, 'is_required' => false],
            ['id' => 106, 'code' => 'options_container', 'label' => 'Display Product Options In', 'is_system' => false, 'is_required' => false],
            ['id' => 117, 'code' => 'custom_layout_update_file', 'label' => 'Custom Layout Update', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($designAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 84,
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Schedule Design Update (Group ID: 83) ====================
        // All are custom attributes (class="leaf" without system)
        $scheduleAttributes = [
            ['id' => 101, 'code' => 'custom_design_from', 'label' => 'Active From', 'is_system' => false, 'is_required' => false],
            ['id' => 102, 'code' => 'custom_design_to', 'label' => 'Active To', 'is_system' => false, 'is_required' => false],
            ['id' => 100, 'code' => 'custom_design', 'label' => 'New Theme', 'is_system' => false, 'is_required' => false],
            ['id' => 116, 'code' => 'custom_layout', 'label' => 'New Layout', 'is_system' => false, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($scheduleAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 83,
                'attribute_id' => $attr['id'],
                'sort_order' => $sortOrder++,
                'attribute_code' => $attr['code'],
                'frontend_label' => $attr['label'],
                'is_system' => $attr['is_system'],
                'is_required' => $attr['is_required'],
            ];
        }
        
        // ==================== Autosettings (Group ID: 82) ====================
        // No attributes in this group (class="folder jstree-leaf" with no children)
        
        // ==================== Gift Options (Group ID: 81) ====================
        // System attribute (class="system-leaf")
        $giftAttributes = [
            ['id' => 134, 'code' => 'gift_message_available', 'label' => 'Allow Gift Message', 'is_system' => true, 'is_required' => false],
        ];
        
        $sortOrder = 1;
        foreach ($giftAttributes as $attr) {
            $mappings[] = [
                'vendor_id' => $vendor->id,
                'attribute_set_id' => $attributeSet->id,
                'attribute_group_id' => 81,
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
        
        $this->command->info('Successfully seeded ' . count($mappings) . ' attribute mappings!');
        
        // Display summary by group
        $this->command->info("\n=== Summary by Group ===");
        $groupCounts = [];
        $systemCount = 0;
        $customCount = 0;
        
        foreach ($mappings as $mapping) {
            $groupId = $mapping['attribute_group_id'];
            if (!isset($groupCounts[$groupId])) {
                $groupCounts[$groupId] = 0;
            }
            $groupCounts[$groupId]++;
            
            if ($mapping['is_system']) {
                $systemCount++;
            } else {
                $customCount++;
            }
        }
        
        foreach ($groupCounts as $groupId => $count) {
            $this->command->info("Group ID {$groupId}: {$count} attributes");
        }
        
        $this->command->info("\n=== Total Summary ===");
        $this->command->info("System Attributes (🔒): {$systemCount}");
        $this->command->info("Custom Attributes (🗑️): {$customCount}");
        $this->command->info("Total: " . count($mappings) . " attributes");
    }
}