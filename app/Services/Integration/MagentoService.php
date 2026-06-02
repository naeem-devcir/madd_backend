<?php
namespace App\Services\Integration;

use App\Models\Vendor\Vendor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MagentoService - Handles core Magento API integration only
 * Responsibilities:
 * - Fetch and manage admin tokens
 * - Store/retrieve vendor credentials from database
 * - Provide reusable HTTP request functionality for other services
 * - NO business-specific logic (orders, products, etc.)
 */
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
        $this->vendor = $vendor;
        
        if (empty($vendor->magento_base_url)) {
            throw new \Exception(
                sprintf(
                    'Magento base URL is missing for vendor "%s" (ID: %s). Please update the vendor\'s magento_base_url field.',
                    $vendor->company_name ?? 'Unknown',
                    $vendor->id ?? 'null'
                )
            );
        }
        
        $this->baseUrl = rtrim($vendor->magento_base_url, '/');
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
        if (empty($vendor->magento_admin_username) || empty($vendor->magento_admin_pass)) {
            throw new \Exception(
                sprintf(
                    'Cannot fetch token: Admin credentials missing for vendor "%s" (ID: %s)',
                    $vendor->company_name ?? 'Unknown',
                    $vendor->id ?? 'null'
                )
            );
        }
        
        $token = $this->fetchAdminToken(
            $vendor->magento_base_url,
            $vendor->magento_admin_username,
            $vendor->magento_admin_pass,
        );
        
        $vendor->update([
            'magento_admin_token' => $token,
            'magento_token_expires_at' => now()->addMinutes(210),
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
    public function fetchAdminToken(string $baseUrl, string $username, string $password): string
    {
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

    /**
     * Get the current valid token (refreshes if needed)
     */
    public function getToken(): string
    {
        $this->ensureToken();
        return $this->token;
    }

    /**
     * Get the vendor instance
     */
    public function getVendor(): ?Vendor
    {
        return $this->vendor;
    }

    /**
     * Get base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    // ─────────────────────────────────────────────────────
    // HTTP CLIENT & REQUEST HANDLING (REUSABLE FOR OTHER SERVICES)
    // ─────────────────────────────────────────────────────

    /**
     * Get HTTP client with authentication
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
     * Ensure token is valid
     */
    private function ensureToken(): void
    {
        if (!empty($this->token)) {
            return;
        }
        
        $this->token = $this->getTokenFromVendor($this->vendor);
    }

    /**
     * Make HTTP request to Magento API with automatic token refresh on 401
     * This is the core reusable method for all API calls
     */
    public function request(string $method, string $endpoint, array $data = [], array $query = []): array
    {
        // Build URL - handle query parameters
        $url = "{$this->baseUrl}/rest/V1/{$endpoint}";
        
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        Log::info('Magento API Call', [
            'method' => $method,
            'endpoint' => $endpoint,
            'vendor_id' => $this->vendor->id ?? 'unknown',
        ]);

        $response = match ($method) {
            'GET' => $this->http()->get($url),
            'POST' => $this->http()->post($url, $data),
            'PUT' => $this->http()->put($url, $data),
            'DELETE' => $this->http()->delete($url),
        };

        // Token expired - refresh and retry once
        if ($response->status() === 401 && $this->vendor) {
            Log::warning('Magento token expired, refreshing from vendor credentials...');
            
            $this->token = $this->fetchAndSaveToken($this->vendor);

            $response = match ($method) {
                'GET' => $this->http()->get($url),
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
    // PUBLIC REQUEST METHODS FOR OTHER SERVICES
    // ─────────────────────────────────────────────────────

    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, [], $query);
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
}