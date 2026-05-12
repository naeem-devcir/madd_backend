<?php

namespace App\Services\Cms;

use App\Models\CmsBlock;
use App\Models\Vendor\Vendor;

use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CmsBlockService
{
    protected ?Vendor $vendor = null;
    protected ?MagentoService $magentoService = null;

    /**
     * Set vendor for current operation
     */
    public function forVendor(Vendor $vendor): self
    {
        $this->vendor = $vendor;
        $this->magentoService = new MagentoService($vendor);
        return $this;
    }

    /**
     * Get Magento service instance
     */
    protected function magento(): MagentoService
    {
        if (!$this->magentoService) {
            throw new \RuntimeException('Vendor not set. Call forVendor() first.');
        }
        return $this->magentoService;
    }

    /**
     * Get all CMS blocks from local DB (READ)
     */
    public function getAllBlocks(array $filters = []): array
    {
        $query = CmsBlock::forVendor($this->vendor->id);

        // Apply filters
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
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        if (isset($filters['store_id'])) {
            $query->forStore($filters['store_id']);
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // Pagination or all
        if (isset($filters['per_page'])) {
            $blocks = $query->paginate($filters['per_page']);
            return [
                'data' => $blocks->items(),
                'total' => $blocks->total(),
                'current_page' => $blocks->currentPage(),
                'per_page' => $blocks->perPage(),
                'last_page' => $blocks->lastPage()
            ];
        }

        return [
            'data' => $query->get()->toArray(),
            'total' => $query->count()
        ];
    }

    /**
     * Get single CMS block by UUID from local DB (READ)
     */
    public function getBlockByUuid(string $uuid): ?array
    {
        $block = CmsBlock::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->first();

        return $block ? $block->toArray() : null;
    }

    /**
     * Get single CMS block by identifier (READ)
     */
    public function getBlockByIdentifier(string $identifier): ?array
    {
        $block = CmsBlock::forVendor($this->vendor->id)
            ->where('identifier', $identifier)
            ->first();

        return $block ? $block->toArray() : null;
    }

    /**
     * Create CMS block (WRITE: Magento → Local)
     */
    public function createBlock(array $data): array
    {
        DB::beginTransaction();
        
        try {
            // Prepare Magento data
            $magentoData = [
                'block' => [
                    'identifier' => $data['identifier'],
                    'title' => $data['title'],
                    'content' => $data['content'] ?? '',
                    'active' => $data['is_active'] ?? true
                ]
            ];

            // Add store IDs if provided
            if (isset($data['store_ids']) && !empty($data['store_ids'])) {
                $magentoData['block']['store_ids'] = (array) $data['store_ids'];
            }

            // 1. Create in Magento
            $magentoBlock = $this->createInMagento($magentoData);
            
            // 2. Save to local DB
            $localBlock = $this->syncFromMagento($magentoBlock);
            
            DB::commit();
            
            return [
                'success' => true,
                'data' => [
                    'uuid' => $localBlock->uuid,
                    'internal_id' => $localBlock->id,
                    'magento_id' => $localBlock->magento_id,
                    'identifier' => $localBlock->identifier,
                    'title' => $localBlock->title,
                ],
                'message' => 'CMS Block created successfully'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CMS Block creation failed', [
                'vendor_id' => $this->vendor->id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Failed to create CMS Block: ' . $e->getMessage());
        }
    }

    /**
     * Update CMS block (WRITE: Magento → Local)
     */
    public function updateBlock(string $uuid, array $data): array
    {
        $localBlock = CmsBlock::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
        
        DB::beginTransaction();
        
        try {
            // Prepare Magento update data
            $magentoData = ['block' => []];
            
            // Only send fields that are provided
            if (isset($data['identifier'])) {
                $magentoData['block']['identifier'] = $data['identifier'];
            }
            if (isset($data['title'])) {
                $magentoData['block']['title'] = $data['title'];
            }
            if (isset($data['content'])) {
                $magentoData['block']['content'] = $data['content'];
            }
            if (isset($data['is_active'])) {
                $magentoData['block']['active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($data['store_ids'])) {
                $magentoData['block']['store_ids'] = (array) $data['store_ids'];
            }
            
            // 1. Update in Magento
            $updatedMagentoBlock = $this->updateInMagento($localBlock->magento_id, $magentoData);
            
            // 2. Update local DB
            $this->syncFromMagento($updatedMagentoBlock);
            
            DB::commit();
            
            return [
                'success' => true,
                'data' => [
                    'uuid' => $localBlock->uuid,
                    'identifier' => $updatedMagentoBlock['identifier'] ?? $data['identifier'] ?? $localBlock->identifier,
                    'title' => $updatedMagentoBlock['title'] ?? $data['title'] ?? $localBlock->title,
                ],
                'message' => 'CMS Block updated successfully'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CMS Block update failed', [
                'vendor_id' => $this->vendor->id,
                'uuid' => $uuid,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Failed to update CMS Block: ' . $e->getMessage());
        }
    }

    /**
     * Delete CMS block (WRITE: Magento → Local)
     */
    public function deleteBlock(string $uuid): array
    {
        $localBlock = CmsBlock::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
        
        DB::beginTransaction();
        
        try {
            // 1. Delete from Magento
            $this->deleteFromMagento($localBlock->magento_id);
            
            // 2. Delete from local
            $localBlock->delete();
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => 'CMS Block deleted successfully'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CMS Block deletion failed', [
                'vendor_id' => $this->vendor->id,
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Failed to delete CMS Block: ' . $e->getMessage());
        }
    }

    /**
     * Sync all CMS blocks from Magento to local
     */
    public function syncAllBlocks(): array
    {
        DB::beginTransaction();
        
        try {
            // Get all blocks from Magento
            $magentoBlocks = $this->magento()->get('cmsBlock/search', [
                'searchCriteria[pageSize]' => 100
            ]);
            
            $syncedCount = 0;
            $errors = [];
            
            foreach ($magentoBlocks['items'] ?? [] as $magentoBlock) {
                try {
                    $this->syncFromMagento($magentoBlock);
                    $syncedCount++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'identifier' => $magentoBlock['identifier'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => "Synced {$syncedCount} CMS blocks successfully",
                'data' => [
                    'synced_count' => $syncedCount,
                    'errors' => $errors
                ]
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CMS Block sync failed', [
                'vendor_id' => $this->vendor->id,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Failed to sync CMS blocks: ' . $e->getMessage());
        }
    }

    /**
     * Create block in Magento API
     */
    protected function createInMagento(array $data): array
    {
        return $this->magento()->post('cmsBlock', $data);
    }

    /**
     * Update block in Magento API
     */
    protected function updateInMagento(string $magentoId, array $data): array
    {
        return $this->magento()->put("cmsBlock/{$magentoId}", $data);
    }

    /**
     * Delete block from Magento API
     */
    protected function deleteFromMagento(string $magentoId): void
    {
        $this->magento()->delete("cmsBlock/{$magentoId}");
    }

    /**
     * Sync single CMS block from Magento data to local
     */
    protected function syncFromMagento(array $magentoBlock): CmsBlock
    {
        return CmsBlock::updateOrCreate(
            [
                'vendor_id' => $this->vendor->id,
                'magento_id' => $magentoBlock['id']
            ],
            [
                'identifier' => $magentoBlock['identifier'],
                'title' => $magentoBlock['title'],
                'content' => $magentoBlock['content'] ?? '',
                'is_active' => $magentoBlock['active'] ?? true,
                'store_ids' => $magentoBlock['store_ids'] ?? null,
                'magento_data' => $magentoBlock,
                'last_synced_at' => now(),
                'magento_updated_at' => $magentoBlock['update_time'] ?? now()
            ]
        );
    }
}