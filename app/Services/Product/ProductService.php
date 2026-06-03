<?php
// app/Services/Product/ProductService.php

namespace App\Services\Product;

use App\Services\Integration\MagentoService;
use App\Models\Vendor\Vendor;
use Illuminate\Support\Facades\Log;
use Exception;

class ProductService
{
    protected MagentoService $magento;
    protected Vendor $vendor;

    public static function forVendor(Vendor $vendor): self
    {
        return new self($vendor);
    }

    public function __construct(Vendor $vendor)
    {
        $this->vendor = $vendor;
        $this->magento = MagentoService::forVendor($vendor);
    }


    public function fetchAllProducts(array $filters = []): array
    {
        try {
            $query = [];

            // Pagination
            $query['searchCriteria[currentPage]'] = $filters['page'] ?? 1;
            $query['searchCriteria[pageSize]'] = $filters['per_page'] ?? 100;

            // Optional search by SKU
            if (!empty($filters['sku'])) {
                $query['searchCriteria[filter_groups][0][filters][0][field]'] = 'sku';
                $query['searchCriteria[filter_groups][0][filters][0][value]'] = '%' . $filters['sku'] . '%';
                $query['searchCriteria[filter_groups][0][filters][0][condition_type]'] = 'like';
            }

            // Optional search by name
            if (!empty($filters['name'])) {
                $query['searchCriteria[filter_groups][1][filters][0][field]'] = 'name';
                $query['searchCriteria[filter_groups][1][filters][0][value]'] = '%' . $filters['name'] . '%';
                $query['searchCriteria[filter_groups][1][filters][0][condition_type]'] = 'like';
            }

            // Optional status filter
            if (isset($filters['status'])) {
                $query['searchCriteria[filter_groups][2][filters][0][field]'] = 'status';
                $query['searchCriteria[filter_groups][2][filters][0][value]'] = $filters['status'];
                $query['searchCriteria[filter_groups][2][filters][0][condition_type]'] = 'eq';
            }

            // Sorting
            $query['searchCriteria[sortOrders][0][field]'] = $filters['sort_by'] ?? 'entity_id';
            $query['searchCriteria[sortOrders][0][direction]'] = $filters['sort_order'] ?? 'DESC';

            $endpoint = 'products?' . http_build_query($query);

            Log::info('Fetching Magento Products', [
                'endpoint' => $endpoint,
                'vendor_id' => $this->vendor->id,
            ]);

            $response = $this->magento->get($endpoint);

            if (isset($response['items'])) {
                return $response;
            }

            if (is_array($response) && !isset($response['items']) && !isset($response['message'])) {
                return [
                    'items' => $response,
                    'total_count' => count($response)
                ];
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('Magento fetchAllProducts failed', [
                'message' => $e->getMessage(),
                'vendor_id' => $this->vendor->id,
            ]);
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            throw new Exception('Failed to fetch Magento products: ' . $filteredMessage);
        }
    }

    /**
     * Get single product by SKU
     */
    public function getProductBySku(string $sku): ?array
    {
        try {
            return $this->magento->get("products/{$sku}");
        } catch (\Exception $e) {
            Log::warning("Failed to fetch product {$sku}: " . $e->getMessage(), [
                'vendor_id' => $this->vendor->id ?? 'unknown'
            ]);
            return null;
        }
    }


    /**
     * Create Simple Product
     */
    public function createProduct(array $formData): array
    {
        try {
            $product = $this->createCoreProduct($formData);
            $sku = $product['sku'];

            // Post-creation steps
            if (!empty($formData['category_ids'])) {
                $this->assignCategories($sku, $formData['category_ids']);
            }

            if (!empty($formData['product_links'])) {
                $this->assignProductLinks($sku, $formData['product_links']);
            }

            if (!empty($formData['custom_options'])) {
                $this->addCustomOptions($sku, $formData['custom_options']);
            }

            if (!empty($formData['tier_prices'])) {
                $this->setTierPrices($sku, $formData['tier_prices']);
            }

            if (isset($formData['inventory'])) {
                $this->assignMSIInventory($sku, $formData['inventory']);
            }

            return [
                'success' => true,
                'message' => 'Product created successfully',
                'product' => $product,
                'sku' => $sku,
            ];
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            Log::error('Magento Product Creation Failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'sku' => $formData['sku'] ?? 'unknown',
            ]);
            throw new Exception('Product creation failed: ' . $filteredMessage);
        }
    }

    /**
     * Create Configurable Product
     * Order: Children → Parent → Link Options → Link Children
     */
    public function createConfigurableProduct(array $formData): array
    {
        try {
            $parentSku     = $formData['sku'];
            $childVariants = $formData['configurable_variants'] ?? [];
            $configOptions = $formData['configurable_options']  ?? [];  // single source, no fallback chain

            \Log::info('createConfigurableProduct received', [
                'sku'           => $parentSku,
                'options_count' => count($configOptions),
                'options'       => $configOptions,
                'variants_count' => count($childVariants),
            ]);

            if (empty($childVariants)) {
                throw new \Exception('Configurable products require at least one variant');
            }

            if (empty($configOptions)) {
                throw new \Exception(
                    'configurable_options missing. Keys received: ' . implode(', ', array_keys($formData))
                );
            }

            // STEP 1: Create parent
            $parentPayload = $this->buildConfigurableParentPayload($formData);
            $parent = $this->magento->post('products', $parentPayload);

            // STEP 2: Add configurable options to parent BEFORE linking children
            $this->addConfigurableOptions($parentSku, $configOptions);

            // STEP 3: Create children
            $childSkus = [];
            foreach ($childVariants as $childData) {
                $childSku    = $this->createConfigurableChild($parentSku, $childData);
                $childSkus[] = $childSku;
            }

            // STEP 4: Link children to parent
            foreach ($childSkus as $childSku) {
                $this->linkChildToParent($parentSku, $childSku);
            }

            // STEP 5: Post-processing
            if (!empty($formData['category_ids'])) {
                $this->assignCategories($parentSku, $formData['category_ids']);
            }
            if (!empty($formData['product_links'])) {
                $this->assignProductLinks($parentSku, $formData['product_links']);
            }
            if (!empty($formData['tier_prices'])) {
                $this->setTierPrices($parentSku, $formData['tier_prices']);
            }
            if (!empty($formData['inventory'])) {
                $this->assignMSIInventory($parentSku, $formData['inventory']);
            }

            return [
                'success'    => true,
                'message'    => 'Configurable product created successfully',
                'parent_sku' => $parentSku,
                'child_skus' => $childSkus,
                'product'    => $parent,
                'sku'        => $parentSku,
            ];
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            $this->cleanupFailedConfigurable($parentSku ?? null, $childSkus ?? []);
            Log::error('Configurable Product Creation Failed: ' . $e->getMessage(), [
                'sku' => $formData['sku'] ?? 'unknown',
            ]);
            throw new \Exception('Configurable product creation failed: ' . $filteredMessage);
        }
    }

    /**
     * Generate variants from configurable options
     */
    protected function generateVariantsFromOptions(array $formData, array $configOptions): array
    {
        $variants = [];
        $baseSku = $formData['sku'];
        $baseName = $formData['name'];
        $basePrice = $formData['price'] ?? 0;
        $baseWeight = $formData['weight'] ?? 0;

        // Get all selected attribute values
        $attributeValueSets = [];
        $attributeCodes = [];

        foreach ($configOptions as $option) {
            $attributeId = $option['attribute_id'];
            $values = $option['values'];

            // You need to fetch actual value labels from attribute values
            // For now, use value_index as the identifier
            $valueSet = [];
            foreach ($values as $value) {
                $valueSet[] = [
                    'value_index' => $value['value_index'],
                    'label' => 'Value ' . $value['value_index'] // You can enhance this
                ];
            }
            $attributeValueSets[] = $valueSet;

            // Get attribute code - you might need to fetch this from Magento
            $attributeCodes[] = 'attribute_' . $attributeId;
        }

        // Generate cartesian product
        $combinations = $this->cartesianProductForVariants($attributeValueSets);

        // Create variants from combinations
        foreach ($combinations as $index => $combination) {
            $variantSku = $baseSku . '-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);

            $variantName = $baseName;
            $configAttributes = [];

            foreach ($combination as $idx => $value) {
                $variantName .= ' - ' . ($value['label'] ?? $value['value_index']);
                $configAttributes[$attributeCodes[$idx]] = $value['value_index'];
            }

            $variants[] = [
                'sku' => $variantSku,
                'name' => $variantName,
                'price' => $basePrice,
                'quantity' => 0,
                'weight' => $baseWeight,
                'configurable_attributes' => $configAttributes
            ];
        }

        return $variants;
    }

    /**
     * Helper for cartesian product
     */
    protected function cartesianProductForVariants(array $arrays): array
    {
        if (empty($arrays)) {
            return [[]];
        }

        $result = [[]];
        foreach ($arrays as $array) {
            $temp = [];
            foreach ($result as $existing) {
                foreach ($array as $item) {
                    $temp[] = array_merge($existing, [$item]);
                }
            }
            $result = $temp;
        }

        return $result;
    }


    /**
     * Create Grouped Product
     */
    public function createGroupedProduct(array $formData): array
    {
        try {
            // Create parent grouped product
            $payload = $this->buildGroupedParentPayload($formData);
            $product = $this->magento->post('products', $payload);
            $sku = $product['sku'];

            // Assign grouped links
            if (!empty($formData['grouped_links'])) {
                $this->assignGroupedLinks($sku, $formData['grouped_links']);
            }

            // Post-processing
            if (!empty($formData['category_ids'])) {
                $this->assignCategories($sku, $formData['category_ids']);
            }

            if (!empty($formData['product_links'])) {
                $this->assignProductLinks($sku, $formData['product_links']);
            }

            return [
                'success' => true,
                'message' => 'Grouped product created successfully',
                'product' => $product,
                'sku' => $sku,
            ];
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            Log::error('Grouped Product Creation Failed: ' . $e->getMessage());
            throw new Exception('Grouped product creation failed: ' . $filteredMessage);
        }
    }

    /**
     * Create Bundle Product
     */
    public function createBundleProduct(array $formData): array
    {
        try {
            $payload = $this->buildBundleParentPayload($formData);
            $product = $this->magento->post('products', $payload);
            $sku = $product['sku'];

            // Add bundle options
            if (!empty($formData['bundle_options'])) {
                $this->addBundleOptions($sku, $formData['bundle_options']);
            }

            // Post-processing
            if (!empty($formData['category_ids'])) {
                $this->assignCategories($sku, $formData['category_ids']);
            }

            return [
                'success' => true,
                'message' => 'Bundle product created successfully',
                'product' => $product,
                'sku' => $sku,
            ];
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            Log::error('Bundle Product Creation Failed: ' . $e->getMessage());
            throw new Exception('Bundle product creation failed: ' . $filteredMessage);
        }
    }

    /**
     * Create Downloadable Product
     */
    public function createDownloadableProduct(array $formData): array
    {
        try {
            $payload = $this->buildDownloadablePayload($formData);
            $product = $this->magento->post('products', $payload);
            $sku = $product['sku'];

            // Add downloadable links and samples
            if (!empty($formData['downloadable_links'])) {
                $this->addDownloadableLinks($sku, $formData['downloadable_links']);
            }

            if (!empty($formData['downloadable_samples'])) {
                $this->addDownloadableSamples($sku, $formData['downloadable_samples']);
            }

            // Post-processing
            if (!empty($formData['category_ids'])) {
                $this->assignCategories($sku, $formData['category_ids']);
            }

            return [
                'success' => true,
                'message' => 'Downloadable product created successfully',
                'product' => $product,
                'sku' => $sku,
            ];
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            Log::error('Downloadable Product Creation Failed: ' . $e->getMessage());
            throw new Exception('Downloadable product creation failed: ' . $filteredMessage);
        }
    }

    /**
     * Create Gift Card Product
     */
    public function createGiftCardProduct(array $formData): array
    {
        try {
            $payload = $this->buildGiftCardPayload($formData);
            $product = $this->magento->post('products', $payload);
            $sku = $product['sku'];

            // Add gift card amounts if fixed
            if ($formData['giftcard_amount_type'] === 'fixed' && !empty($formData['giftcard_amounts'])) {
                $this->addGiftCardAmounts($sku, $formData['giftcard_amounts']);
            }

            if (!empty($formData['category_ids'])) {
                $this->assignCategories($sku, $formData['category_ids']);
            }

            return [
                'success' => true,
                'message' => 'Gift card product created successfully',
                'product' => $product,
                'sku' => $sku,
            ];
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            Log::error('Gift Card Product Creation Failed: ' . $e->getMessage());
            throw new Exception('Gift card product creation failed: ' . $filteredMessage);
        }
    }

    // ============ CONFIGURABLE HELPER METHODS ============

    protected function createConfigurableChild(string $parentSku, array $childData): string
    {
        $childSku = $childData['sku'];

        // Build custom attributes array
        $customAttributes = $this->buildCustomAttributes($childData);

        // Add configurable attributes as custom attributes on the child product
        if (!empty($childData['configurable_attributes'])) {
            foreach ($childData['configurable_attributes'] as $attributeCode => $value) {
                $customAttributes[] = [
                    'attribute_code' => $attributeCode,
                    'value' => (string) $value
                ];
            }
        }

        $mediaGalleryEntries = $this->buildMediaGalleryEntries($childData);
        $quantity = $childData['quantity'] ?? 0;

        // FIX: The payload structure was incorrect
        $payload = [
            'product' => [  // This wrapper was missing
                'sku' => $childSku,
                'name' => $childData['name'] ?? "{$parentSku} Variant",
                'attribute_set_id' => (int)($childData['attribute_set_id'] ?? 4),
                'price' => (float)($childData['price'] ?? 0),
                'status' => 1,
                'visibility' => 1, // Not visible individually
                'type_id' => 'simple',
                'weight' => isset($childData['weight']) ? (float) $childData['weight'] : null,
                'extension_attributes' => [
                    'website_ids' => $childData['website_ids'] ?? [1],
                    'stock_item' => [
                        'qty' => (int) $quantity,
                        'is_in_stock' => $quantity > 0 ? 1 : 0,
                        'manage_stock' => 1,
                        'use_config_manage_stock' => 0,
                    ],
                ],
                'custom_attributes' => $customAttributes,
            ],
        ];

        if (!empty($mediaGalleryEntries)) {
            $payload['product']['media_gallery_entries'] = $mediaGalleryEntries;
        }

        if (in_array($childData['type_id'] ?? 'simple', ['virtual', 'downloadable'])) {
            unset($payload['product']['weight']);
            unset($payload['product']['extension_attributes']['stock_item']);
        }

        // Log the payload for debugging
        \Log::info('Creating configurable child', [
            'child_sku' => $childSku,
            'parent_sku' => $parentSku,
            'configurable_attributes' => $childData['configurable_attributes'] ?? [],
            'payload' => $payload
        ]);

        $this->magento->post('products', $payload);

        return $childSku;
    }

    protected function buildConfigurableParentPayload(array $data): array
    {
        $payload = [
            'product' => [  // This wrapper was missing
                'sku' => $data['sku'],
                'name' => $data['name'],
                'attribute_set_id' => (int)($data['attribute_set_id'] ?? 4),
                'status' => (int)($data['status'] ?? 1),
                'visibility' => (int)($data['visibility'] ?? 4),
                'type_id' => 'configurable',
                'price' => (float)($data['price'] ?? 0),
                'extension_attributes' => [
                    'website_ids' => $data['website_ids'] ?? [1],
                ],
                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        return $payload;
    }

    protected function addConfigurableOptions(string $parentSku, array $configOptions): void
    {
        foreach ($configOptions as $index => $option) {

            // Guard against malformed options
            if (empty($option['attribute_id'])) {
                throw new \Exception("configurable_options[{$index}] is missing 'attribute_id'");
            }
            if (!isset($option['label'])) {
                throw new \Exception("configurable_options[{$index}] is missing 'label'");
            }
            if (empty($option['values'])) {
                throw new \Exception("configurable_options[{$index}] is missing 'values'");
            }

            $payload = [
                'option' => [
                    'attribute_id'   => (int) $option['attribute_id'],
                    'label'          => $option['label'],
                    'position'       => (int) ($option['position'] ?? $index),
                    'is_use_default' => true,
                    'values'         => array_map(fn($v) => [
                        'value_index' => (int) $v['value_index'],
                    ], $option['values']),
                ],
            ];

            \Log::info("Adding configurable option [{$index}] to {$parentSku}", [
                'attribute_id' => $option['attribute_id'],
                'label'        => $option['label'],
                'values_count' => count($option['values']),
            ]);

            $this->magento->post("configurable-products/{$parentSku}/options", $payload);
        }
    }

    protected function linkChildToParent(string $parentSku, string $childSku): void
    {
        $this->magento->post("configurable-products/{$parentSku}/child", ['childSku' => $childSku]);
    }
    protected function cleanupFailedConfigurable(?string $parentSku, array $childSkus): void
    {
        // Delete children first (parent can't be deleted while children are linked)
        foreach ($childSkus as $childSku) {
            try {
                $this->magento->delete("products/{$childSku}");
            } catch (\Exception $e) {
                Log::warning("Cleanup: failed to delete child {$childSku}: " . $e->getMessage());
            }
        }

        // Then delete parent
        if ($parentSku) {
            try {
                $this->magento->delete("products/{$parentSku}");
            } catch (\Exception $e) {
                Log::warning("Cleanup: failed to delete parent {$parentSku}: " . $e->getMessage());
            }
        }
    }
    // ============ GROUPED HELPER METHODS ============

    protected function buildGroupedParentPayload(array $data): array
    {
        $payload = [
            'product' => [
                'sku' => $data['sku'],
                'name' => $data['name'],
                'attribute_set_id' => (int)($data['attribute_set_id'] ?? 4),
                'status' => (int)($data['status'] ?? 1),
                'visibility' => (int)($data['visibility'] ?? 4),
                'type_id' => 'grouped',
                'extension_attributes' => [
                    'website_ids' => $data['website_ids'] ?? [1],
                ],
                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        return $payload;
    }

    protected function assignGroupedLinks(string $sku, array $links): void
    {
        $groupedLinks = array_map(function ($link, $index) {
            return [
                'sku' => $link['linked_sku'],
                'position' => $link['position'] ?? $index,
                'qty' => (float)($link['qty'] ?? 1),
            ];
        }, $links, array_keys($links));

        $payload = ['links' => $groupedLinks];
        $this->magento->post("products/{$sku}/grouped-products", $payload);
    }

    // ============ BUNDLE HELPER METHODS ============

    protected function buildBundleParentPayload(array $data): array
    {
        $payload = [
            'product' => [
                'sku' => $data['sku'],
                'name' => $data['name'],
                'attribute_set_id' => (int)($data['attribute_set_id'] ?? 4),
                'status' => (int)($data['status'] ?? 1),
                'visibility' => (int)($data['visibility'] ?? 4),
                'type_id' => 'bundle',
                'price' => (float)($data['price'] ?? 0),
                'extension_attributes' => [
                    'website_ids' => $data['website_ids'] ?? [1],
                    'bundle_product_options' => [],
                ],
                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        // Add bundle-specific custom attributes
        $bundleAttributes = [];

        if (isset($data['bundle_price_type'])) {
            $bundleAttributes[] = [
                'attribute_code' => 'price_type',
                'value' => $data['bundle_price_type'] === 'dynamic' ? '0' : '1',
            ];
        }

        if (isset($data['bundle_sku_type'])) {
            $bundleAttributes[] = [
                'attribute_code' => 'sku_type',
                'value' => $data['bundle_sku_type'] === 'dynamic' ? '0' : '1',
            ];
        }

        if (isset($data['bundle_shipping_type'])) {
            $bundleAttributes[] = [
                'attribute_code' => 'shipment_type',
                'value' => $data['bundle_shipping_type'] === 'together' ? '0' : '1',
            ];
        }

        if (!empty($bundleAttributes)) {
            $payload['product']['custom_attributes'] = array_merge(
                $payload['product']['custom_attributes'],
                $bundleAttributes
            );
        }

        return $payload;
    }

    protected function addBundleOptions(string $sku, array $options): void
    {
        foreach ($options as $option) {
            $payload = [
                'option' => [
                    'title' => $option['title'],
                    'required' => (bool)($option['required'] ?? true),
                    'type' => $option['type'] ?? 'select',
                    'position' => (int)($option['position'] ?? 0),
                    'sku' => $option['sku'] ?? '',
                    'product_links' => array_map(function ($link) {
                        return [
                            'sku' => $link['sku'],
                            'qty' => (float)($link['qty'] ?? 1),
                            'price' => (float)($link['price'] ?? 0),
                            'price_type' => $link['price_type'] ?? 'fixed',
                            'can_change_quantity' => (bool)($link['can_change_quantity'] ?? false),
                            'position' => (int)($link['position'] ?? 0),
                            'is_default' => (bool)($link['is_default'] ?? false),
                        ];
                    }, $option['product_links'] ?? []),
                ],
            ];

            $this->magento->post("basket-products/{$sku}/options", $payload);
        }
    }

    // ============ DOWNLOADABLE HELPER METHODS ============

    protected function buildDownloadablePayload(array $data): array
    {
        $payload = [
            'product' => [
                'sku' => $data['sku'],
                'name' => $data['name'],
                'attribute_set_id' => (int)($data['attribute_set_id'] ?? 4),
                'status' => (int)($data['status'] ?? 1),
                'visibility' => (int)($data['visibility'] ?? 4),
                'type_id' => 'downloadable',
                'price' => (float)($data['price'] ?? 0),
                'extension_attributes' => [
                    'website_ids' => $data['website_ids'] ?? [1],
                ],
                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        // Add downloadable-specific custom attributes
        $downloadableAttributes = [];

        if (isset($data['links_purchased_separately'])) {
            $downloadableAttributes[] = [
                'attribute_code' => 'links_purchased_separately',
                'value' => $data['links_purchased_separately'] ? '1' : '0',
            ];
        }

        if (isset($data['links_title'])) {
            $downloadableAttributes[] = [
                'attribute_code' => 'links_title',
                'value' => $data['links_title'],
            ];
        }

        if (isset($data['samples_title'])) {
            $downloadableAttributes[] = [
                'attribute_code' => 'samples_title',
                'value' => $data['samples_title'],
            ];
        }

        if (!empty($downloadableAttributes)) {
            $payload['product']['custom_attributes'] = array_merge(
                $payload['product']['custom_attributes'],
                $downloadableAttributes
            );
        }

        return $payload;
    }

    protected function addDownloadableLinks(string $sku, array $links): void
    {
        $payload = [
            'link' => [
                'title' => $links[0]['title'] ?? 'Download',
                'price' => 0,
                'is_shareable' => 1,
                'sample' => [
                    'type' => 'file',
                ],
            ],
            'links' => array_map(function ($link, $index) {
                return [
                    'title' => $link['title'],
                    'sort_order' => $link['sort_order'] ?? $index,
                    'is_shareable' => $link['is_shareable'] ?? 1,
                    'price' => (float)($link['price'] ?? 0),
                    'number_of_downloads' => (int)($link['number_of_downloads'] ?? 0),
                    'link_type' => $link['link_type'] ?? 'file',
                    'link_file' => $link['link_type'] === 'file' ? ($link['link_file'] ?? '') : null,
                    'link_url' => $link['link_type'] === 'url' ? ($link['link_url'] ?? '') : null,
                    'sample_type' => $link['sample_type'] ?? 'file',
                    'sample_file' => $link['sample_type'] === 'file' ? ($link['sample_file'] ?? '') : null,
                    'sample_url' => $link['sample_type'] === 'url' ? ($link['sample_url'] ?? '') : null,
                ];
            }, $links, array_keys($links)),
        ];

        $this->magento->post("products/{$sku}/downloadable-links", $payload);
    }

    protected function addDownloadableSamples(string $sku, array $samples): void
    {
        $payload = [
            'samples' => array_map(function ($sample, $index) {
                return [
                    'title' => $sample['title'],
                    'sort_order' => $sample['sort_order'] ?? $index,
                    'sample_type' => $sample['sample_type'] ?? 'file',
                    'sample_file' => $sample['sample_type'] === 'file' ? ($sample['sample_file'] ?? '') : null,
                    'sample_url' => $sample['sample_type'] === 'url' ? ($sample['sample_url'] ?? '') : null,
                ];
            }, $samples, array_keys($samples)),
        ];

        $this->magento->post("products/{$sku}/downloadable-links/samples", $payload);
    }

    // ============ GIFT CARD HELPER METHODS ============

    protected function buildGiftCardPayload(array $data): array
    {
        $payload = [
            'product' => [
                'sku' => $data['sku'],
                'name' => $data['name'],
                'attribute_set_id' => (int)($data['attribute_set_id'] ?? 4),
                'status' => (int)($data['status'] ?? 1),
                'visibility' => (int)($data['visibility'] ?? 4),
                'type_id' => 'giftcard',
                'price' => (float)($data['price'] ?? 0),
                'extension_attributes' => [
                    'website_ids' => $data['website_ids'] ?? [1],
                ],
                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        // Add gift card-specific custom attributes
        $giftCardAttributes = [];

        if (isset($data['giftcard_type'])) {
            $giftCardAttributes[] = [
                'attribute_code' => 'giftcard_type',
                'value' => $data['giftcard_type'],
            ];
        }

        if (isset($data['giftcard_amount_type'])) {
            $giftCardAttributes[] = [
                'attribute_code' => 'giftcard_amount_type',
                'value' => $data['giftcard_amount_type'],
            ];
        }

        if ($data['giftcard_amount_type'] === 'dynamic') {
            if (isset($data['giftcard_open_amount_min'])) {
                $giftCardAttributes[] = [
                    'attribute_code' => 'giftcard_open_amount_min',
                    'value' => (string) $data['giftcard_open_amount_min'],
                ];
            }
            if (isset($data['giftcard_open_amount_max'])) {
                $giftCardAttributes[] = [
                    'attribute_code' => 'giftcard_open_amount_max',
                    'value' => (string) $data['giftcard_open_amount_max'],
                ];
            }
        }

        if (isset($data['allow_message'])) {
            $giftCardAttributes[] = [
                'attribute_code' => 'allow_message',
                'value' => $data['allow_message'] ? '1' : '0',
            ];
        }

        if (isset($data['gift_message_max_length'])) {
            $giftCardAttributes[] = [
                'attribute_code' => 'gift_message_max_length',
                'value' => (string) $data['gift_message_max_length'],
            ];
        }

        if (!empty($giftCardAttributes)) {
            $payload['product']['custom_attributes'] = array_merge(
                $payload['product']['custom_attributes'],
                $giftCardAttributes
            );
        }

        return $payload;
    }

    protected function addGiftCardAmounts(string $sku, array $amounts): void
    {
        foreach ($amounts as $amount) {
            $payload = [
                'giftCardAmount' => [
                    'website_id' => (int)($amount['website_id'] ?? 0),
                    'value' => (float)$amount['value'],
                ],
            ];
            $this->magento->post("giftcards/{$sku}/amounts", $payload);
        }
    }

    // ============ CORE HELPER METHODS ============

    protected function createCoreProduct(array $data): array
    {
        $quantity = $data['quantity'] ?? 0;
        $categoryLinks = [];

        if (!empty($data['category_ids'])) {
            foreach ($data['category_ids'] as $index => $catId) {
                $categoryLinks[] = [
                    'position' => $index,
                    'category_id' => (string) $catId,
                    'extension_attributes' => new \stdClass(),
                ];
            }
        }

        $mediaGalleryEntries = $this->buildMediaGalleryEntries($data);

        $payload = [
            'product' => [
                'sku' => $data['sku'],
                'name' => $data['name'],
                'attribute_set_id' => (int)($data['attribute_set_id'] ?? 4),
                'price' => (float)($data['price'] ?? 0),
                'status' => (int)($data['status'] ?? 1),
                'visibility' => (int)($data['visibility'] ?? 4),
                'type_id' => $data['type_id'] ?? 'simple',
                'weight' => isset($data['weight']) ? (float) $data['weight'] : null,
                'extension_attributes' => [
                    'website_ids' => $data['website_ids'] ?? [1],
                    'category_links' => $categoryLinks,
                    'stock_item' => [
                        'qty' => $quantity,
                        'is_in_stock' => $quantity > 0 ? 1 : 0,
                        'manage_stock' => isset($data['manage_stock']) ? (int) $data['manage_stock'] : 1,
                        'use_config_manage_stock' => 0,
                        'backorders' => (int)($data['backorders'] ?? 0),
                        'use_config_backorders' => 0,
                        'notify_stock_qty' => (int)($data['notify_stock_qty'] ?? 0),
                        'use_config_notify_stock_qty' => 0,
                        'min_sale_qty' => (int)($data['min_sale_qty'] ?? 1),
                        'use_config_min_sale_qty' => 0,
                        'max_sale_qty' => (int)($data['max_sale_qty'] ?? 10000),
                        'use_config_max_sale_qty' => 0,
                        'qty_increments' => (int)($data['qty_increments'] ?? 1),
                        'use_config_qty_increments' => 0,
                        'enable_qty_increments' => isset($data['enable_qty_increments']) ? (int) $data['enable_qty_increments'] : 0,
                    ],
                ],
                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        if (!empty($mediaGalleryEntries)) {
            $payload['product']['media_gallery_entries'] = $mediaGalleryEntries;
        }

        if (in_array($data['type_id'] ?? 'simple', ['virtual', 'downloadable', 'giftcard'])) {
            unset($payload['product']['weight']);
            unset($payload['product']['extension_attributes']['stock_item']);
        }

        return $this->magento->post('products', $payload);
    }

    protected function buildCustomAttributes(array $data): array
    {
        $customAttributes = [];

        $attributeMap = [
            'description' => 'description',
            'short_description' => 'short_description',
            'meta_title' => 'meta_title',
            'meta_description' => 'meta_description',
            'meta_keyword' => 'meta_keyword',
            'url_key' => 'url_key',
            'special_price' => 'special_price',
            'special_from_date' => 'special_from_date',
            'special_to_date' => 'special_to_date',
            'cost' => 'cost',
            'msrp' => 'msrp',
            'msrp_display_actual_price_type' => 'msrp_display_actual_price_type',
            'gift_message_available' => 'gift_message_available',
            'tax_class_id' => 'tax_class_id',
            'custom_design' => 'custom_design',
            'page_layout' => 'page_layout',
            'custom_layout_update' => 'custom_layout_update',
            'news_from_date' => 'news_from_date',
            'news_to_date' => 'news_to_date',
            'country_of_manufacture' => 'country_of_manufacture',
        ];

        foreach ($attributeMap as $inputField => $attributeCode) {
            if (isset($data[$inputField]) && $data[$inputField] !== null && $data[$inputField] !== '') {
                $value = $data[$inputField];
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (is_int($value)) {
                    $value = (string) $value;
                } elseif (is_float($value)) {
                    $value = number_format($value, 2, '.', '');
                }
                $customAttributes[] = [
                    'attribute_code' => $attributeCode,
                    'value' => $value,
                ];
            }
        }

        if (!empty($data['dynamic_attributes'])) {
            foreach ($data['dynamic_attributes'] as $code => $value) {
                if ($value !== null && $value !== '') {
                    $customAttributes[] = [
                        'attribute_code' => $code,
                        'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                    ];
                }
            }
        }

        if (!isset($data['url_key']) && isset($data['name'])) {
            $customAttributes[] = [
                'attribute_code' => 'url_key',
                'value' => $this->generateUrlKey($data['name']),
            ];
        }

        return $customAttributes;
    }

    protected function buildMediaGalleryEntries(array $data): array
    {
        $mediaGalleryEntries = [];
        $mediaItems = $data['media'] ?? $data['media_gallery'] ?? [];

        if (empty($mediaItems)) {
            return [];
        }

        foreach ($mediaItems as $index => $mediaItem) {
            if (!isset($mediaItem['content']['base64_encoded_data'])) {
                continue;
            }

            $base64Data = $mediaItem['content']['base64_encoded_data'];
            if (strpos($base64Data, 'base64,') !== false) {
                $base64Data = substr($base64Data, strpos($base64Data, 'base64,') + 7);
            }

            $originalName = $mediaItem['content']['name'] ?? 'image.jpg';
            $sanitizedName = $this->sanitizeFilename($originalName);

            $types = $mediaItem['types'] ?? [];
            if (empty($types) && $index === 0) {
                $types = ['image', 'small_image', 'thumbnail', 'swatch_image'];
            }

            $mediaGalleryEntries[] = [
                'id' => 0,
                'media_type' => $mediaItem['media_type'] ?? 'image',
                'label' => $mediaItem['label'] ?? pathinfo($sanitizedName, PATHINFO_FILENAME),
                'position' => (int)($mediaItem['position'] ?? $index + 1),
                'disabled' => (bool)($mediaItem['disabled'] ?? false),
                'types' => $types,
                'content' => [
                    'base64_encoded_data' => $base64Data,
                    'type' => $mediaItem['content']['type'] ?? 'image/jpeg',
                    'name' => $sanitizedName,
                ],
            ];
        }

        return $mediaGalleryEntries;
    }

    // ============ SHARED HELPER METHODS ============

    public function assignCategories(string $sku, array $categoryIds): void
    {
        foreach ($categoryIds as $index => $catId) {
            $payload = [
                'category_links' => [
                    'sku' => $sku,
                    'category_id' => (string) $catId,
                    'position' => $index,
                ],
            ];
            try {
                $this->magento->post('categories/products', $payload);
            } catch (\Exception $e) {
                Log::warning("Category {$catId} assignment failed for SKU {$sku}: " . $e->getMessage());
            }
        }
    }

    public function assignProductLinks(string $sku, array $links): void
    {
        $typeMap = [
            'related' => 'related',
            'up-sell' => 'upsell',
            'upsell' => 'upsell',
            'cross-sell' => 'crosssell',
            'crosssell' => 'crosssell',
        ];

        $items = [];
        foreach ($links as $index => $link) {
            $apiType = $typeMap[$link['link_type'] ?? 'related'] ?? 'related';
            $linkedSku = $link['linked_sku'] ?? $link['linked_product_sku'] ?? null;

            if (!$linkedSku) {
                continue;
            }

            $items[] = [
                'sku' => $sku,
                'link_type' => $apiType,
                'linked_product_sku' => $linkedSku,
                'linked_product_type' => $link['linked_type'] ?? 'simple',
                'position' => $index + 1,
                'extension_attributes' => ['qty' => 0],
            ];
        }

        if (!empty($items)) {
            $this->magento->post("products/{$sku}/links", ['items' => $items]);
        }
    }

    public function addCustomOptions(string $sku, array $options): void
    {
        $validTypes = ['drop_down', 'radio', 'checkbox', 'multiple', 'field', 'area', 'file', 'date', 'date_time', 'time'];

        foreach ($options as $opt) {
            if (!in_array($opt['type'], $validTypes)) {
                continue;
            }

            $entry = [
                'product_sku' => $sku,
                'title' => $opt['title'],
                'type' => $opt['type'],
                'is_require' => (bool)($opt['is_required'] ?? false),
                'sort_order' => (int)($opt['sort_order'] ?? 0),
            ];

            if (in_array($opt['type'], ['drop_down', 'radio', 'checkbox', 'multiple']) && !empty($opt['values'])) {
                $entry['values'] = array_map(function ($v) {
                    return [
                        'title' => $v['title'],
                        'price' => (float)($v['price'] ?? 0),
                        'price_type' => $v['price_type'] ?? 'fixed',
                        'sku' => $v['sku'] ?? '',
                        'sort_order' => (int)($v['sort_order'] ?? 0),
                    ];
                }, $opt['values']);
            } else {
                $entry['price'] = (float)($opt['price'] ?? 0);
                $entry['price_type'] = $opt['price_type'] ?? 'fixed';
            }

            $this->magento->post('products/options', ['option' => $entry]);
        }
    }

    public function setTierPrices(string $sku, array $tiers): void
    {
        $prices = array_map(function ($t) use ($sku) {
            return [
                'sku' => $sku,
                'price' => (float) $t['price'],
                'price_type' => $t['price_type'] ?? 'fixed',
                'customer_group' => $t['customer_group'] ?? 'ALL GROUPS',
                'quantity' => (float) ($t['quantity'] ?? 1),
                'website_id' => (int) ($t['website_id'] ?? 0),
            ];
        }, $tiers);

        if (!empty($prices)) {
            $this->magento->post("products/tier-prices", ['prices' => $prices]);
        }
    }

    public function assignMSIInventory(string $sku, array $inventory): void
    {
        $sourceItems = [[
            'sku' => $sku,
            'source_code' => $inventory['source_code'] ?? 'default',
            'quantity' => (float) ($inventory['quantity'] ?? 0),
            'status' => (int) ($inventory['status'] ?? 1),
        ]];

        $this->magento->post('inventory/source-items', ['sourceItems' => $sourceItems]);
    }

    protected function sanitizeFilename(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $basename = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $basename);
        $basename = preg_replace('/-+/', '-', $basename);
        $basename = trim($basename, '-');

        if (empty($basename)) {
            $basename = 'image';
        }

        $basename = $basename . '_' . time() . '_' . rand(100, 999);

        if (!empty($extension)) {
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $extension = strtolower($extension);
            if (!in_array($extension, $allowedExtensions)) {
                $extension = 'jpg';
            }
            return $basename . '.' . $extension;
        }

        return $basename . '.jpg';
    }

    protected function generateUrlKey(string $name): string
    {
        $key = strtolower($name);
        $key = preg_replace('/[^a-z0-9-]/', '-', $key);
        $key = preg_replace('/-+/', '-', $key);
        return trim($key, '-');
    }


    /**
     * Get all product attributes from Magento
     * This is a wrapper that passes the endpoint to MagentoService
     */
    public function getProductAttributes(): array
    {
        try {
            // Get all query parameters from the request
            $queryParams = request()->query();

            // Build parameters array for Magento API
            // Magento expects parameters like: searchCriteria[currentPage], searchCriteria[pageSize], etc.
            $magentoParams = [];

            foreach ($queryParams as $key => $value) {
                // Pass through any searchCriteria parameters as-is
                if (strpos($key, 'searchCriteria') === 0) {
                    $magentoParams[$key] = $value;
                }
            }

            // Set default pagination if not provided
            if (!isset($magentoParams['searchCriteria[currentPage]'])) {
                $magentoParams['searchCriteria[currentPage]'] = 1;
            }
            if (!isset($magentoParams['searchCriteria[pageSize]'])) {
                $magentoParams['searchCriteria[pageSize]'] = 100;
            }

            Log::info('Fetching product attributes from Magento', [
                'vendor_id' => $this->vendor->id,
                'params' => $magentoParams
            ]);

            // Call Magento API with parameters
            $response = $this->magento->get('products/attributes', $magentoParams);

            // Return items if they exist, otherwise return empty array
            if (isset($response['items'])) {
                return $response['items'];
            }

            return $response;
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            Log::error('Failed to fetch product attributes from Magento', [
                'error' => $e->getMessage(),
                'vendor_id' => $this->vendor->id,
                'params' => $magentoParams ?? []
            ]);
            throw new Exception('Failed to fetch product attributes: ' . $filteredMessage);
        }
    }

    /**
     * Get specific attribute details by ID
     */
    public function getAttribute(int $attributeId): array
    {
        try {
            // Pass the specific attribute endpoint
            return $this->magento->get("products/attributes/{$attributeId}");
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            Log::error("Failed to fetch attribute {$attributeId} from Magento", [
                'error' => $e->getMessage(),
                'vendor_id' => $this->vendor->id
            ]);
            throw new Exception("Failed to fetch attribute: " . $filteredMessage);
        }
    }

    /**
     * Get attribute options (values) for a specific attribute
     * Returns all possible values for dropdown/select attributes
     */
    public function getAttributeOptions(int $attributeId): array
    {
        try {
            // Pass the attribute options endpoint to MagentoService
            $response = $this->magento->get("products/attributes/{$attributeId}/options");

            // Format options for frontend consumption
            // Magento returns options in format: [['value' => '1', 'label' => 'Red'], ...]
            return array_map(function ($option) {
                return [
                    'value_index' => (int) $option['value'],
                    'value' => $option['label'],
                    'swatch_data' => $option['swatch_data'] ?? null,
                ];
            }, $response ?? []);
        } catch (\Exception $e) {
            Log::warning("Failed to get options for attribute {$attributeId}", [
                'error' => $e->getMessage(),
                'vendor_id' => $this->vendor->id
            ]);

            // Return empty array instead of throwing - attributes might not have options
            return [];
        }
    }

    /**
     * Get configurable attributes only (filtered for configurable product types)
     * Common configurable attributes: color, size, material, style, etc.
     */
    public function getConfigurableAttributes(array $queryParams = []): array
    {
        try {
            // Pass query params to Magento
            $response = $this->magento->get('products/attributes', $queryParams);

            $allAttributes = $response['items'] ?? [];

            // Filter for configurable attributes
            $configurableCodes = ['color', 'size', 'material', 'style', 'brand'];

            $configurableAttributes = array_filter($allAttributes, function ($attr) use ($configurableCodes) {
                return in_array($attr['attribute_code'], $configurableCodes);
            });

            return array_values($configurableAttributes);
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            Log::error('Failed to fetch configurable attributes', [
                'error' => $e->getMessage(),
                'vendor_id' => $this->vendor->id
            ]);
            throw new Exception('Failed to fetch configurable attributes: ' . $filteredMessage);
        }
    }

    /**
     * Get attributes by specific attribute codes
     * Useful for fetching only the attributes you need
     */
    public function getAttributesByCodes(array $attributeCodes): array
    {
        try {
            $allAttributes = $this->getProductAttributes();

            $filteredAttributes = array_filter($allAttributes, function ($attr) use ($attributeCodes) {
                return in_array($attr['attribute_code'], $attributeCodes);
            });

            // Fetch options for each attribute
            foreach ($filteredAttributes as &$attr) {
                $attr['options'] = $this->getAttributeOptions($attr['attribute_id']);
            }

            return array_values($filteredAttributes);
        } catch (\Exception $e) {
            $filteredMessage = $this->extractErrorMessage($e->getMessage());
            Log::error('Failed to fetch attributes by codes', [
                'codes' => $attributeCodes,
                'error' => $e->getMessage(),
                'vendor_id' => $this->vendor->id
            ]);
            throw new Exception('Failed to fetch attributes: ' . $filteredMessage);
        }
    }

    public function updateProduct(string $sku, array $data): array
    {
        $payload = [
            'product' => [
                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        $updatable = ['price', 'status', 'visibility', 'weight', 'tax_class_id'];
        foreach ($updatable as $field) {
            if (isset($data[$field])) {
                $payload['product'][$field] = $data[$field];
            }
        }

        // Add media gallery entries if updating media
        $mediaGalleryEntries = $this->buildMediaGalleryEntries($data);
        if (!empty($mediaGalleryEntries)) {
            $payload['product']['media_gallery_entries'] = $mediaGalleryEntries;
        }

        return $this->magento->put("products/{$sku}", $payload);
    }
    protected function extractErrorMessage(string $exceptionMessage, string $fallbackMessage = 'Product creation failed'): string
    {
        // Try to extract the JSON message part first
        if (preg_match('/{"message":"([^"]+)"/', $exceptionMessage, $matches)) {
            $jsonMessage = $matches[1];
            // Get the first sentence or up to first dot
            $dotPosition = strpos($jsonMessage, '.');
            if ($dotPosition !== false) {
                return substr($jsonMessage, 0, $dotPosition + 1);
            }
            return $jsonMessage;
        }

        // If no JSON found, try to get the first sentence from plain text
        $dotPosition = strpos($exceptionMessage, '.');
        if ($dotPosition !== false && $dotPosition < 200) { // Limit to first 200 chars
            return substr($exceptionMessage, 0, $dotPosition + 1);
        }

        // Fallback: return first 150 characters
        return substr($exceptionMessage, 0, 150) . (strlen($exceptionMessage) > 150 ? '...' : '');
    }

    /**
     * Delete product from Magento by SKU
     */
    public function deleteProduct(string $sku): array
    {
        return $this->magento->delete("products/{$sku}");
    }
}
