<?php
namespace App\Services\Integration;

use App\Models\Vendor\Vendor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MagentoService
{
    protected string $baseUrl;
    protected string $token;
    protected ?Vendor $vendor;

    public static function forVendor(Vendor $vendor): self
    {
        return new self($vendor);
    }

    /**
     * Constructor - Initialize Magento service with vendor credentials ONLY from database
     * 
     * @param Vendor $vendor - Vendor model instance (must have Magento credentials)
     * @throws \Exception
     */
    public function __construct(Vendor $vendor)
    {
        // Store the vendor
        $this->vendor = $vendor;
        
        // Validate required fields from vendors table
        if (empty($vendor->magento_base_url)) {
            throw new \Exception(
                sprintf(
                    'Magento base URL is missing for vendor "%s" (ID: %s). Please update the vendor\'s magento_base_url field.',
                    $vendor->company_name ?? 'Unknown',
                    $vendor->id ?? 'null'
                )
            );
        }
        
        // Set base URL
        $this->baseUrl = rtrim($vendor->magento_base_url, '/');
        
        // Get token from vendor (will throw exception if no valid credentials)
        $this->token = $this->getTokenFromVendor($vendor);

    }
    
    /**
     * Get token from vendor (only from database, no external config)
     * 
     * @param Vendor $vendor
     * @return string
     * @throws \Exception
     */
    private function getTokenFromVendor(Vendor $vendor): string
    {
        // Priority 1: Integration token (permanent, from vendors table)
        if (!empty($vendor->magento_access_token)) {
            Log::info('Using integration token from vendor', [
                'vendor_id' => $vendor->id
            ]);
            return $vendor->magento_access_token;
        }
        
        // Priority 2: Valid cached admin token (from vendors table)
        if ($this->isVendorTokenValid($vendor) && !empty($vendor->magento_admin_token)) {
            Log::info('Using cached admin token from vendor', [
                'vendor_id' => $vendor->id,
                'expires_at' => $vendor->magento_token_expires_at
            ]);
            return $vendor->magento_admin_token;
        }
        
        // Priority 3: Fetch new token using admin credentials from vendors table
        if (!empty($vendor->magento_admin_username) && !empty($vendor->magento_admin_pass)) {
            Log::info('Fetching new admin token from Magento API', [
                'vendor_id' => $vendor->id,
                'base_url' => $vendor->magento_base_url
            ]);
            return $this->fetchAndSaveToken($vendor);
        }
        
        // No valid credentials found in vendors table
        throw new \Exception(
            sprintf(
                'No valid Magento credentials found for vendor "%s" (ID: %s). ' .
                'Please ensure the vendor has either: ' .
                '1) magento_access_token (integration token), OR ' .
                '2) Both magento_admin_username and magento_admin_pass fields populated.',
                $vendor->company_name ?? 'Unknown',
                $vendor->id ?? 'null'
            )
        );
    }
    
    /**
     * Check if vendor's cached token is valid
     */
    private function isVendorTokenValid(Vendor $vendor): bool
    {
        return !empty($vendor->magento_admin_token) && 
               !empty($vendor->magento_token_expires_at) && 
               now()->lessThan($vendor->magento_token_expires_at);
    }

    /**
     * Fetch admin token using vendor's credentials and save to vendors table
     */
    public function fetchAndSaveToken(Vendor $vendor): string
    {
        // Validate vendor has admin credentials
        if (empty($vendor->magento_admin_username) || empty($vendor->magento_admin_pass)) {
            throw new \Exception(
                sprintf(
                    'Cannot fetch token: Admin credentials missing for vendor "%s" (ID: %s)',
                    $vendor->company_name ?? 'Unknown',
                    $vendor->id ?? 'null'
                )
            );
        }
        
        // Fetch token from Magento
        $token = $this->fetchAdminToken(
            $vendor->magento_base_url,
            $vendor->magento_admin_username,
            $vendor->magento_admin_pass,
        );
        
        // Save token to vendors table
        $vendor->update([
            'magento_admin_token' => $token,
            'magento_token_expires_at' => now()->addMinutes(210), // 3.5 hours
            'magento_synced_at' => now(),
        ]);
        
        Log::info('Token fetched and saved to vendor', [
            'vendor_id' => $vendor->id,
            'expires_at' => now()->addMinutes(210)
        ]);
        
        return $token;
    }

    /**
     * Fetch admin token from Magento API
     */
    public function fetchAdminToken(
        string $baseUrl,
        string $username,
        string $password
    ): string {
        $url = rtrim($baseUrl, '/');
        
        Log::info('Fetching token from Magento', [
            'url' => $url . '/rest/V1/integration/admin/token'
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
        ->timeout(15)
        ->post("{$url}/rest/V1/integration/admin/token", [
            'username' => $username,
            'password' => $password,
        ]);

        if ($response->failed()) {
            throw new \Exception(
                sprintf(
                    'Magento token fetch failed for URL %s: %s',
                    $url,
                    $response->body()
                )
            );
        }

        return trim($response->body(), '"');
    }

    // ─────────────────────────────────────────────────────
    // HTTP CLIENT & REQUEST HANDLING
    // ─────────────────────────────────────────────────────

    private function http()
    {
        $this->ensureToken();

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30);
    }

    private function ensureToken(): void
    {
        if (!empty($this->token)) {
            return;
        }
        
        // Token is empty, try to get it from vendor again
        $this->token = $this->getTokenFromVendor($this->vendor);
    }

    /**
     * Make HTTP request to Magento API with automatic token refresh on 401
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = "{$this->baseUrl}/rest/V1/{$endpoint}";

        Log::info('MAGENTO API CALL', [
            'method' => $method,
            'endpoint' => $endpoint,
            'vendor_id' => $this->vendor->id ?? 'unknown',
        ]);

        $response = match ($method) {
            'GET' => $this->http()->get($url, $data),
            'POST' => $this->http()->post($url, $data),
            'PUT' => $this->http()->put($url, $data),
            'DELETE' => $this->http()->delete($url),
        };

        Log::info('MAGENTO API RESPONSE', [
            'status' => $response->status(),
            'success' => $response->successful(),
        ]);

        // Token expired - refresh and retry once
        if ($response->status() === 401 && $this->vendor) {
            Log::warning('Magento token expired, refreshing from vendor credentials...');
            
            // Refresh token using vendor's admin credentials
            $this->token = $this->fetchAndSaveToken($this->vendor);

            $response = match ($method) {
                'GET' => $this->http()->get($url, $data),
                'POST' => $this->http()->post($url, $data),
                'PUT' => $this->http()->put($url, $data),
                'DELETE' => $this->http()->delete($url),
            };
        }

        if ($response->failed()) {
            throw new \Exception(
                sprintf(
                    'Magento [%s] failed for vendor %s with status %d: %s',
                    $endpoint,
                    $this->vendor->id ?? 'unknown',
                    $response->status(),
                    $response->body()
                )
            );
        }

        $json = $response->json();

        if (is_array($json)) {
            return $json;
        }
        return ['success' => (bool) $json, 'value' => $json];
    }

    // ─────────────────────────────────────────────────────
    // PUBLIC API METHODS
    // ─────────────────────────────────────────────────────

    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, $query);
    }

    public function post(string $endpoint, array $body = []): array
    {
        return $this->request('POST', $endpoint, $body);
    }

    public function put(string $endpoint, array $body = []): array
    {
        return $this->request('PUT', $endpoint, $body);
    }

    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    // ─────────────────────────────────────────────────────
    // STORE MANAGEMENT (Global)
    // ─────────────────────────────────────────────────────

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

    public function createWebsite(array $data): array
    {
        return $this->post('store/websites', $data);
    }

    public function createStoreGroup(array $data): array
    {
        return $this->post('store/storeGroups', $data);
    }

    public function updateStoreGroup(int $groupId, array $data): array
    {
        return $this->put('store/storeGroups/' . $groupId, $data);
    }

    public function deleteStoreGroup(int $groupId): array
    {
        return $this->delete('store/storeGroups/' . $groupId);
    }

    public function createStoreView(array $data): array
    {
        return $this->post('store/storeViews', $data);
    }

    public function updateStoreView(int $storeViewId, array $data): array
    {
        return $this->put('store/storeViews/' . $storeViewId, $data);
    }

    public function deleteStoreView(int $storeViewId): array
    {
        return $this->delete('store/storeViews/' . $storeViewId);
    }

    // ─────────────────────────────────────────────────────
    // ORDER MANAGEMENT (Global)
    // ─────────────────────────────────────────────────────

    public function getOrders(int $page = 1, int $size = 20): array
    {
        return $this->get('orders', [
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]' => $size,
            'searchCriteria[sortOrders][0][field]' => 'created_at',
            'searchCriteria[sortOrders][0][direction]' => 'DESC',
        ]);
    }

    public function getOrder(int $orderId): array
    {
        return $this->get('orders/' . $orderId);
    }

    // ─────────────────────────────────────────────────────
    // CUSTOMER MANAGEMENT (Global)
    // ─────────────────────────────────────────────────────

    public function getCustomers(int $page = 1, int $size = 50): array
    {
        return $this->get('customers/search', [
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]' => $size,
        ]);
    }

    public function getCustomer(int $customerId): array
    {
        return $this->get('customers/' . $customerId);
    }

    // ─────────────────────────────────────────────────────
    // CATEGORY MANAGEMENT (Global)
    // ─────────────────────────────────────────────────────

    public function getCategories(array $params = []): array
    {
        return $this->get('categories', $params);
    }

    public function getCategory(int $categoryId): array
    {
        return $this->get('categories/' . $categoryId);
    }

    public function createCategory(array $categoryData): array
    {
        return $this->post('categories', $categoryData);
    }

    public function updateCategory(int $categoryId, array $categoryData): array
    {
        if (!isset($categoryData['category']['id'])) {
            $categoryData['category']['id'] = $categoryId;
        }

        unset($categoryData['category']['level']);
        unset($categoryData['category']['path']);
        unset($categoryData['category']['children']);
        unset($categoryData['category']['created_at']);
        unset($categoryData['category']['updated_at']);

        return $this->put('categories/' . $categoryId, $categoryData);
    }

    public function deleteCategory(int $categoryId): array
    {
        return $this->delete('categories/' . $categoryId);
    }

    public function getCategoryTree(?int $rootCategoryId = null, ?int $storeId = null, int $depth = 5): array
    {
        $params = ['depth' => $depth];

        if ($rootCategoryId) {
            $params['rootCategoryId'] = $rootCategoryId;
        }

        if ($storeId) {
            $params['storeId'] = $storeId;
        }

        return $this->get('categories', $params);
    }

    // ─────────────────────────────────────────────────────
    // COUPON & SALES RULE MANAGEMENT (Global)
    // ─────────────────────────────────────────────────────

    public function createSalesRule(array $ruleData): array
    {
        return $this->post('salesRules', $ruleData);
    }

    public function updateSalesRule(int $ruleId, array $ruleData): array
    {
        if (!isset($ruleData['rule']['rule_id'])) {
            $ruleData['rule']['rule_id'] = $ruleId;
        }

        return $this->put('salesRules/' . $ruleId, $ruleData);
    }

    public function deleteSalesRule(int $ruleId): array
    {
        return $this->delete('salesRules/' . $ruleId);
    }

    public function getSalesRule(int $ruleId): array
    {
        return $this->get('salesRules/' . $ruleId);
    }

    public function searchSalesRules(array $criteria = []): array
    {
        $query = [];
        $filterIndex = 0;

        if (!empty($criteria['name'])) {
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'name';
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $criteria['name'];
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'like';
            $filterIndex++;
        }

        if (!empty($criteria['is_active'])) {
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'is_active';
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $criteria['is_active'] ? '1' : '0';
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'eq';
            $filterIndex++;
        }

        if (!empty($criteria['page_size'])) {
            $query['searchCriteria[pageSize]'] = $criteria['page_size'];
        }

        if (!empty($criteria['current_page'])) {
            $query['searchCriteria[currentPage]'] = $criteria['current_page'];
        }

        return $this->get('salesRules/search', $query);
    }

    public function createCoupon(array $couponData): array
    {
        return $this->post('coupons', $couponData);
    }

    public function updateCoupon(int $couponId, array $couponData): array
    {
        if (!isset($couponData['coupon']['coupon_id'])) {
            $couponData['coupon']['coupon_id'] = $couponId;
        }

        return $this->put('coupons/' . $couponId, $couponData);
    }

    public function deleteCoupon(int $couponId): array
    {
        return $this->delete('coupons/' . $couponId);
    }

    public function getCoupon(int $couponId): array
    {
        return $this->get('coupons/' . $couponId);
    }

    public function searchCoupons(array $criteria = []): array
    {
        $query = [];
        $filterIndex = 0;

        if (!empty($criteria['rule_id'])) {
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'rule_id';
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $criteria['rule_id'];
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'eq';
            $filterIndex++;
        }

        if (!empty($criteria['code'])) {
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'code';
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $criteria['code'];
            $query["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'eq';
            $filterIndex++;
        }

        if (!empty($criteria['page_size'])) {
            $query['searchCriteria[pageSize]'] = $criteria['page_size'];
        }

        if (!empty($criteria['current_page'])) {
            $query['searchCriteria[currentPage]'] = $criteria['current_page'];
        }

        return $this->get('coupons/search', $query);
    }

    public function generateCoupons(array $data): array
    {
        return $this->post('coupons/generate', $data);
    }
}
