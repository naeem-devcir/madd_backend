<?php

namespace App\Services\Store;

use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\Integration\MagentoService;
use Carbon\Carbon;

class StoreService
{
    protected ?Vendor $vendor = null;
    protected ?MagentoService $magentoService = null;

    public function forVendor(Vendor $vendor): self
    {
        $this->vendor = $vendor;
        $this->magentoService = new MagentoService($vendor);
        return $this;
    }

    protected function magento(): MagentoService
    {
        if (!$this->magentoService) {
            throw new \RuntimeException('Vendor not set. Call forVendor() first.');
        }
        return $this->magentoService;
    }

    /**
     * Sync all stores from Magento to local database
     * 
     * @return array {
     *     'synced_count' => int,
     *     'skipped_count' => int,
     *     'failed_count' => int,
     *     'stores' => array,
     *     'errors' => array
     * }
     */
    public function syncAllStores(): array
    {
        Log::info('Starting store sync from Magento', [
            'vendor_id' => $this->vendor->id,
            'vendor_uuid' => $this->vendor->uuid,
            'vendor_name' => $this->vendor->company_name
        ]);

        $result = [
            'synced_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'stores' => [],
            'errors' => []
        ];

        try {
            // Fetch stores from Magento
            $magentoStores = $this->fetchMagentoStores();
            
            if (empty($magentoStores)) {
                Log::warning('No stores found in Magento', [
                    'vendor_id' => $this->vendor->id
                ]);
                return $result;
            }

            Log::info('Fetched stores from Magento', [
                'vendor_id' => $this->vendor->id,
                'count' => count($magentoStores)
            ]);

            // Process each store
            foreach ($magentoStores as $magentoStore) {
                try {
                    $syncResult = $this->syncSingleStore($magentoStore);
                    
                    if ($syncResult['action'] === 'created') {
                        $result['synced_count']++;
                        $result['stores'][] = $syncResult['store'];
                    } elseif ($syncResult['action'] === 'skipped') {
                        $result['skipped_count']++;
                    }
                } catch (\Exception $e) {
                    $result['failed_count']++;
                    $errorMsg = sprintf(
                        'Failed to sync store %s: %s',
                        $magentoStore['code'] ?? 'unknown',
                        $e->getMessage()
                    );
                    $result['errors'][] = $errorMsg;
                    
                    Log::error('Failed to sync individual store', [
                        'vendor_id' => $this->vendor->id,
                        'magento_store' => $magentoStore,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('Store sync completed', [
                'vendor_id' => $this->vendor->id,
                'synced' => $result['synced_count'],
                'skipped' => $result['skipped_count'],
                'failed' => $result['failed_count']
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to sync stores from Magento', [
                'vendor_id' => $this->vendor->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $result['errors'][] = 'Sync failed: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Fetch all stores from Magento using Magento API
     * 
     * @return array
     * @throws \Exception
     */
    protected function fetchMagentoStores(): array
    {
        try {
            // Magento API endpoint for stores
            // GET /rest/V1/store/storeViews
            // This returns all store views in Magento
            $stores = $this->magento()->get('store/storeViews');
            
            if (!is_array($stores)) {
                throw new \Exception('Invalid response format from Magento API');
            }

            // If we need store groups and websites for additional data
            $storeGroups = $this->magento()->get('store/storeGroups');
            $websites = $this->magento()->get('store/websites');

            // Enhance store data with additional information
            foreach ($stores as &$store) {
                $store['store_group'] = $this->findStoreGroup($store['store_group_id'] ?? null, $storeGroups);
                $store['website'] = $this->findWebsite($store['website_id'] ?? null, $websites);
            }

            return $stores;

        } catch (\Exception $e) {
            throw new \Exception('Failed to fetch stores from Magento API: ' . $e->getMessage());
        }
    }

    /**
     * Find store group by ID
     */
    protected function findStoreGroup($groupId, array $storeGroups): ?array
    {
        if (!$groupId) {
            return null;
        }

        foreach ($storeGroups as $group) {
            if (($group['id'] ?? $group['store_group_id'] ?? null) == $groupId) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Find website by ID
     */
    protected function findWebsite($websiteId, array $websites): ?array
    {
        if (!$websiteId) {
            return null;
        }

        foreach ($websites as $website) {
            if (($website['id'] ?? $website['website_id'] ?? null) == $websiteId) {
                return $website;
            }
        }

        return null;
    }

    /**
     * Sync a single store - checks existence and creates if needed
     * 
     * @param array $magentoStore
     * @return array
     */
    protected function syncSingleStore(array $magentoStore): array
    {
        // Check if store already exists by Magento store ID
        $existingStore = VendorStore::where('vendor_id', $this->vendor->id)
            ->where('magento_store_id', $magentoStore['id'] ?? null)
            ->first();

        if ($existingStore) {
            Log::info('Store already exists, skipping', [
                'vendor_id' => $this->vendor->id,
                'magento_store_id' => $magentoStore['id'] ?? null,
                'store_name' => $existingStore->store_name
            ]);

            return [
                'action' => 'skipped',
                'store' => $existingStore
            ];
        }

        // Additional check by store code if magento_store_id is not available
        if (empty($magentoStore['id']) && !empty($magentoStore['code'])) {
            $existingByCode = VendorStore::where('vendor_id', $this->vendor->id)
                ->where('store_slug', $magentoStore['code'])
                ->first();

            if ($existingByCode) {
                Log::info('Store already exists by code, skipping', [
                    'vendor_id' => $this->vendor->id,
                    'store_code' => $magentoStore['code']
                ]);

                return [
                    'action' => 'skipped',
                    'store' => $existingByCode
                ];
            }
        }

        // Create new store
        $store = $this->createStoreFromMagentoData($magentoStore);
        
        Log::info('New store created from Magento sync', [
            'vendor_id' => $this->vendor->id,
            'store_id' => $store->id,
            'store_uuid' => $store->uuid,
            'store_name' => $store->store_name,
            'magento_store_id' => $store->magento_store_id
        ]);

        return [
            'action' => 'created',
            'store' => $store
        ];
    }

    /**
     * Create a new VendorStore record from Magento store data
     * 
     * @param array $magentoStore
     * @return VendorStore
     */
    protected function createStoreFromMagentoData(array $magentoStore): VendorStore
    {
        $storeData = $this->mapMagentoStoreToVendorStore($magentoStore);
        
        return DB::transaction(function () use ($storeData) {
            return VendorStore::create($storeData);
        });
    }

    /**
     * Map Magento store data to VendorStore model attributes
     * 
     * @param array $magentoStore
     * @return array
     */
    protected function mapMagentoStoreToVendorStore(array $magentoStore): array
    {
        // Extract core store information
        $storeCode = $magentoStore['code'] ?? $magentoStore['store_code'] ?? null;
        $storeName = $magentoStore['name'] ?? $magentoStore['store_name'] ?? 'Unnamed Store';
        
        // Generate slug from store code or name
        $slug = $storeCode ? Str::slug($storeCode) : Str::slug($storeName);
        
        // Get store group and website data
        $storeGroup = $magentoStore['store_group'] ?? [];
        $website = $magentoStore['website'] ?? [];
        
        // Determine store settings from Magento data
        $countryCode = $this->extractCountryCode($magentoStore, $storeGroup, $website);
        $languageCode = $this->extractLanguageCode($magentoStore, $storeGroup);
        $currencyCode = $this->extractCurrencyCode($storeGroup, $website);
        $timezone = $this->extractTimezone($storeGroup, $website);

        return [
            'uuid' => (string) Str::uuid(),
            'vendor_id' => $this->vendor->id,
            'store_name' => $storeName,
            'store_slug' => $slug,
            'country_code' => $countryCode,
            'language_code' => $languageCode,
            'currency_code' => $currencyCode,
            'timezone' => $timezone,
            'domain_id' => null, // Will be set separately if needed
            'subdomain' => $storeCode ?: null,
            'magento_store_id' => $magentoStore['id'] ?? $magentoStore['store_id'] ?? null,
            'magento_store_group_id' => $magentoStore['store_group_id'] ?? $storeGroup['id'] ?? null,
            'magento_website_id' => $magentoStore['website_id'] ?? $website['id'] ?? null,
            'theme_id' => null,
            'status' => 'active', // Default status
            'sales_policy_id' => null,
            'logo_url' => null,
            'favicon_url' => null,
            'banner_url' => null,
            'primary_color' => null,
            'secondary_color' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'seo_meta_title' => null,
            'seo_meta_description' => null,
            'seo_settings' => null,
            'payment_methods' => null,
            'shipping_methods' => null,
            'tax_settings' => null,
            'social_links' => null,
            'google_analytics_id' => null,
            'facebook_pixel_id' => null,
            'custom_css' => null,
            'custom_js' => null,
            'is_demo' => false,
            'address' => null,
            'metadata' => json_encode([
                'synced_from_magento' => true,
                'magento_synced_at' => Carbon::now()->toISOString(),
                'original_magento_data' => $magentoStore
            ]),
            'activated_at' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }

    /**
     * Extract country code from Magento data
     */
    protected function extractCountryCode(array $magentoStore, array $storeGroup, array $website): ?string
    {
        // Try multiple sources for country code
        $sources = [
            $magentoStore['country_code'] ?? null,
            $magentoStore['locale'] ?? null,
            $storeGroup['country_code'] ?? null,
            $website['country_code'] ?? null,
            $this->vendor->default_country_code ?? null
        ];

        foreach ($sources as $source) {
            if ($source && strlen($source) >= 2) {
                return substr($source, 0, 2);
            }
        }

        return config('app.fallback_country', 'US');
    }

    /**
     * Extract language code from Magento data
     */
    protected function extractLanguageCode(array $magentoStore, array $storeGroup): ?string
    {
        $sources = [
            $magentoStore['language_code'] ?? null,
            $magentoStore['locale'] ?? null,
            $storeGroup['language_code'] ?? null,
            $this->vendor->default_language_code ?? null
        ];

        foreach ($sources as $source) {
            if ($source && strlen($source) >= 2) {
                return substr($source, 0, 2);
            }
        }

        return config('app.fallback_locale', 'en');
    }

    /**
     * Extract currency code from Magento data
     */
    protected function extractCurrencyCode(array $storeGroup, array $website): ?string
    {
        $sources = [
            $storeGroup['currency_code'] ?? null,
            $website['currency_code'] ?? null,
            $this->vendor->default_currency_code ?? null
        ];

        foreach ($sources as $source) {
            if ($source && strlen($source) === 3) {
                return strtoupper($source);
            }
        }

        return 'USD';
    }

    /**
     * Extract timezone from Magento data
     */
    protected function extractTimezone(array $storeGroup, array $website): ?string
    {
        $sources = [
            $storeGroup['timezone'] ?? null,
            $website['timezone'] ?? null,
            $this->vendor->default_timezone ?? null
        ];

        foreach ($sources as $source) {
            if ($source) {
                return $source;
            }
        }

        return config('app.timezone', 'UTC');
    }

    /**
     * Get single store by UUID (READ)
     */
    public function getStoreByUuid(string $uuid): ?array
    {
        $store = VendorStore::where('vendor_id', $this->vendor->id)
            ->where('uuid', $uuid)
            ->first();

        return $store ? $store->toArray() : null;
    }

    /**
     * Get store by Magento ID (READ)
     */
    public function getStoreByMagentoId(int $magentoStoreId): ?array
    {
        $store = VendorStore::where('vendor_id', $this->vendor->id)
            ->where('magento_store_id', $magentoStoreId)
            ->first();

        return $store ? $store->toArray() : null;
    }
}