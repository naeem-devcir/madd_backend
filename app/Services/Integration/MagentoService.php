<?php

namespace App\Services\Integration;

use App\Models\Product\ProductDraft;
use App\Models\Product\VendorProduct;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MagentoService
{
    protected string $baseUrl;
    protected string $token;
    protected ?Vendor $vendor;

    /**
     * Vendor object pass karo — credentials DB se aayenge
     * Ya manually baseUrl + token pass karo
     */
    public function __construct(Vendor|string|null $vendorOrToken = null, ?string $baseUrl = null)
    {
        if ($vendorOrToken instanceof Vendor) {
            // DB se credentials lo
            $this->vendor  = $vendorOrToken;
            if (empty($vendorOrToken->magento_base_url)) {
                throw new \Exception('Magento base URL is missing for vendor ID: ' . $vendorOrToken->id);
            }

            $this->baseUrl = rtrim($vendorOrToken->magento_base_url, '/');
            $this->token   = $this->resolveToken($vendorOrToken);
        } else {
            // Manual token (fallback / master admin)
            $this->vendor  = null;
            $this->baseUrl = rtrim($baseUrl ?? config('magento.base_url'), '/');
            $this->token   = $vendorOrToken ?? config('magento.access_token', '');
        }
    }

    // ─────────────────────────────────────────────────────
    // TOKEN RESOLUTION
    // ─────────────────────────────────────────────────────

    /**
     * Vendor ka valid token lo
     * DB mein valid token hai to use karo, warna fresh fetch karo
     */
    protected function resolveToken(Vendor $vendor): string
    {
        // 1. Agar access token hai (Integration token — permanent)
        if (!empty($vendor->magento_access_token)) {
            return $vendor->magento_access_token;
        }

        // 2. Agar admin token valid hai (4 hour wala)
        if ($vendor->isMagentoTokenValid()) {
            return $vendor->magento_admin_token;
        }

        if (empty($vendor->magento_admin_username) || empty($vendor->magento_admin_pass)) {
            throw new \Exception('Magento API credentials are missing for vendor ID: ' . $vendor->id);
        }

        // 3. Fresh token fetch karo aur DB mein save karo
        return $this->fetchAndSaveToken($vendor);
    }

    /**
     * Fresh admin token fetch karo aur vendor DB mein save karo
     */
    public function fetchAndSaveToken(Vendor $vendor): string
    {
        $token = $this->fetchAdminToken(
            $vendor->magento_base_url,
            $vendor->magento_admin_username,
            $vendor->magento_admin_pass,
        );

        $vendor->update([
            'magento_admin_token'      => $token,
            'magento_token_expires_at' => now()->addMinutes(210), // 3.5 hours
            'magento_synced_at'        => now(),
        ]);

        return $token;
    }

    /**
     * POST /rest/V1/integration/admin/token
     */
    public function fetchAdminToken(
        ?string $baseUrl = null,
        ?string $username = null,
        ?string $password = null
    ): string {
        $url  = rtrim($baseUrl ?? config('magento.base_url'), '/');
        $user = $username ?? config('magento.admin_user');
        $pass = $password ?? config('magento.admin_pass');

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->timeout(15)
            ->post("{$url}/rest/V1/integration/admin/token", [
                'username' => $user,
                'password' => $pass,
            ]);

        if ($response->failed()) {
            throw new \Exception("Magento token fetch failed [{$url}]: " . $response->body());
        }

        return trim($response->body(), '"');
    }

    // ─────────────────────────────────────────────────────
    // HTTP CLIENT
    // ─────────────────────────────────────────────────────

    private function http()
    {
        $this->ensureToken();

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->timeout(30);
    }

    private function ensureToken(): void
    {
        if (! empty($this->token)) {
            return;
        }

        if ($this->vendor) {
            $this->token = $this->resolveToken($this->vendor);
            return;
        }

        if (empty(config('magento.admin_pass'))) {
            throw new \Exception('Magento token is missing. Set MAGENTO_ACCESS_TOKEN or MAGENTO_ADMIN_PASS.');
        }

        $this->token = $this->fetchAdminToken(
            config('magento.base_url'),
            config('magento.admin_user'),
            config('magento.admin_pass'),
        );
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = "{$this->baseUrl}/rest/V1/{$endpoint}";

        $response = match ($method) {
            'GET'    => $this->http()->get($url, $data),
            'POST'   => $this->http()->post($url, $data),
            'PUT'    => $this->http()->put($url, $data),
            'DELETE' => $this->http()->delete($url),
        };

        // Token expire — refresh karke retry
        if ($response->status() === 401 && $this->vendor) {
            Log::warning("Magento token expired for vendor {$this->vendor->id}, refreshing...");
            $this->token = $this->fetchAndSaveToken($this->vendor);

            $response = match ($method) {
                'GET'    => $this->http()->get($url, $data),
                'POST'   => $this->http()->post($url, $data),
                'PUT'    => $this->http()->put($url, $data),
                'DELETE' => $this->http()->delete($url),
            };
        }

        if ($response->failed()) {
            Log::error("Magento API [{$method} {$endpoint}]", [
                'vendor_id' => $this->vendor?->id,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
            throw new \Exception("Magento [{$endpoint}] failed: " . $response->body());
        }

        $json = $response->json();

        if (is_array($json)) {
            return $json;
        }

        return ['success' => (bool) $json, 'value' => $json];
    }

    private function get(string $ep, array $q = []): array
    {
        return $this->request('GET',    $ep, $q);
    }
    private function post(string $ep, array $b = []): array
    {
        return $this->request('POST',   $ep, $b);
    }
    private function put(string $ep, array $b = []): array
    {
        return $this->request('PUT',    $ep, $b);
    }
    private function delete(string $ep): array
    {
        return $this->request('DELETE', $ep);
    }

    // ─────────────────────────────────────────────────────
    // VENDOR STORE
    // ─────────────────────────────────────────────────────

    public function createVendorStore(Vendor $vendor): array
    {
        $website = $this->post('store/websites', [
            'website' => [
                'code'             => 'vendor_' . $vendor->id . '_' . time(),
                'name'             => $vendor->company_name,
                'default_group_id' => 0,
                'is_default'       => false,
            ]
        ]);

        $websiteId = $website['id'] ?? null;
        if (!$websiteId) {
            throw new \Exception('Website creation failed: ' . json_encode($website));
        }

        $category = $this->post('categories', [
            'category' => [
                'parent_id'       => 2,
                'name'            => $vendor->company_name,
                'is_active'       => true,
                'include_in_menu' => false,
            ]
        ]);

        $rootCategoryId = $category['id'] ?? 2;

        $storeGroup = $this->post('store/storeGroups', [
            'storeGroup' => [
                'website_id'       => $websiteId,
                'name'             => $vendor->company_name,
                'code'             => 'vendor_group_' . $vendor->id,
                'root_category_id' => $rootCategoryId,
            ]
        ]);

        $vendor->update([
            'magento_website_id'       => $websiteId,
            'magento_store_group_id'   => $storeGroup['id'] ?? null,
            'magento_root_category_id' => $rootCategoryId,
            'magento_synced_at'        => now(),
        ]);

        return [
            'website_id'       => $websiteId,
            'store_group_id'   => $storeGroup['id'] ?? null,
            'root_category_id' => $rootCategoryId,
        ];
    }

    public function createStoreView(Vendor $vendor, VendorStore $store): array
    {
        return $this->createStoreViewFromData($vendor, [
            'store_name' => $store->store_name,
            'store_slug' => $store->store_slug,
            'status' => $store->status,
        ]);
    }

    public function createStoreGroupFromData(Vendor $vendor, array $data): array
    {
        if (empty($vendor->magento_website_id)) {
            throw new \Exception('Magento website ID is not configured for vendor ID: ' . $vendor->id);
        }

        $response = $this->post('store/storeGroups', [
            'storeGroup' => [
                'website_id' => (int) $vendor->magento_website_id,
                'name' => $data['store_name'],
                'code' => $this->storeGroupCodeFromData($data),
                'root_category_id' => (int) ($data['magento_root_category_id'] ?? $vendor->magento_root_category_id ?? 2),
            ],
        ]);

        $storeGroupId = $response['id'] ?? $response['value'] ?? null;

        return [
            'store_id' => $storeGroupId,
            'store_group_id' => $storeGroupId,
            'website_id' => $vendor->magento_website_id,
            'root_category_id' => $data['magento_root_category_id'] ?? $vendor->magento_root_category_id ?? 2,
            'response' => $response,
            'magento_entity' => 'store_group',
        ];
    }

    public function updateStoreGroup(VendorStore $store): array
    {
        $storeGroupId = $store->magento_store_group_id ?: $store->magento_store_id;

        if (empty($storeGroupId)) {
            throw new \Exception('Magento store group ID is missing for this store.');
        }

        $response = $this->put('store/storeGroups/' . $storeGroupId, [
            'storeGroup' => [
                'id' => (int) $storeGroupId,
                'website_id' => (int) $store->magento_website_id,
                'name' => $store->store_name,
                'code' => $this->storeGroupCodeFromData([
                    'store_slug' => $store->store_slug,
                    'store_name' => $store->store_name,
                ]),
                'root_category_id' => (int) data_get($store->metadata, 'magento.root_category_id', $store->vendor->magento_root_category_id ?? 2),
            ],
        ]);

        return [
            'store_id' => $storeGroupId,
            'store_group_id' => $storeGroupId,
            'website_id' => $store->magento_website_id,
            'response' => $response,
            'magento_entity' => 'store_group',
        ];
    }

    public function deleteStoreGroup(VendorStore $store): array
    {
        $storeGroupId = $store->magento_store_group_id ?: $store->magento_store_id;

        if (empty($storeGroupId)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'missing_magento_store_group_id'];
        }

        return $this->deleteStoreGroupById((int) $storeGroupId);
    }

    public function deleteStoreGroupById(int $storeGroupId): array
    {
        $response = $this->delete('store/storeGroups/' . $storeGroupId);

        return [
            'success' => true,
            'store_group_id' => $storeGroupId,
            'response' => $response,
            'magento_entity' => 'store_group',
        ];
    }

    public function createStoreViewFromData(Vendor $vendor, array $data): array
    {
        if (empty($vendor->magento_website_id) || empty($vendor->magento_store_group_id)) {
            throw new \Exception('Magento website/store group is not configured for vendor ID: ' . $vendor->id);
        }

        $response = $this->post('store/storeViews', [
            'storeView' => [
                'code'           => $this->storeViewCodeFromData($data),
                'name'           => $data['store_name'],
                'website_id'     => $vendor->magento_website_id,
                'store_group_id' => $vendor->magento_store_group_id,
                'is_active'      => ($data['status'] ?? 'inactive') === 'active',
            ]
        ]);

        return [
            'store_id'       => $response['id'] ?? $response['value'] ?? null,
            'store_group_id' => $vendor->magento_store_group_id,
            'website_id'     => $vendor->magento_website_id,
            'response'       => $response,
        ];
    }

    public function updateStoreView(VendorStore $store): array
    {
        if (empty($store->magento_store_id)) {
            throw new \Exception('Magento store ID is missing for this store.');
        }

        return $this->put('store/storeViews/' . $store->magento_store_id, [
            'storeView' => [
                'id'             => (int) $store->magento_store_id,
                'code'           => $this->storeViewCode($store),
                'name'           => $store->store_name,
                'website_id'     => (int) $store->magento_website_id,
                'store_group_id' => (int) $store->magento_store_group_id,
                'is_active'      => $store->status === 'active',
            ],
        ]);
    }

    public function deleteStoreView(VendorStore $store): array
    {
        if (empty($store->magento_store_id)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'missing_magento_store_id'];
        }

        return $this->deleteStoreViewById((int) $store->magento_store_id);
    }

    public function deleteStoreViewById(int $magentoStoreId): array
    {
        try {
            return $this->delete('store/storeViews/' . $magentoStoreId);
        } catch (\Throwable $e) {
            Log::warning('Magento store view delete failed; deactivating instead', [
                'magento_store_id' => $magentoStoreId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function getStoreViews(): array
    {
        return $this->get('store/storeViews');
    }

    public function getStoreGroups(): array
    {
        return $this->get('store/storeGroups');
    }

    public function getWebsites(): array
    {
        return $this->get('store/websites');
    }

    private function storeViewCode(VendorStore $store): string
    {
        return $this->storeViewCodeFromData([
            'store_slug' => $store->store_slug,
            'store_name' => $store->store_name,
        ]);
    }

    private function storeViewCodeFromData(array $data): string
    {
        $source = $data['store_slug'] ?? $data['store_name'] ?? 'store';
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($source));
        $slug = trim($slug ?: 'store', '_');

        return 'store_' . $slug . '_' . substr(md5($slug), 0, 8);
    }

    private function storeGroupCodeFromData(array $data): string
    {
        $source = $data['store_slug'] ?? $data['store_name'] ?? 'store';
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($source));
        $slug = trim($slug ?: 'store', '_');

        return 'store_' . $slug . '_' . substr(md5($slug), 0, 8);
    }

    // ─────────────────────────────────────────────────────
    // PRODUCTS / ORDERS / CUSTOMERS
    // ─────────────────────────────────────────────────────

    public function getProducts(int $page = 1, int $size = 20): array
    {
        return $this->get('products', [
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]'    => $size,
        ]);
    }

    public function getProductBySku(string $sku): array
    {
        return $this->get('products/' . rawurlencode($sku));
    }

    public function createProduct(array $data): array
    {
        return $this->post('products', ['product' => $data]);
    }

    public function updateProduct(string $sku, array $data): array
    {
        return $this->put('products/' . rawurlencode($sku), ['product' => $data]);
    }

    public function deleteProduct(string $sku): array
    {
        return $this->delete('products/' . rawurlencode($sku));
    }

    public function createOrUpdateProduct(ProductDraft|VendorProduct $product): array
    {
        $payload = $this->buildProductPayload($product);

        if (! empty($product->magento_product_id) || ! empty($product->magento_sku)) {
            return $this->updateProduct($product->magento_sku ?: $product->sku, $payload);
        }

        return $this->createProduct($payload);
    }

    public function syncStockForProduct(ProductDraft|VendorProduct $product): array
    {
        return $this->updateStock($product->magento_sku ?: $product->sku, (int) ($product->quantity ?? 0));
    }

    public function updateStock(string $sku, int $qty): array
    {
        return $this->put('products/' . rawurlencode($sku) . '/stockItems/1', [
            'stockItem' => ['qty' => $qty, 'is_in_stock' => $qty > 0]
        ]);
    }

    public function getOrders(int $page = 1, int $size = 20): array
    {
        return $this->get('orders', [
            'searchCriteria[currentPage]'              => $page,
            'searchCriteria[pageSize]'                 => $size,
            'searchCriteria[sortOrders][0][field]'     => 'created_at',
            'searchCriteria[sortOrders][0][direction]' => 'DESC',
        ]);
    }

    public function getCustomers(int $page = 1, int $size = 50): array
    {
        return $this->get('customers/search', [
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]'    => $size,
        ]);
    }

    public function getCategories(): array
    {
        return $this->get('categories');
    }


    private function buildProductPayload(ProductDraft|VendorProduct $product): array
    {
        $attributes = is_array($product->attributes ?? null) ? $product->attributes : [];

        $requestedStatus = $product instanceof ProductDraft
            ? data_get($product->product_data, 'status', 'active')
            : ($product->status ?? 'active');

        // description & short_description always go in as plain strings
        $customAttributes = [
            ['attribute_code' => 'description',       'value' => $product->description       ?? $attributes['description']       ?? ''],
            ['attribute_code' => 'short_description',  'value' => $product->short_description ?? $attributes['short_description'] ?? ''],
        ];

        // Separate known scalar/array attributes from select attributes that need ID resolution
        $skipCodes = ['description', 'short_description'];

        foreach ($attributes as $code => $value) {
            if (in_array($code, $skipCodes, true)) {
                continue;
            }

            if (is_array($value)) {
                // Multi-select or array value — resolve each label to an option ID
                $resolvedValues = array_map(
                    fn($v) => is_int($v) || ctype_digit((string) $v)
                        ? (int) $v
                        : $this->resolveOptionId($code, $v),
                    $value
                );

                $customAttributes[] = [
                    'attribute_code' => $code,
                    'value'          => implode(',', $resolvedValues), // Magento multi-select format
                ];

                continue;
            }

            // Single value — resolve if string, pass through if already an int
            $resolvedValue = is_int($value) || ctype_digit((string) $value)
                ? (int) $value
                : $this->tryResolveOptionId($code, $value);

            $customAttributes[] = [
                'attribute_code' => $code,
                'value'          => $resolvedValue,
            ];
        }

        if (! empty($product->categories) && is_array($product->categories)) {
            $customAttributes[] = [
                'attribute_code' => 'category_ids',
                'value'          => array_values($product->categories),
            ];
        }

        return [
            'sku'                  => $product->sku,
            'name'                 => $product->name,
            'attribute_set_id'     => (int) ($product->attribute_set_id ?? 4),
            'price'                => (float) ($product->price ?? 0),
            'status'               => $requestedStatus === 'inactive' ? 2 : 1,
            'visibility'           => 4,
            'type_id'              => $product->type_id ?? 'simple',
            'weight'               => (float) ($product->weight ?? 0),
            'extension_attributes' => [
                'stock_item' => [
                    'qty'         => (int) ($product->quantity ?? 0),
                    'is_in_stock' => (int) ($product->quantity ?? 0) > 0,
                ],
            ],
            'custom_attributes'    => $customAttributes,
        ];
    }

    /**
     * Resolve a string label to its Magento option ID.
     * Throws if no match found — hard fail so bad data doesn't silently go through.
     *
     * Results are cached per attribute code for 24 hours to avoid hammering Magento.
     */
    private function resolveOptionId(string $attributeCode, string $label): int
    {
        $options = \Illuminate\Support\Facades\Cache::remember(
            "magento_attr_opts_{$attributeCode}",
            now()->addHours(24),
            fn() => $this->get("/V1/products/attributes/{$attributeCode}/options")
        );

        $match = collect($options)->first(
            fn($opt) => strtolower(trim($opt['label'])) === strtolower(trim($label))
        );

        if (! $match) {
            $available = collect($options)
                ->filter(fn($o) => $o['value'] !== '') // exclude the empty "Choose an option" entry
                ->pluck('label')
                ->implode(', ');

            throw new \RuntimeException(
                "Magento attribute '{$attributeCode}': no option found for '{$label}'. " .
                    "Available options: {$available}"
            );
        }

        return (int) $match['value'];
    }

    /**
     * Like resolveOptionId but falls back to the raw string value when the
     * attribute has no options (i.e. it's a text/textarea field, not a select).
     * This prevents crashing on free-text attributes like 'brand', 'material', etc.
     */
    private function tryResolveOptionId(string $attributeCode, string $value): int|string
    {
        try {
            $options = \Illuminate\Support\Facades\Cache::remember(
                "magento_attr_opts_{$attributeCode}",
                now()->addHours(24),
                fn() => $this->get("/V1/products/attributes/{$attributeCode}/options")
            );

            // Empty or single-entry response means it's a text field, not a select
            $filteredOptions = collect($options)->filter(fn($o) => $o['value'] !== '');

            if ($filteredOptions->isEmpty()) {
                return $value; // free-text attribute — pass as-is
            }

            $match = $filteredOptions->first(
                fn($opt) => strtolower(trim($opt['label'])) === strtolower(trim($value))
            );

            return $match ? (int) $match['value'] : $value;
        } catch (\Throwable) {
            // Attribute fetch failed (e.g. attribute doesn't exist in this set) — pass raw
            return $value;
        }
    }
    // private function buildProductPayload(ProductDraft|VendorProduct $product): array
    // {
    //     $attributes = is_array($product->attributes ?? null) ? $product->attributes : [];
    //     $requestedStatus = $product instanceof ProductDraft
    //         ? data_get($product->product_data, 'status', 'active')
    //         : ($product->status ?? 'active');

    //     $customAttributes = [
    //         ['attribute_code' => 'description', 'value' => $product->description ?? $attributes['description'] ?? ''],
    //         ['attribute_code' => 'short_description', 'value' => $product->short_description ?? $attributes['short_description'] ?? ''],
    //     ];

    //     foreach ($attributes as $code => $value) {
    //         if (in_array($code, ['description', 'short_description'], true)) {
    //             continue;
    //         }

    //         $customAttributes[] = [
    //             'attribute_code' => $code,
    //             'value' => is_array($value) ? json_encode($value) : $value,
    //         ];
    //     }

    //     if (! empty($product->categories) && is_array($product->categories)) {
    //         $customAttributes[] = [
    //             'attribute_code' => 'category_ids',
    //             'value' => array_values($product->categories),
    //         ];
    //     }

    //     return [
    //         'sku' => $product->sku,
    //         'name' => $product->name,
    //         'attribute_set_id' => (int) ($product->attribute_set_id ?? 4),
    //         'price' => (float) ($product->price ?? 0),
    //         'status' => $requestedStatus === 'inactive' ? 2 : 1,
    //         'visibility' => 4,
    //         'type_id' => $product->type_id ?? 'simple',
    //         'weight' => (float) ($product->weight ?? 0),
    //         'extension_attributes' => [
    //             'stock_item' => [
    //                 'qty' => (int) ($product->quantity ?? 0),
    //                 'is_in_stock' => ((int) ($product->quantity ?? 0)) > 0,
    //             ],
    //         ],
    //         'custom_attributes' => $customAttributes,
    //     ];
    // }
}
