<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\CreateProductRequest;
use App\Http\Requests\Api\Vendor\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product\VendorProduct;
use App\Models\Product\ProductDraft;
use App\Models\Product\ProductSharing;
use App\Models\Vendor\VendorStore;
use App\Services\Product\ProductService;
use App\Services\Product\ProductSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorProductController extends Controller
{
    protected $productService;
    protected $productSyncService;

    public function __construct(
        ProductService $productService,
        ProductSyncService $productSyncService
    ) {
        $this->productService = $productService;
        $this->productSyncService = $productSyncService;
    }

    /**
     * Get all products for the vendor
     */
    public function index(Request $request)
    {
        $vendor = $request->user()->vendor;

        $query = VendorProduct::where('vendor_id', $vendor->getKey())
            ->with(['store', 'draft']);

        // Apply filters
        if ($request->has('store_id')) {
            $store = VendorStore::where('id', $request->store_id)
                ->where('vendor_id', $vendor->getKey())
                ->firstOrFail();

            $query->where('vendor_store_id', $store->uuid);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('sku', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        $products = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => ProductResource::collection($products),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
                'total_count' => VendorProduct::where('vendor_id', $vendor->getKey())->count(),
                'active_count' => VendorProduct::where('vendor_id', $vendor->getKey())->where('status', 'active')->count(),
                'inactive_count' => VendorProduct::where('vendor_id', $vendor->getKey())->where('status', 'inactive')->count(),
            ]
        ]);
    }

    /**
     * Create a new product draft
     */
    public function store(CreateProductRequest $request)
    {
        $vendor = $request->user()->vendor;

        // Check plan limits
        if (!$vendor->canAddProduct()) {
            return response()->json([
                'success' => false,
                'message' => 'Product limit reached for your plan. Maximum ' . $vendor->plan->max_products . ' products allowed.',
                'limit' => $vendor->plan->max_products,
                'current' => VendorProduct::where('vendor_id', $vendor->getKey())->count(),
            ], 403);
        }

        // Verify store belongs to vendor
        $store = VendorStore::where('id', $request->vendor_store_id)
            ->where('vendor_id', $vendor->getKey())
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Create product draft
            $draft = ProductDraft::create([
                'vendor_id' => $vendor->getKey(),
                'vendor_store_id' => $store->uuid,
                'sku' => $request->sku,
                'name' => $request->name,
                'description' => $request->description,
                'short_description' => $request->short_description,
                'price' => $request->price,
                'special_price' => $request->special_price,
                'special_price_from' => $request->special_price_from,
                'special_price_to' => $request->special_price_to,
                'quantity' => $request->quantity,
                'weight' => $request->weight,
                'product_data' => $request->except(['sku', 'name', 'description', 'price', 'quantity']),
                'media_gallery' => $request->media_gallery,
                'categories' => $request->categories,
                'attributes' => $request->attributes,
                'seo_data' => $request->seo_data,
                'auto_approve' => $vendor->kyc_status === 'verified',
                'status' => 'draft',
            ]);

            // If auto-approve is enabled, submit for approval immediately
            if ($draft->auto_approve) {
                $this->productService->submitForApproval($draft);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product draft created successfully',
                'data' => [
                    'draft_id' => $draft->id,
                    'status' => $draft->status,
                    'requires_approval' => !$draft->auto_approve,
                    'estimated_approval_time' => $draft->auto_approve ? null : '24-48 hours',
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific product
     */
    public function show(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $product = VendorProduct::where('vendor_id', $vendor->getKey())
            ->with(['store', 'draft', 'reviews', 'sharedToStores'])
            ->findOrFail($id);

        // Get sales statistics
        $salesStats = [
            'total_sold' => $product->orderItems()->sum('qty_ordered'),
            'total_revenue' => $product->orderItems()->sum('row_total'),
            'last_30_days' => $product->orderItems()
                ->whereHas('order', function($q) {
                    $q->where('created_at', '>=', now()->subDays(30));
                })
                ->sum('qty_ordered'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'product' => new ProductResource($product),
                'statistics' => $salesStats,
                'reviews' => [
                    'average_rating' => $product->getAverageRating(),
                    'total_reviews' => $product->getTotalReviews(),
                    'rating_distribution' => $this->getRatingDistribution($product),
                ],
            ]
        ]);
    }

    /**
     * Update a product
     */
    public function update(UpdateProductRequest $request, $id)
    {
        $vendor = $request->user()->vendor;

        $product = VendorProduct::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        DB::beginTransaction();

        try {
            // Create new draft for the update
            $draft = ProductDraft::create([
                'vendor_id' => $vendor->getKey(),
                'vendor_store_id' => $product->vendor_store_id,
                'vendor_product_id' => $product->id,
                'parent_draft_id' => $product->draft?->id,
                'version' => ($product->draft?->version ?? 0) + 1,
                'sku' => $request->sku ?? $product->sku,
                'name' => $request->name ?? $product->name,
                'description' => $request->description,
                'short_description' => $request->short_description,
                'price' => $request->price ?? $product->price,
                'special_price' => $request->special_price,
                'special_price_from' => $request->special_price_from,
                'special_price_to' => $request->special_price_to,
                'quantity' => $request->quantity,
                'weight' => $request->weight,
                'product_data' => $request->except(['sku', 'name', 'description', 'price', 'quantity']),
                'media_gallery' => $request->media_gallery,
                'categories' => $request->categories,
                'attributes' => $request->attributes,
                'seo_data' => $request->seo_data,
                'auto_approve' => $vendor->kyc_status === 'verified',
                'status' => 'draft',
            ]);

            // Submit for approval
            $this->productService->submitForApproval($draft);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product update submitted for approval',
                'data' => [
                    'draft_id' => $draft->id,
                    'status' => 'pending_review',
                    'estimated_time' => '24-48 hours',
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a product (soft delete)
     */
    public function destroy(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $product = VendorProduct::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        // Check if product has pending orders
        $hasPendingOrders = $product->orderItems()
            ->whereHas('order', function($q) {
                $q->whereNotIn('status', ['delivered', 'cancelled', 'refunded']);
            })
            ->exists();

        if ($hasPendingOrders) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete product with pending orders. Please archive it instead.',
            ], 422);
        }

        try {
            // Sync deletion to Magento
            $this->productSyncService->deleteFromMagento($product);

            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update product inventory
     */
    public function updateInventory(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $vendor = $request->user()->vendor;

        $product = VendorProduct::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        try {
            // Sync inventory to Magento
            $this->productSyncService->updateInventory($product, $request->quantity);

            // Log inventory change
            \App\Models\Inventory\InventoryLog::create([
                'vendor_product_id' => $product->id,
                'vendor_id' => $vendor->id,
                'previous_quantity' => $product->quantity ?? 0,
                'new_quantity' => $request->quantity,
                'change' => $request->quantity - ($product->quantity ?? 0),
                'reason' => $request->reason ?? 'Manual update',
                'changed_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Inventory updated successfully',
                'data' => [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'quantity' => $request->quantity,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update inventory',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product drafts (pending approvals)
     */
    public function drafts(Request $request)
    {
        $vendor = $request->user()->vendor;

        $drafts = ProductDraft::where('vendor_id', $vendor->getKey())
            ->whereIn('status', ['pending', 'needs_modification', 'draft'])
            ->with(['product', 'reviewedBy', 'store'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $drafts,
            'meta' => [
                'pending_count' => ProductDraft::where('vendor_id', $vendor->getKey())->where('status', 'pending')->count(),
                'needs_modification_count' => ProductDraft::where('vendor_id', $vendor->getKey())->where('status', 'needs_modification')->count(),
            ]
        ]);
    }

    /**
     * Duplicate a product
     */
    public function duplicate(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $originalProduct = VendorProduct::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        // Check plan limits
        if (!$vendor->canAddProduct()) {
            return response()->json([
                'success' => false,
                'message' => 'Product limit reached for your plan',
            ], 403);
        }

        DB::beginTransaction();

        try {
            // Generate new SKU
            $newSku = $originalProduct->sku . '-copy';
            $counter = 1;
            while (VendorProduct::where('vendor_id', $vendor->getKey())->where('sku', $newSku)->exists()) {
                $newSku = $originalProduct->sku . '-copy-' . $counter;
                $counter++;
            }

            // Create draft from original
            $draft = ProductDraft::create([
                'vendor_id' => $vendor->getKey(),
                'vendor_store_id' => $originalProduct->vendor_store_id,
                'sku' => $newSku,
                'name' => $originalProduct->name . ' (Copy)',
                'description' => $originalProduct->description,
                'price' => $originalProduct->price,
                'quantity' => 0,
                'product_data' => $originalProduct->product_data,
                'auto_approve' => $vendor->kyc_status === 'verified',
                'status' => 'draft',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product duplicated successfully',
                'data' => [
                    'draft_id' => $draft->id,
                    'name' => $draft->name,
                    'sku' => $draft->sku,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to duplicate product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Share product with another store
     */
    public function share(Request $request, $id)
    {
        $request->validate([
            'target_store_id' => 'required|exists:vendor_stores,id',
            'commission_percentage' => 'nullable|numeric|min:0|max:100',
            'markup_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $vendor = $request->user()->vendor;

        $product = VendorProduct::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        // Verify target store exists and is active
        $targetStore = VendorStore::where('id', $request->target_store_id)
            ->where('status', 'active')
            ->firstOrFail();

        // Check if already shared
        $existingShare = ProductSharing::where('source_product_id', $product->id)
            ->where('target_store_id', $targetStore->uuid)
            ->first();

        if ($existingShare) {
            return response()->json([
                'success' => false,
                'message' => 'Product already shared with this store',
            ], 422);
        }

        $share = ProductSharing::create([
            'source_product_id' => $product->id,
            'target_store_id' => $targetStore->uuid,
            'commission_percentage' => $request->commission_percentage,
            'markup_percentage' => $request->markup_percentage,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product share request sent',
            'data' => $share
        ]);
    }

    /**
     * Get rating distribution for product
     */
    private function getRatingDistribution($product)
    {
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        
        $ratings = $product->reviews()
            ->where('status', 'approved')
            ->select('rating', \DB::raw('count(*) as count'))
            ->groupBy('rating')
            ->get();

        foreach ($ratings as $rating) {
            $distribution[$rating->rating] = $rating->count;
        }

        return $distribution;
    }

    /**
     * Placeholder until bulk pricing workflow is implemented.
     */
    public function bulkPriceUpdate(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Bulk price update is not implemented yet.',
            'product_id' => $id,
        ], 501);
    }
}
