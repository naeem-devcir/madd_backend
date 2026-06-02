<?php

namespace App\Services\Cms;

use App\Models\CmsPage;
use App\Models\Vendor\Vendor;
use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CmsPageService
{
    protected ?Vendor $vendor = null;
    protected ?MagentoService $magentoService = null;

    public function forVendor(Vendor $vendor): self
    {
        $this->vendor = $vendor;
        $this->magentoService = MagentoService::forVendor($vendor);
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
     * Get all CMS pages from local DB (READ)
     */
    public function getAllPages(array $filters = []): array
    {
        $query = CmsPage::forVendor($this->vendor->id);

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['identifier'])) {
            $query->where('identifier', 'like', "%{$filters['identifier']}%");
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('meta_title', 'like', "%{$search}%");
            });
        }

        if (isset($filters['store_id'])) {
            $query->forStore($filters['store_id']);
        }

        $sortBy = $filters['sort_by'] ?? 'sort_order';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        if (isset($filters['per_page'])) {
            $pages = $query->paginate($filters['per_page']);
            return [
                'data' => $pages->items(),
                'total' => $pages->total(),
                'current_page' => $pages->currentPage(),
                'per_page' => $pages->perPage(),
                'last_page' => $pages->lastPage()
            ];
        }

        return [
            'data' => $query->get()->toArray(),
            'total' => $query->count()
        ];
    }

    /**
     * Get single CMS page by UUID from local DB (READ)
     */
    public function getPageByUuid(string $uuid): ?array
    {
        $page = CmsPage::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->first();

        return $page ? $page->toArray() : null;
    }

    /**
     * Get page by identifier (READ)
     */
    public function getPageByIdentifier(string $identifier): ?array
    {
        $page = CmsPage::forVendor($this->vendor->id)
            ->where('identifier', $identifier)
            ->first();

        return $page ? $page->toArray() : null;
    }

    /**
     * Create CMS page (WRITE: Magento → Local)
     */
    public function createPage(array $data): array
    {
        DB::beginTransaction();

        try {
            // Start with required fields
            $pageData = [
                'identifier' => $data['identifier'],
                'title' => $data['title'],
                'active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)
            ];

            // Add optional fields only if they have values
            if (!empty($data['page_layout'])) {
                $pageData['page_layout'] = $data['page_layout'];
            }

            if (!empty($data['content_heading'])) {
                $pageData['content_heading'] = $data['content_heading'];
            }

            if (!empty($data['content'])) {
                $pageData['content'] = $data['content'];
            }

            if (!empty($data['meta_title'])) {
                $pageData['meta_title'] = $data['meta_title'];
            } elseif (!empty($data['title'])) {
                $pageData['meta_title'] = $data['title'];
            }

            if (!empty($data['meta_keywords'])) {
                $pageData['meta_keywords'] = $data['meta_keywords'];
            }

            if (!empty($data['meta_description'])) {
                $pageData['meta_description'] = $data['meta_description'];
            }

            if (isset($data['sort_order']) && $data['sort_order'] !== '') {
                $pageData['sort_order'] = (int)$data['sort_order'];
            }

            if (!empty($data['custom_theme'])) {
                $pageData['custom_theme'] = $data['custom_theme'];
            }

            if (!empty($data['custom_root_template'])) {
                $pageData['custom_root_template'] = $data['custom_root_template'];
            }

            // Handle XML fields - ONLY if they have valid content
            if (!empty($data['layout_update_xml']) && trim($data['layout_update_xml']) !== '') {
                $sanitizedXml = $this->sanitizeXml($data['layout_update_xml']);
                if ($sanitizedXml !== '') {
                    $pageData['layout_update_xml'] = $sanitizedXml;
                }
            }

            // Handle custom_layout_update_xml - ONLY if has valid content
            if (!empty($data['custom_layout_update_xml']) && trim($data['custom_layout_update_xml']) !== '') {
                $sanitizedXml = $this->sanitizeXml($data['custom_layout_update_xml']);
                if ($sanitizedXml !== '') {
                    $pageData['custom_layout_update_xml'] = $sanitizedXml;
                }
            }

            // Handle date fields
            if (!empty($data['custom_theme_from']) && trim($data['custom_theme_from']) !== '') {
                $pageData['custom_theme_from'] = $data['custom_theme_from'];
            }

            if (!empty($data['custom_theme_to']) && trim($data['custom_theme_to']) !== '') {
                $pageData['custom_theme_to'] = $data['custom_theme_to'];
            }

            // Wrap in 'page' key as Magento expects
            $magentoData = ['page' => $pageData];

            // Log the request for debugging
            Log::info('Creating CMS Page in Magento', ['request_data' => $magentoData]);

            // Create in Magento
            $magentoPage = $this->createInMagento($magentoData);

            // Sync to local DB
            $localPage = $this->syncFromMagento($magentoPage);

            DB::commit();

            return [
                'success' => true,
                'data' => [
                    'uuid' => $localPage->uuid,
                    'internal_id' => $localPage->id,
                    'magento_id' => $localPage->magento_id,
                    'identifier' => $localPage->identifier,
                    'title' => $localPage->title,
                ],
                'message' => 'CMS Page created successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CMS Page creation failed', [
                'vendor_id' => $this->vendor->id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Failed to create CMS Page: ' . $e->getMessage());
        }
    }

    /**
     * Update CMS page (WRITE: Magento → Local)
     */
    /**
     * Update CMS page (WRITE: Magento → Local)
     */
    public function updatePage(string $uuid, array $data): array
    {
        $localPage = CmsPage::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $pageData = [];

            // Only include fields that are provided and have values
            $stringFields = [
                'identifier',
                'title',
                'page_layout',
                'meta_title',
                'meta_keywords',
                'meta_description',
                'content_heading',
                'content',
                'custom_theme',
                'custom_root_template'
            ];

            foreach ($stringFields as $field) {
                if (isset($data[$field]) && !empty(trim($data[$field]))) {
                    $pageData[$field] = $data[$field];
                }
            }

            // Handle sort_order
            if (isset($data['sort_order']) && $data['sort_order'] !== '') {
                $pageData['sort_order'] = (int)$data['sort_order'];
            }

            // Handle is_active
            if (isset($data['is_active'])) {
                $pageData['active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }

            // Handle XML fields - ONLY if they have valid content
            if (isset($data['layout_update_xml']) && !empty(trim($data['layout_update_xml']))) {
                $sanitizedXml = $this->sanitizeXml($data['layout_update_xml']);
                if ($sanitizedXml !== '') {
                    $pageData['layout_update_xml'] = $sanitizedXml;
                }
            }

            if (isset($data['custom_layout_update_xml']) && !empty(trim($data['custom_layout_update_xml']))) {
                $sanitizedXml = $this->sanitizeXml($data['custom_layout_update_xml']);
                if ($sanitizedXml !== '') {
                    $pageData['custom_layout_update_xml'] = $sanitizedXml;
                }
            }

            // Handle date fields
            if (isset($data['custom_theme_from']) && !empty(trim($data['custom_theme_from']))) {
                $pageData['custom_theme_from'] = $data['custom_theme_from'];
            }

            if (isset($data['custom_theme_to']) && !empty(trim($data['custom_theme_to']))) {
                $pageData['custom_theme_to'] = $data['custom_theme_to'];
            }

            // Only proceed if there's data to update
            if (empty($pageData)) {
                throw new \Exception('No valid data provided for update');
            }

            $magentoData = ['page' => $pageData];

            // Log the request for debugging
            Log::info('Updating CMS Page in Magento', [
                'magento_id' => $localPage->magento_id,
                'request_data' => $magentoData
            ]);

            // Update in Magento
            $updatedMagentoPage = $this->updateInMagento($localPage->magento_id, $magentoData);

            // Sync to local DB
            $this->syncFromMagento($updatedMagentoPage);

            DB::commit();

            return [
                'success' => true,
                'data' => [
                    'uuid' => $localPage->uuid,
                    'identifier' => $updatedMagentoPage['identifier'] ?? $data['identifier'] ?? $localPage->identifier,
                    'title' => $updatedMagentoPage['title'] ?? $data['title'] ?? $localPage->title,
                ],
                'message' => 'CMS Page updated successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CMS Page update failed', [
                'vendor_id' => $this->vendor->id,
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Failed to update CMS Page: ' . $e->getMessage());
        }
    }

    /**
     * Delete CMS page (WRITE: Magento → Local)
     */
    public function deletePage(string $uuid): array
    {
        $localPage = CmsPage::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $this->deleteFromMagento($localPage->magento_id);
            $localPage->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'CMS Page deleted successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CMS Page deletion failed', [
                'vendor_id' => $this->vendor->id,
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Failed to delete CMS Page: ' . $e->getMessage());
        }
    }

    /**
     * Sync all CMS pages from Magento to local
     */
    public function syncAllPages(): array
    {
        DB::beginTransaction();

        try {
            $magentoPages = $this->magento()->get('cmsPage/search', [
                'searchCriteria[pageSize]' => 100
            ]);

            $syncedCount = 0;
            $errors = [];

            foreach ($magentoPages['items'] ?? [] as $magentoPage) {
                try {
                    $this->syncFromMagento($magentoPage);
                    $syncedCount++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'identifier' => $magentoPage['identifier'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => "Synced {$syncedCount} CMS pages successfully",
                'data' => [
                    'synced_count' => $syncedCount,
                    'errors' => $errors
                ]
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CMS Page sync failed', [
                'vendor_id' => $this->vendor->id,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Failed to sync CMS pages: ' . $e->getMessage());
        }
    }

    protected function createInMagento(array $data): array
    {
        return $this->magento()->post('cmsPage', $data);
    }

    protected function updateInMagento(string $magentoId, array $data): array
    {
        return $this->magento()->put("cmsPage/{$magentoId}", $data);
    }

    protected function deleteFromMagento(string $magentoId): void
    {
        $this->magento()->delete("cmsPage/{$magentoId}");
    }

    protected function syncFromMagento(array $magentoPage): CmsPage
    {
        return CmsPage::updateOrCreate(
            [
                'vendor_id' => $this->vendor->id,
                'magento_id' => $magentoPage['id']
            ],
            [
                'identifier' => $magentoPage['identifier'],
                'title' => $magentoPage['title'],
                'content' => $magentoPage['content'] ?? '',
                'page_layout' => $magentoPage['page_layout'] ?? null,
                'content_heading' => $magentoPage['content_heading'] ?? null,
                'is_active' => $magentoPage['active'] ?? true,
                'sort_order' => (int) ($magentoPage['sort_order'] ?? 0),
                'meta_title' => $magentoPage['meta_title'] ?? null,
                'meta_keywords' => $magentoPage['meta_keywords'] ?? null,
                'meta_description' => $magentoPage['meta_description'] ?? null,
                'custom_theme' => $magentoPage['custom_theme'] ?? null,
                'custom_root_template' => $magentoPage['custom_root_template'] ?? null,
                'layout_update_xml' => $magentoPage['layout_update_xml'] ?? null,
                'custom_layout_update_xml' => $magentoPage['custom_layout_update_xml'] ?? null,
                'custom_theme_from' => $magentoPage['custom_theme_from'] ?? null,
                'custom_theme_to' => $magentoPage['custom_theme_to'] ?? null,
                'magento_data' => $magentoPage,
                'last_synced_at' => now(),
                'magento_updated_at' => $magentoPage['update_time'] ?? now()
            ]
        );
    }



    protected function sanitizeXml(?string $xml): string
    {
        if (empty($xml) || trim($xml) === '') {
            return '';
        }

        // Remove any BOM or invalid characters
        $xml = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $xml);

        // Try to validate XML structure
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            // Return empty string if XML is invalid
            Log::warning('Invalid XML detected, skipping layout update', [
                'xml' => $xml,
                'vendor_id' => $this->vendor->id
            ]);
            return '';
        }

        return $xml;
    }
}
