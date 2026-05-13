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
            $query->where(function($q) use ($search) {
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
            $magentoData = ['page' => [
                'identifier' => $data['identifier'],
                'title' => $data['title'],
                'content' => $data['content'] ?? '',
                'active' => $data['is_active'] ?? true,
            ]];

            // Optional fields
            if (isset($data['page_layout'])) {
                $magentoData['page']['page_layout'] = $data['page_layout'];
            }
            if (isset($data['content_heading'])) {
                $magentoData['page']['content_heading'] = $data['content_heading'];
            }
            if (isset($data['meta_title'])) {
                $magentoData['page']['meta_title'] = $data['meta_title'];
            }
            if (isset($data['meta_keywords'])) {
                $magentoData['page']['meta_keywords'] = $data['meta_keywords'];
            }
            if (isset($data['meta_description'])) {
                $magentoData['page']['meta_description'] = $data['meta_description'];
            }
            if (isset($data['sort_order'])) {
                $magentoData['page']['sort_order'] = (string) $data['sort_order'];
            }

            $magentoPage = $this->createInMagento($magentoData);
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
    public function updatePage(string $uuid, array $data): array
    {
        $localPage = CmsPage::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
        
        DB::beginTransaction();
        
        try {
            $magentoData = ['page' => []];
            
            $fields = ['identifier', 'title', 'content', 'page_layout', 'content_heading', 
                       'meta_title', 'meta_keywords', 'meta_description', 'sort_order'];
            
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $magentoData['page'][$field] = $field === 'sort_order' ? (string) $data[$field] : $data[$field];
                }
            }
            
            if (isset($data['is_active'])) {
                $magentoData['page']['active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }
            
            $updatedMagentoPage = $this->updateInMagento($localPage->magento_id, $magentoData);
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
}
