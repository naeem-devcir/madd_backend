<?php

namespace App\Services\Store;

use App\Models\Config\Domain;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StoreService
{
    public function create(array $data): VendorStore
    {
        $vendor = Vendor::findOrFail($data['vendor_id']);
        $this->ensureVendorMagentoWebsiteConfigured($vendor, $data);
        $storeValues = $this->storeValues($vendor, $data);

        try {
            $magentoData = $this->magentoForVendor($vendor)->createStoreGroupFromData($vendor, $storeValues);
            if (empty($magentoData['store_group_id'])) {
                throw new \Exception('Magento store was created but no store/group ID was returned.');
            }
        } catch (\Throwable $e) {
            Log::error('Magento store creation failed; Laravel store was not created', [
                'vendor_id' => $vendor->id,
                'store_name' => $data['store_name'] ?? null,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        try {
            return DB::transaction(function () use ($data, $magentoData, $storeValues) {
                $store = VendorStore::create(array_merge($storeValues, [
                    'magento_store_id' => $magentoData['store_id'],
                    'magento_store_group_id' => $magentoData['store_group_id'],
                    'magento_website_id' => $magentoData['website_id'],
                    'metadata' => $this->mergeMagentoMetadata(null, $magentoData, $storeValues['metadata'] ?? []),
                ]));
                $this->attachDomains($store, $data);

                return $store->fresh(['vendor', 'domain', 'theme']);
            });
        } catch (\Throwable $e) {
            Log::error('Laravel store creation failed after Magento store was created', [
                'vendor_id' => $vendor->id,
                'magento_store_group_id' => $magentoData['store_group_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            if (! empty($magentoData['store_group_id'])) {
                try {
                    $this->magentoForVendor($vendor)->deleteStoreGroupById((int) $magentoData['store_group_id']);
                } catch (\Throwable $deleteException) {
                    Log::critical('Failed to rollback Magento store after Laravel store creation failure', [
                        'vendor_id' => $vendor->id,
                        'magento_store_group_id' => $magentoData['store_group_id'],
                        'error' => $deleteException->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }

    public function update(VendorStore $store, array $data): VendorStore
    {
        $magentoStore = $store->replicate();
        $magentoStore->exists = true;
        $magentoStore->id = $store->id;
        $magentoStore->forceFill($data);
        $magentoStore->magento_website_id = $magentoStore->magento_website_id ?: $store->vendor->magento_website_id;

        if ($magentoStore->status === 'active' && $store->status !== 'active') {
            $data['activated_at'] = now();
        }

        if ($store->magento_store_id || $store->magento_store_group_id) {
            $magentoResult = $this->magentoForVendor($store->vendor)->updateStoreGroup($magentoStore);
        } else {
            $this->ensureVendorMagentoWebsiteConfigured($store->vendor, $data);
            $magentoResult = $this->magentoForVendor($store->vendor)->createStoreGroupFromData($store->vendor, [
                'store_name' => $magentoStore->store_name,
                'store_slug' => $magentoStore->store_slug,
                'status' => $magentoStore->status,
            ]);
            if (empty($magentoResult['store_group_id'])) {
                throw new \Exception('Magento store was created but no store/group ID was returned.');
            }

            $data['magento_store_id'] = $magentoResult['store_id'];
            $data['magento_store_group_id'] = $magentoResult['store_group_id'];
            $data['magento_website_id'] = $magentoResult['website_id'];
        }

        return DB::transaction(function () use ($store, $data, $magentoResult) {
            $store->update($data);
            $store->update([
                'metadata' => $this->mergeMagentoMetadata($store->fresh(), $magentoResult),
            ]);

            return $store->fresh(['vendor', 'domain', 'theme']);
        });
    }

    public function delete(VendorStore $store, bool $force = false): array
    {
        return DB::transaction(function () use ($store, $force) {
            $magentoResult = $this->magentoForVendor($store->vendor)->deleteStoreGroup($store);

            if ($force) {
                Domain::where('vendor_store_id', $store->id)->forceDelete();
                $store->forceDelete();
            } else {
                Domain::where('vendor_store_id', $store->id)->delete();
                $store->delete();
            }

            return $magentoResult;
        });
    }

    public function getVendorStoreLimit(Vendor $vendor): int
    {
        return $vendor->plan->max_stores ?? 1;
    }

    public function ensureVendorMagentoWebsiteConfigured(Vendor $vendor, array $data = []): void
    {
        if (! $vendor->magento_website_id && ! empty($data['magento_website_id'])) {
            $vendor->forceFill(['magento_website_id' => $data['magento_website_id']])->save();
            $vendor->refresh();
        }

        if ($vendor->magento_website_id) {
            return;
        }

        throw new \Exception('Vendor Magento website ID is not configured. Magento installation automation is intentionally not implemented.');
    }

    private function storeValues(Vendor $vendor, array $data, array $magentoData = []): array
    {
        $status = $data['status'] ?? 'inactive';
        $slug = $this->uniqueSlug($vendor, $data['store_slug'] ?? Str::slug($data['store_name']));

        return [
            'uuid' => $data['uuid'] ?? (string) Str::uuid(),
            'vendor_id' => $vendor->id,
            'store_name' => $data['store_name'],
            'store_slug' => $slug,
            'country_code' => $data['country_code'] ?? $vendor->country_code ?? 'US',
            'language_code' => $data['language_code'] ?? 'en',
            'currency_code' => $data['currency_code'] ?? 'EUR',
            'timezone' => $data['timezone'] ?? $vendor->timezone ?? 'UTC',
            'subdomain' => $data['subdomain'] ?? null,
            'domain_id' => $data['domain_id'] ?? null,
            'magento_store_id' => $magentoData['store_id'] ?? $data['magento_store_id'] ?? null,
            'magento_store_group_id' => $magentoData['store_group_id'] ?? $data['magento_store_group_id'] ?? null,
            'magento_website_id' => $magentoData['website_id'] ?? $data['magento_website_id'] ?? $vendor->magento_website_id,
            'theme_id' => $data['theme_id'] ?? null,
            'status' => $status,
            'sales_policy_id' => $data['sales_policy_id'] ?? null,
            'logo_url' => $data['logo_url'] ?? null,
            'favicon_url' => $data['favicon_url'] ?? null,
            'banner_url' => $data['banner_url'] ?? null,
            'primary_color' => $data['primary_color'] ?? '#000000',
            'secondary_color' => $data['secondary_color'] ?? '#666666',
            'contact_email' => $data['contact_email'] ?? $vendor->contact_email,
            'contact_phone' => $data['contact_phone'] ?? $vendor->phone,
            'seo_meta_title' => $data['seo_meta_title'] ?? null,
            'seo_meta_description' => $data['seo_meta_description'] ?? null,
            'seo_settings' => $data['seo_settings'] ?? null,
            'payment_methods' => $data['payment_methods'] ?? null,
            'shipping_methods' => $data['shipping_methods'] ?? null,
            'tax_settings' => $data['tax_settings'] ?? null,
            'social_links' => $data['social_links'] ?? null,
            'google_analytics_id' => $data['google_analytics_id'] ?? null,
            'facebook_pixel_id' => $data['facebook_pixel_id'] ?? null,
            'custom_css' => $data['custom_css'] ?? null,
            'custom_js' => $data['custom_js'] ?? null,
            'is_demo' => $data['is_demo'] ?? false,
            'address' => $data['address'] ?? null,
            'metadata' => $this->mergeMagentoMetadata(null, $magentoData, $data['metadata'] ?? []),
            'activated_at' => $status === 'active' ? now() : null,
        ];
    }

    private function attachDomains(VendorStore $store, array $data): void
    {
        if (! empty($data['domain'])) {
            $domain = Domain::firstOrCreate(
                ['domain' => $data['domain']],
                [
                    'uuid' => (string) Str::uuid(),
                    'vendor_store_id' => $store->id,
                    'type' => 'vendor_custom',
                    'verification_token' => Str::random(32),
                    'dns_verified' => false,
                    'ssl_status' => 'pending',
                    'is_primary' => true,
                ]
            );

            $store->update(['domain_id' => $domain->id]);
            return;
        }

        if (! empty($data['subdomain'])) {
            $domain = Domain::create([
                'uuid' => (string) Str::uuid(),
                'vendor_store_id' => $store->id,
                'domain' => $data['subdomain'] . '.' . config('app.domain', 'example.com'),
                'type' => 'madd_subdomain',
                'verification_token' => Str::random(32),
                'dns_verified' => true,
                'dns_verified_at' => now(),
                'ssl_status' => 'active',
                'is_primary' => true,
            ]);

            $store->update(['domain_id' => $domain->id]);
        }
    }

    private function uniqueSlug(Vendor $vendor, string $slug): string
    {
        $base = Str::slug($slug) ?: 'store';
        $candidate = $base;
        $counter = 1;

        while (VendorStore::withTrashed()->where('vendor_id', $vendor->id)->where('store_slug', $candidate)->exists()) {
            $candidate = $base . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function mergeMagentoMetadata(?VendorStore $store, array $magentoData, array $baseMetadata = []): array
    {
        $metadata = is_array($store?->metadata) ? $store->metadata : $baseMetadata;

        if (! empty($magentoData)) {
            $metadata['magento'] = $magentoData;
            $metadata['magento_synced_at'] = now()->toIso8601String();
        }

        return $metadata;
    }

    private function magentoForVendor(Vendor $vendor): MagentoService
    {
        return MagentoService::forVendor($vendor);
    }
}
