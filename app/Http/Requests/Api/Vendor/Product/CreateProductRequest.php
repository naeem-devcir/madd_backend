<?php

namespace App\Http\Requests\Api\Vendor\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // Basic required fields
            'sku' => 'required|string|max:64|unique:vendor_products,sku',
            'name' => 'required|string|max:255',
            'type_id' => 'required|string|in:simple,configurable,bundle,grouped,virtual,downloadable',
            'attribute_set_id' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'status' => 'boolean',
            'visibility' => 'integer|in:1,2,3,4',

            // Optional fields
            'weight' => 'nullable|numeric|min:0',
            'tax_class_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',

            // SEO fields
            'url_key' => 'nullable|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_keyword' => 'nullable|string',
            'meta_description' => 'nullable|string|max:500',

            // Advanced pricing
            'special_price' => 'nullable|numeric|min:0',
            'special_from_date' => 'nullable|date',
            'special_to_date' => 'nullable|date|after:special_from_date',
            'cost' => 'nullable|numeric|min:0',
            'msrp' => 'nullable|numeric|min:0',
            'msrp_display_actual_price_type' => 'nullable|in:0,1,2,3',

            // Stock management
            'manage_stock' => 'boolean',
            'backorders' => 'nullable|integer|in:0,1,2',
            'notify_stock_qty' => 'nullable|integer',
            'min_sale_qty' => 'nullable|integer',
            'max_sale_qty' => 'nullable|integer',
            'qty_increments' => 'nullable|integer',
            'enable_qty_increments' => 'boolean',

            // Design
            'custom_design' => 'nullable|string',
            'page_layout' => 'nullable|string',
            'custom_layout_update' => 'nullable|string',

            // Gift options
            'gift_message_available' => 'nullable|boolean',

            // Badge dates
            'news_from_date' => 'nullable|date',
            'news_to_date' => 'nullable|date',
            'country_of_manufacture' => 'nullable|string|size:2',

            // Categories
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer',

            // Media files
            'media_gallery' => 'sometimes|array',
            'media_gallery.*.media_type' => 'required_with:media_gallery|string|in:image,video',
            'media_gallery.*.label' => 'sometimes|string',
            'media_gallery.*.position' => 'sometimes|integer',
            'media_gallery.*.disabled' => 'sometimes|boolean',
            'media_gallery.*.types' => 'sometimes|array',
            'media_gallery.*.content.base64_encoded_data' => 'required_with:media_gallery.*|string',
            'media_gallery.*.content.type' => 'required_with:media_gallery.*|string',
            'media_gallery.*.content.name' => 'required_with:media_gallery.*|string',

            // Product links (related/up-sell/cross-sell)
            'product_links' => 'nullable|array',
            'product_links.*.link_type' => 'required|in:related,up-sell,upsell,cross-sell,crosssell',
            'product_links.*.linked_sku' => 'required|string',
            'product_links.*.linked_type' => 'nullable|string',

            // Custom options
            'custom_options' => 'nullable|array',
            'custom_options.*.title' => 'required|string',
            'custom_options.*.type' => 'required|string|in:drop_down,radio,checkbox,multi,field,area,file,date,time',
            'custom_options.*.is_required' => 'boolean',
            'custom_options.*.sort_order' => 'integer',
            'custom_options.*.price' => 'numeric',
            'custom_options.*.price_type' => 'in:fixed,percent',
            'custom_options.*.values' => 'array',
            'custom_options.*.values.*.title' => 'required|string',
            'custom_options.*.values.*.price' => 'numeric',
            'custom_options.*.values.*.price_type' => 'in:fixed,percent',
            'custom_options.*.values.*.sku' => 'nullable|string',

            // Tier prices
            'tier_prices' => 'nullable|array',

            'tier_prices.*.quantity' => 'required|numeric|min:1',

            'tier_prices.*.price' => 'required|numeric|min:0',

            // Magento expects fixed | discount
            'tier_prices.*.price_type' => 'nullable|in:fixed,discount',

            // Magento expects customer_group name
            'tier_prices.*.customer_group' => 'nullable|string',

            // Optional website id
            'tier_prices.*.website_id' => 'nullable|integer|min:0', // Tier prices
            'tier_prices' => 'nullable|array',

            'tier_prices.*.quantity' => 'required|numeric|min:1',

            'tier_prices.*.price' => 'required|numeric|min:0',

            // Magento expects fixed | discount
            'tier_prices.*.price_type' => 'nullable|in:fixed,discount',

            // Magento expects customer_group name
            'tier_prices.*.customer_group' => 'nullable|string',

            // Optional website id
            'tier_prices.*.website_id' => 'nullable|integer|min:0',

            // MSI Inventory
            'inventory' => 'nullable|array',
            'inventory.source_code' => 'nullable|string',
            'inventory.quantity' => 'nullable|numeric',
            'inventory.status' => 'nullable|integer|in:0,1',

            // Configurable product options
            'configurable_options'                          => 'sometimes|array',
            'configurable_options.*.attribute_id'           => 'required_with:configurable_options|integer',
            'configurable_options.*.label'                  => 'required_with:configurable_options|string',
            'configurable_options.*.position'               => 'sometimes|integer',
            'configurable_options.*.values'                 => 'required_with:configurable_options|array',
            'configurable_options.*.values.*.value_index'   => 'required|integer',

            'configurable_variants'                                         => 'sometimes|array',
            'configurable_variants.*.sku'                                   => 'required_with:configurable_variants|string',
            'configurable_variants.*.name'                                  => 'required_with:configurable_variants|string',
            'configurable_variants.*.price'                                 => 'sometimes|numeric',
            'configurable_variants.*.quantity'                              => 'sometimes|integer',
            'configurable_variants.*.weight'                                => 'sometimes|numeric',
            'configurable_variants.*.attribute_set_id'                      => 'sometimes|integer',
            'configurable_variants.*.status'                                => 'sometimes|integer',
            'configurable_variants.*.visibility'                            => 'sometimes|integer',
            'configurable_variants.*.configurable_attributes'               => 'sometimes|array',
            'configurable_variants.*.configurable_attributes.*'             => 'sometimes|integer',


            // Dynamic attributes
            'dynamic_attributes' => 'nullable|array',
            'dynamic_attributes.*' => 'nullable',
        ];
    }

    public function messages()
    {
        return [
            'sku.unique' => 'This SKU already exists in your product list.',
            'type_id.in' => 'Product type must be simple, configurable, bundle, grouped, virtual, or downloadable.',
        ];
    }
}
