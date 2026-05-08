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
     * Constructor - Initialize Magento service with vendor credentials or manual token
     * 
     * @param Vendor|string|null $vendorOrToken - Vendor model instance or manual token string
     * @param string|null $baseUrl - Manual base URL (used only with manual token)
     * @throws \Exception
     */
    public function __construct(Vendor|string|null $vendorOrToken = null, ?string $baseUrl = null)
    {
        if ($vendorOrToken instanceof Vendor) {
            // Initialize from vendor DB credentials
            $this->vendor = $vendorOrToken;
            
            if (empty($vendorOrToken->magento_base_url)) {
                throw new \Exception('Magento base URL is missing for vendor ID: ' . $vendorOrToken->id);
            }

            $this->baseUrl = rtrim($vendorOrToken->magento_base_url, '/');
            $this->token = $this->resolveToken($vendorOrToken);
        } else {
            // Manual initialization (fallback / master admin)
            $this->vendor = null;
            $this->baseUrl = rtrim($baseUrl ?? config('magento.base_url'), '/');
            $this->token = $vendorOrToken ?? config('magento.access_token', '');
        }
    }

    // ─────────────────────────────────────────────────────
    // TOKEN RESOLUTION & AUTHENTICATION
    // ─────────────────────────────────────────────────────

    /**
     * Resolve valid token for vendor from database or fetch fresh
     * Priority: Integration Token (permanent) > Admin Token (valid cache) > Fresh Fetch
     * 
     * @param Vendor $vendor
     * @return string
     * @throws \Exception
     */
    protected function resolveToken(Vendor $vendor): string
    {
        // Priority 1: Integration token (permanent, never expires)
        if (!empty($vendor->magento_access_token)) {
            return $vendor->magento_access_token;
        }

        // Priority 2: Cached admin token (valid for ~3.5 hours)
        if ($vendor->isMagentoTokenValid()) {
            return $vendor->magento_admin_token;
        }

        // Priority 3: Ensure credentials exist before fetching
        if (empty($vendor->magento_admin_username) || empty($vendor->magento_admin_pass)) {
            throw new \Exception('Magento API credentials are missing for vendor ID: ' . $vendor->id);
        }

        // Priority 4: Fetch fresh token
        return $this->fetchAndSaveToken($vendor);
    }

    /**
     * Fetch fresh admin token and save to vendor record
     * 
     * @param Vendor $vendor
     * @return string
     */
    public function fetchAndSaveToken(Vendor $vendor): string
    {
        $token = $this->fetchAdminToken(
            $vendor->magento_base_url,
            $vendor->magento_admin_username,
            $vendor->magento_admin_pass,
        );

        $vendor->update([
            'magento_admin_token' => $token,
            'magento_token_expires_at' => now()->addMinutes(210), // 3.5 hours (Magento default)
            'magento_synced_at' => now(),
        ]);

        return $token;
    }

    /**
     * Fetch admin token from Magento API
     * POST /rest/V1/integration/admin/token
     * 
     * @param string|null $baseUrl
     * @param string|null $username
     * @param string|null $password
     * @return string
     * @throws \Exception
     */
    public function fetchAdminToken(
        ?string $baseUrl = null,
        ?string $username = null,
        ?string $password = null
    ): string {
        $url = rtrim($baseUrl ?? config('magento.base_url'), '/');
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
    // HTTP CLIENT & REQUEST HANDLING
    // ─────────────────────────────────────────────────────

    /**
     * Get HTTP client with authentication headers
     * 
     * @return \Illuminate\Http\Client\PendingRequest
     * @throws \Exception
     */
    private function http()
    {
        $this->ensureToken();

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30);
    }

    /**
     * Ensure valid token exists, fetch if missing
     * 
     * @throws \Exception
     */
    private function ensureToken(): void
    {
        if (!empty($this->token)) {
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

    /**
     * Make HTTP request to Magento API with automatic token refresh on 401
     * 
     * @param string $method - HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint - API endpoint (without /rest/V1/ prefix)
     * @param array $data - Request payload or query parameters
     * @return array
     * @throws \Exception
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = "{$this->baseUrl}/rest/V1/{$endpoint}";

        $response = match ($method) {
            'GET' => $this->http()->get($url, $data),
            'POST' => $this->http()->post($url, $data),
            'PUT' => $this->http()->put($url, $data),
            'DELETE' => $this->http()->delete($url),
        };

        // Token expired - refresh and retry once
        if ($response->status() === 401 && $this->vendor) {
            Log::warning("Magento token expired for vendor {$this->vendor->id}, refreshing...");
            $this->token = $this->fetchAndSaveToken($this->vendor);

            $response = match ($method) {
                'GET' => $this->http()->get($url, $data),
                'POST' => $this->http()->post($url, $data),
                'PUT' => $this->http()->put($url, $data),
                'DELETE' => $this->http()->delete($url),
            };
        }

        if ($response->failed()) {
            Log::error("Magento API [{$method} {$endpoint}]", [
                'vendor_id' => $this->vendor?->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception("Magento [{$endpoint}] failed: " . $response->body());
        }

        $json = $response->json();

        if (is_array($json)) {
            return $json;
        }

        return ['success' => (bool) $json, 'value' => $json];
    }

    /**
     * GET request helper
     */
    private function get(string $ep, array $q = []): array
    {
        return $this->request('GET', $ep, $q);
    }

    /**
     * POST request helper
     */
    private function post(string $ep, array $b = []): array
    {
        return $this->request('POST', $ep, $b);
    }

    /**
     * PUT request helper
     */
    private function put(string $ep, array $b = []): array
    {
        return $this->request('PUT', $ep, $b);
    }

    /**
     * DELETE request helper
     */
    private function delete(string $ep): array
    {
        return $this->request('DELETE', $ep);
    }

    // ─────────────────────────────────────────────────────
    // COUPON & SALES RULE MANAGEMENT (CART PRICE RULES)
    // ─────────────────────────────────────────────────────

    /**
     * Create a new sales rule (cart price rule) in Magento
     * POST /rest/V1/salesRules
     * 
     * @param array $ruleData - Sales rule data structure
     * @return array - Created rule data with rule_id
     * @throws \Exception
     * 
     * Expected $ruleData structure:
     * [
     *     'rule' => [
     *         'name' => 'Coupon Code',
     *         'description' => 'Description here',
     *         'is_active' => true,
     *         'uses_per_customer' => 1,
     *         'uses_per_coupon' => 100,
     *         'discount_amount' => 10.00,
     *         'simple_action' => 'by_percent', // or 'cart_fixed', 'buy_x_get_y'
     *         'coupon_type' => 2, // 1=No coupon, 2=Specific coupon, 3=Auto generation
     *         'use_auto_generation' => false,
     *         'website_ids' => [1],
     *         'customer_group_ids' => [0,1,2,3],
     *         'from_date' => '2024-01-01',
     *         'to_date' => '2024-12-31',
     *     ]
     * ]
     */
    public function createSalesRule(array $ruleData): array
    {
        return $this->post('salesRules', $ruleData);
    }

    /**
     * Update an existing sales rule in Magento
     * PUT /rest/V1/salesRules/{ruleId}
     * 
     * @param int $ruleId - Magento rule ID
     * @param array $ruleData - Updated rule data (only fields to update)
     * @return array - Updated rule data
     * @throws \Exception
     */
    public function updateSalesRule(int $ruleId, array $ruleData): array
    {
        // Ensure rule_id is included in payload for PUT request
        if (!isset($ruleData['rule']['rule_id'])) {
            $ruleData['rule']['rule_id'] = $ruleId;
        }
        
        return $this->put('salesRules/' . $ruleId, $ruleData);
    }

    /**
     * Delete a sales rule from Magento
     * DELETE /rest/V1/salesRules/{ruleId}
     * 
     * @param int $ruleId - Magento rule ID
     * @return array - Response data
     * @throws \Exception
     */
    public function deleteSalesRule(int $ruleId): array
    {
        return $this->delete('salesRules/' . $ruleId);
    }

    /**
     * Get a sales rule by ID
     * GET /rest/V1/salesRules/{ruleId}
     * 
     * @param int $ruleId - Magento rule ID
     * @return array - Rule data
     * @throws \Exception
     */
    public function getSalesRule(int $ruleId): array
    {
        return $this->get('salesRules/' . $ruleId);
    }

    /**
     * Search sales rules with filters
     * GET /rest/V1/salesRules/search
     * 
     * @param array $criteria - Search criteria (filters, page size, etc.)
     * @return array - List of matching rules
     * @throws \Exception
     */
    public function searchSalesRules(array $criteria = []): array
    {
        $query = [];
        
        if (!empty($criteria['name'])) {
            $query['searchCriteria[filter_groups][0][filters][0][field]'] = 'name';
            $query['searchCriteria[filter_groups][0][filters][0][value]'] = $criteria['name'];
            $query['searchCriteria[filter_groups][0][filters][0][condition_type]'] = 'like';
        }
        
        if (!empty($criteria['is_active'])) {
            $query['searchCriteria[filter_groups][1][filters][0][field]'] = 'is_active';
            $query['searchCriteria[filter_groups][1][filters][0][value]'] = $criteria['is_active'] ? '1' : '0';
            $query['searchCriteria[filter_groups][1][filters][0][condition_type]'] = 'eq';
        }
        
        if (!empty($criteria['page_size'])) {
            $query['searchCriteria[pageSize]'] = $criteria['page_size'];
        }
        
        if (!empty($criteria['current_page'])) {
            $query['searchCriteria[currentPage]'] = $criteria['current_page'];
        }
        
        return $this->get('salesRules/search', $query);
    }

    /**
     * Update only the status of a sales rule
     * Convenience method for toggling active/inactive
     * 
     * @param int $ruleId - Magento rule ID
     * @param bool $isActive - Active status
     * @return array - Updated rule data
     * @throws \Exception
     */
    public function updateSalesRuleStatus(int $ruleId, bool $isActive): array
    {
        return $this->updateSalesRule($ruleId, [
            'rule' => [
                'rule_id' => $ruleId,
                'is_active' => $isActive,
            ]
        ]);
    }

    /**
     * Create a coupon code for a sales rule
     * POST /rest/V1/coupons
     * 
     * @param int $ruleId - Parent sales rule ID
     * @param string $couponCode - Unique coupon code
     * @param int|null $usageLimit - Maximum uses for this coupon (null = unlimited)
     * @param int|null $usagePerCustomer - Maximum uses per customer (null = unlimited)
     * @return array - Created coupon data with coupon_id
     * @throws \Exception
     * 
     * Note: Use this for specific coupons. For auto-generated coupons,
     * set use_auto_generation=true in sales rule instead.
     */
    public function createCoupon(int $ruleId, string $couponCode, ?int $usageLimit = null, ?int $usagePerCustomer = null): array
    {
        $couponData = [
            'coupon' => [
                'rule_id' => $ruleId,
                'code' => $couponCode,
                'usage_limit' => $usageLimit ?? 0, // 0 = unlimited
                'usage_per_customer' => $usagePerCustomer ?? 0,
                'times_used' => 0,
                'is_primary' => true, // Primary coupon for the rule
            ]
        ];
        
        return $this->post('coupons', $couponData);
    }

    /**
     * Update an existing coupon
     * PUT /rest/V1/coupons/{couponId}
     * 
     * @param int $couponId - Magento coupon ID
     * @param array $couponData - Updated coupon data
     * @return array - Updated coupon data
     * @throws \Exception
     */
    public function updateCoupon(int $couponId, array $couponData): array
    {
        if (!isset($couponData['coupon']['coupon_id'])) {
            $couponData['coupon']['coupon_id'] = $couponId;
        }
        
        return $this->put('coupons/' . $couponId, $couponData);
    }

    /**
     * Delete a coupon from Magento
     * DELETE /rest/V1/coupons/{couponId}
     * 
     * @param int $couponId - Magento coupon ID
     * @return array - Response data
     * @throws \Exception
     */
    public function deleteCoupon(int $couponId): array
    {
        return $this->delete('coupons/' . $couponId);
    }

    /**
     * Get a coupon by ID
     * GET /rest/V1/coupons/{couponId}
     * 
     * @param int $couponId - Magento coupon ID
     * @return array - Coupon data
     * @throws \Exception
     */
    public function getCoupon(int $couponId): array
    {
        return $this->get('coupons/' . $couponId);
    }

    /**
     * Search coupons with filters
     * GET /rest/V1/coupons/search
     * 
     * @param array $criteria - Search criteria
     * @return array - List of matching coupons
     * @throws \Exception
     * 
     * Available filters:
     * - rule_id: Filter by parent sales rule ID
     * - code: Filter by coupon code (exact match)
     * - coupon_id: Filter by coupon ID
     * - times_used: Filter by usage count
     */
    public function searchCoupons(array $criteria = []): array
    {
        $query = [];
        $filterIndex = 0;
        
        // Filter by rule_id
        if (!empty($criteria['rule_id'])) {
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'rule_id';
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $criteria['rule_id'];
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'eq';
            $filterIndex++;
        }
        
        // Filter by coupon code
        if (!empty($criteria['code'])) {
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'code';
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $criteria['code'];
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'eq';
            $filterIndex++;
        }
        
        // Filter by usage count range
        if (isset($criteria['min_uses'])) {
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'times_used';
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $criteria['min_uses'];
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'gteq';
            $filterIndex++;
        }
        
        if (isset($criteria['max_uses'])) {
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'times_used';
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $criteria['max_uses'];
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'lteq';
            $filterIndex++;
        }
        
        // Pagination
        if (!empty($criteria['page_size'])) {
            $query['searchCriteria[pageSize]'] = $criteria['page_size'];
        }
        
        if (!empty($criteria['current_page'])) {
            $query['searchCriteria[currentPage]'] = $criteria['current_page'];
        }
        
        // Sorting
        if (!empty($criteria['sort_field'])) {
            $query['searchCriteria[sortOrders][0][field]'] = $criteria['sort_field'];
            $query['searchCriteria[sortOrders][0][direction]'] = $criteria['sort_direction'] ?? 'DESC';
        }
        
        return $this->get('coupons/search', $query);
    }

    /**
     * Get coupon by code (convenience method)
     * Uses search API to find coupon by exact code
     * 
     * @param string $couponCode
     * @return array|null - Coupon data or null if not found
     * @throws \Exception
     */
    public function getCouponByCode(string $couponCode): ?array
    {
        $result = $this->searchCoupons(['code' => $couponCode]);
        $items = $result['items'] ?? [];
        
        return !empty($items) ? $items[0] : null;
    }

    /**
     * Generate multiple coupon codes for a sales rule (auto-generation)
     * This requires the sales rule to have use_auto_generation = true
     * POST /rest/V1/coupons/generate
     * 
     * @param int $ruleId - Parent sales rule ID
     * @param int $quantity - Number of coupons to generate
     * @param int $length - Length of coupon code
     * @param string $format - Format (alphanum, alpha, num)
     * @param string|null $prefix - Optional prefix
     * @param string|null $suffix - Optional suffix
     * @return array - Generated coupon list
     * @throws \Exception
     */
    public function generateCoupons(int $ruleId, int $quantity, int $length = 12, string $format = 'alphanum', ?string $prefix = null, ?string $suffix = null): array
    {
        $data = [
            'coupon' => [
                'rule_id' => $ruleId,
                'qty' => $quantity,
                'length' => $length,
                'format' => $format,
                'prefix' => $prefix,
                'suffix' => $suffix,
            ]
        ];
        
        return $this->post('coupons/generate', $data);
    }

    // ─────────────────────────────────────────────────────
    // VENDOR STORE MANAGEMENT
    // ─────────────────────────────────────────────────────

    /**
     * Create complete vendor store structure (Website, Store Group, Root Category)
     * 
     * @param Vendor $vendor
     * @return array
     * @throws \Exception
     */
    public function createVendorStore(Vendor $vendor): array
    {
        // Step 1: Create website
        $website = $this->post('store/websites', [
            'website' => [
                'code' => 'vendor_' . $vendor->id . '_' . time(),
                'name' => $vendor->company_name,
                'default_group_id' => 0,
                'is_default' => false,
            ]
        ]);

        $websiteId = $website['id'] ?? null;
        if (!$websiteId) {
            throw new \Exception('Website creation failed: ' . json_encode($website));
        }

        // Step 2: Create root category for the store
        $category = $this->post('categories', [
            'category' => [
                'parent_id' => 2, // Default Root Category
                'name' => $vendor->company_name,
                'is_active' => true,
                'include_in_menu' => false,
            ]
        ]);

        $rootCategoryId = $category['id'] ?? 2;

        // Step 3: Create store group
        $storeGroup = $this->post('store/storeGroups', [
            'storeGroup' => [
                'website_id' => $websiteId,
                'name' => $vendor->company_name,
                'code' => 'vendor_group_' . $vendor->id,
                'root_category_id' => $rootCategoryId,
            ]
        ]);

        // Step 4: Update vendor with Magento IDs
        $vendor->update([
            'magento_website_id' => $websiteId,
            'magento_store_group_id' => $storeGroup['id'] ?? null,
            'magento_root_category_id' => $rootCategoryId,
            'magento_synced_at' => now(),
        ]);

        return [
            'website_id' => $websiteId,
            'store_group_id' => $storeGroup['id'] ?? null,
            'root_category_id' => $rootCategoryId,
        ];
    }

    /**
     * Create store group from data
     * 
     * @param Vendor $vendor
     * @param array $data
     * @return array
     * @throws \Exception
     */
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

    /**
     * Update store group
     * 
     * @param VendorStore $store
     * @return array
     * @throws \Exception
     */
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

    /**
     * Delete store group
     * 
     * @param VendorStore $store
     * @return array
     */
    public function deleteStoreGroup(VendorStore $store): array
    {
        $storeGroupId = $store->magento_store_group_id ?: $store->magento_store_id;

        if (empty($storeGroupId)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'missing_magento_store_group_id'];
        }

        return $this->deleteStoreGroupById((int) $storeGroupId);
    }

    /**
     * Delete store group by ID
     * 
     * @param int $storeGroupId
     * @return array
     */
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

    /**
     * Create store view
     * 
     * @param Vendor $vendor
     * @param VendorStore $store
     * @return array
     */
    public function createStoreView(Vendor $vendor, VendorStore $store): array
    {
        return $this->createStoreViewFromData($vendor, [
            'store_name' => $store->store_name,
            'store_slug' => $store->store_slug,
            'status' => $store->status,
        ]);
    }

    /**
     * Create store view from data
     * 
     * @param Vendor $vendor
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function createStoreViewFromData(Vendor $vendor, array $data): array
    {
        if (empty($vendor->magento_website_id) || empty($vendor->magento_store_group_id)) {
            throw new \Exception('Magento website/store group is not configured for vendor ID: ' . $vendor->id);
        }

        $response = $this->post('store/storeViews', [
            'storeView' => [
                'code' => $this->storeViewCodeFromData($data),
                'name' => $data['store_name'],
                'website_id' => $vendor->magento_website_id,
                'store_group_id' => $vendor->magento_store_group_id,
                'is_active' => ($data['status'] ?? 'inactive') === 'active',
            ]
        ]);

        return [
            'store_id' => $response['id'] ?? $response['value'] ?? null,
            'store_group_id' => $vendor->magento_store_group_id,
            'website_id' => $vendor->magento_website_id,
            'response' => $response,
        ];
    }

    /**
     * Update store view
     * 
     * @param VendorStore $store
     * @return array
     * @throws \Exception
     */
    public function updateStoreView(VendorStore $store): array
    {
        if (empty($store->magento_store_id)) {
            throw new \Exception('Magento store ID is missing for this store.');
        }

        return $this->put('store/storeViews/' . $store->magento_store_id, [
            'storeView' => [
                'id' => (int) $store->magento_store_id,
                'code' => $this->storeViewCode($store),
                'name' => $store->store_name,
                'website_id' => (int) $store->magento_website_id,
                'store_group_id' => (int) $store->magento_store_group_id,
                'is_active' => $store->status === 'active',
            ],
        ]);
    }

    /**
     * Delete store view
     * 
     * @param VendorStore $store
     * @return array
     */
    public function deleteStoreView(VendorStore $store): array
    {
        if (empty($store->magento_store_id)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'missing_magento_store_id'];
        }

        return $this->deleteStoreViewById((int) $store->magento_store_id);
    }

    /**
     * Delete store view by ID
     * 
     * @param int $magentoStoreId
     * @return array
     * @throws \Exception
     */
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

    /**
     * Get all store views
     * 
     * @return array
     */
    public function getStoreViews(): array
    {
        return $this->get('store/storeViews');
    }

    /**
     * Get all store groups
     * 
     * @return array
     */
    public function getStoreGroups(): array
    {
        return $this->get('store/storeGroups');
    }

    /**
     * Get all websites
     * 
     * @return array
     */
    public function getWebsites(): array
    {
        return $this->get('store/websites');
    }

    // ─────────────────────────────────────────────────────
    // PRODUCT MANAGEMENT
    // ─────────────────────────────────────────────────────

    /**
     * Get products with pagination
     * 
     * @param int $page
     * @param int $size
     * @return array
     */
    public function getProducts(int $page = 1, int $size = 20): array
    {
        return $this->get('products', [
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]' => $size,
        ]);
    }

    /**
     * Get product by SKU
     * 
     * @param string $sku
     * @return array
     */
    public function getProductBySku(string $sku): array
    {
        return $this->get('products/' . rawurlencode($sku));
    }

    /**
     * Create product
     * 
     * @param array $data
     * @return array
     */
    public function createProduct(array $data): array
    {
        return $this->post('products', ['product' => $data]);
    }

    /**
     * Update product
     * 
     * @param string $sku
     * @param array $data
     * @return array
     */
    public function updateProduct(string $sku, array $data): array
    {
        return $this->put('products/' . rawurlencode($sku), ['product' => $data]);
    }

    /**
     * Delete product
     * 
     * @param string $sku
     * @return array
     */
    public function deleteProduct(string $sku): array
    {
        return $this->delete('products/' . rawurlencode($sku));
    }

    /**
     * Create or update product (upsert)
     * 
     * @param ProductDraft|VendorProduct $product
     * @return array
     */
    public function createOrUpdateProduct(ProductDraft|VendorProduct $product): array
    {
        $payload = $this->buildProductPayload($product);

        if (!empty($product->magento_product_id) || !empty($product->magento_sku)) {
            return $this->updateProduct($product->magento_sku ?: $product->sku, $payload);
        }

        return $this->createProduct($payload);
    }

    /**
     * Sync stock for product
     * 
     * @param ProductDraft|VendorProduct $product
     * @return array
     */
    public function syncStockForProduct(ProductDraft|VendorProduct $product): array
    {
        return $this->updateStock($product->magento_sku ?: $product->sku, (int) ($product->quantity ?? 0));
    }

    /**
     * Update stock for product
     * 
     * @param string $sku
     * @param int $qty
     * @return array
     */
    public function updateStock(string $sku, int $qty): array
    {
        return $this->put('products/' . rawurlencode($sku) . '/stockItems/1', [
            'stockItem' => ['qty' => $qty, 'is_in_stock' => $qty > 0]
        ]);
    }

    // ─────────────────────────────────────────────────────
    // ORDER MANAGEMENT
    // ─────────────────────────────────────────────────────

    /**
     * Get orders with pagination
     * 
     * @param int $page
     * @param int $size
     * @return array
     */
    public function getOrders(int $page = 1, int $size = 20): array
    {
        return $this->get('orders', [
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]' => $size,
            'searchCriteria[sortOrders][0][field]' => 'created_at',
            'searchCriteria[sortOrders][0][direction]' => 'DESC',
        ]);
    }

    // ─────────────────────────────────────────────────────
    // CUSTOMER MANAGEMENT
    // ─────────────────────────────────────────────────────

    /**
     * Get customers with pagination
     * 
     * @param int $page
     * @param int $size
     * @return array
     */
    public function getCustomers(int $page = 1, int $size = 50): array
    {
        return $this->get('customers/search', [
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]' => $size,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // CATEGORY MANAGEMENT
    // ─────────────────────────────────────────────────────

    /**
     * Get all categories
     * 
     * @return array
     */
    public function getCategories(): array
    {
        return $this->get('categories');
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE HELPER METHODS
    // ─────────────────────────────────────────────────────

    /**
     * Generate store view code from store data
     * 
     * @param VendorStore $store
     * @return string
     */
    private function storeViewCode(VendorStore $store): string
    {
        return $this->storeViewCodeFromData([
            'store_slug' => $store->store_slug,
            'store_name' => $store->store_name,
        ]);
    }

    /**
     * Generate store view code from data array
     * 
     * @param array $data
     * @return string
     */
    private function storeViewCodeFromData(array $data): string
    {
        $source = $data['store_slug'] ?? $data['store_name'] ?? 'store';
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($source));
        $slug = trim($slug ?: 'store', '_');

        return 'store_' . $slug . '_' . substr(md5($slug), 0, 8);
    }

    /**
     * Generate store group code from data array
     * 
     * @param array $data
     * @return string
     */
    private function storeGroupCodeFromData(array $data): string
    {
        $source = $data['store_slug'] ?? $data['store_name'] ?? 'store';
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($source));
        $slug = trim($slug ?: 'store', '_');

        return 'store_' . $slug . '_' . substr(md5($slug), 0, 8);
    }

    /**
     * Build product payload for Magento API
     * 
     * @param ProductDraft|VendorProduct $product
     * @return array
     */
    private function buildProductPayload(ProductDraft|VendorProduct $product): array
    {
        $attributes = is_array($product->attributes ?? null) ? $product->attributes : [];

        $requestedStatus = $product instanceof ProductDraft
            ? data_get($product->product_data, 'status', 'active')
            : ($product->status ?? 'active');

        // Base custom attributes (description and short_description are always plain strings)
        $customAttributes = [
            ['attribute_code' => 'description', 'value' => $product->description ?? $attributes['description'] ?? ''],
            ['attribute_code' => 'short_description', 'value' => $product->short_description ?? $attributes['short_description'] ?? ''],
        ];

        // Skip codes that are already handled
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
                    'value' => implode(',', $resolvedValues), // Magento multi-select format
                ];

                continue;
            }

            // Single value — resolve if string, pass through if already an int
            $resolvedValue = is_int($value) || ctype_digit((string) $value)
                ? (int) $value
                : $this->tryResolveOptionId($code, $value);

            $customAttributes[] = [
                'attribute_code' => $code,
                'value' => $resolvedValue,
            ];
        }

        // Add category IDs if present
        if (!empty($product->categories) && is_array($product->categories)) {
            $customAttributes[] = [
                'attribute_code' => 'category_ids',
                'value' => array_values($product->categories),
            ];
        }

        return [
            'sku' => $product->sku,
            'name' => $product->name,
            'attribute_set_id' => (int) ($product->attribute_set_id ?? 4),
            'price' => (float) ($product->price ?? 0),
            'status' => $requestedStatus === 'inactive' ? 2 : 1, // 1=Enabled, 2=Disabled
            'visibility' => 4, // 4=Catalog, Search
            'type_id' => $product->type_id ?? 'simple',
            'weight' => (float) ($product->weight ?? 0),
            'extension_attributes' => [
                'stock_item' => [
                    'qty' => (int) ($product->quantity ?? 0),
                    'is_in_stock' => (int) ($product->quantity ?? 0) > 0,
                ],
            ],
            'custom_attributes' => $customAttributes,
        ];
    }

    /**
     * Resolve a string label to its Magento option ID.
     * Throws if no match found — hard fail so bad data doesn't silently go through.
     * Results are cached per attribute code for 24 hours to avoid hammering Magento.
     * 
     * @param string $attributeCode
     * @param string $label
     * @return int
     * @throws \RuntimeException
     */
    private function resolveOptionId(string $attributeCode, string $label): int
    {
        $options = \Illuminate\Support\Facades\Cache::remember(
            "magento_attr_opts_{$attributeCode}",
            now()->addHours(24),
            fn() => $this->get("products/attributes/{$attributeCode}/options")
        );

        $match = collect($options)->first(
            fn($opt) => strtolower(trim($opt['label'])) === strtolower(trim($label))
        );

        if (!$match) {
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
     * 
     * @param string $attributeCode
     * @param string $value
     * @return int|string
     */
    private function tryResolveOptionId(string $attributeCode, string $value): int|string
    {
        try {
            $options = \Illuminate\Support\Facades\Cache::remember(
                "magento_attr_opts_{$attributeCode}",
                now()->addHours(24),
                fn() => $this->get("products/attributes/{$attributeCode}/options")
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
}