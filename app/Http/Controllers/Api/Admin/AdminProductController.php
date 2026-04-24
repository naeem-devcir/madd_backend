<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product\ProductDraft;
use App\Models\Product\VendorProduct;
use App\Services\Product\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminProductController extends Controller
{
    protected ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    /**
     * Get pending product approvals
     */
    public function pending(Request $request)
    {
        try {
            $pendingProducts = ProductDraft::with(['vendor', 'store', 'product'])
                ->where('status', 'pending')
                ->orderBy('created_at', 'asc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $pendingProducts,
                'meta' => [
                    'total_pending' => ProductDraft::where('status', 'pending')->count(),
                    'current_page' => $pendingProducts->currentPage(),
                    'last_page' => $pendingProducts->lastPage(),
                    'per_page' => $pendingProducts->perPage(),
                    'total' => $pendingProducts->total(),
                ],
            ]);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending products',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Approve product draft
     */
    public function approve(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'notes' => 'nullable|string|max:1000',
            ]);

            $draft = ProductDraft::with(['vendor', 'product'])->findOrFail($id);

            if ($draft->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is not pending approval',
                    'current_status' => $draft->status,
                ], 422);
            }

            DB::beginTransaction();

            try {
                $this->productService->approveProduct($draft, auth()->id(), $validated['notes'] ?? null);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Product approved successfully',
                    'data' => [
                        'product_id' => $draft->vendor_product_id,
                        'draft_id' => $draft->id,
                        'status' => 'approved',
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                    ],
                ]);

            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product draft not found',
            ], 404);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Reject product draft
     */
    public function reject(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'reason' => 'required|string|min:5|max:1000',
            ]);

            $draft = ProductDraft::findOrFail($id);

            if ($draft->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is not pending approval',
                    'current_status' => $draft->status,
                ], 422);
            }

            DB::beginTransaction();

            try {
                $this->productService->rejectProduct($draft, auth()->id(), $validated['reason']);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Product rejected successfully',
                    'data' => [
                        'draft_id' => $draft->id,
                        'status' => 'rejected',
                        'reason' => $validated['reason'],
                        'rejected_by' => auth()->id(),
                        'rejected_at' => now(),
                    ],
                ]);

            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product draft not found',
            ], 404);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get all products (admin view)
     */
    public function index(Request $request)
    {
        try {
            $query = VendorProduct::with(['vendor', 'store']);

            if ($request->has('status') && in_array($request->status, ['active', 'inactive', 'draft'])) {
                $query->where('status', $request->status);
            }

            if ($request->has('vendor_id') && is_numeric($request->vendor_id)) {
                $query->where('vendor_id', $request->vendor_id);
            }

            if ($request->has('search') && strlen($request->search) >= 2) {
                $searchTerm = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', $searchTerm)
                        ->orWhere('sku', 'like', $searchTerm)
                        ->orWhere('description', 'like', $searchTerm);
                });
            }

            if ($request->has('price_min') && is_numeric($request->price_min)) {
                $query->where('price', '>=', $request->price_min);
            }

            if ($request->has('price_max') && is_numeric($request->price_max)) {
                $query->where('price', '<=', $request->price_max);
            }

            $perPage = $request->get('per_page', 20);
            $perPage = min($perPage, 100); // Limit max per page

            $products = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => ProductResource::collection($products),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'filters' => $request->only(['status', 'vendor_id', 'search', 'price_min', 'price_max']),
                ],
            ]);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get single product by UUID (admin view)
     */
    public function show($uuid)
    {
        try {
            $product = VendorProduct::with(['vendor', 'store', 'draft', 'reviews', 'orderItems'])
                ->where('uuid', $uuid)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => new ProductResource($product),
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found with the provided UUID',
            ], 404);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete product by UUID (admin)
     */
    public function destroy($uuid)
    {
        try {
            $product = VendorProduct::where('uuid', $uuid)->firstOrFail();

            DB::beginTransaction();

            try {
                // Check if product can be deleted
                if ($product->orderItems()->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot delete product with existing orders',
                        'order_count' => $product->orderItems()->count(),
                    ], 409);
                }

                $productData = [
                    'id' => $product->id,
                    'uuid' => $product->uuid,
                    'name' => $product->name,
                ];

                $product->delete();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Product deleted successfully',
                    'data' => $productData,
                ]);

            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found with the provided UUID',
            ], 404);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get product statistics
     */
    public function statistics(Request $request)
    {
        try {
            $stats = [
                'total' => VendorProduct::count(),
                'active' => VendorProduct::where('status', 'active')->count(),
                'inactive' => VendorProduct::where('status', 'inactive')->count(),
                'draft' => VendorProduct::where('status', 'draft')->count(),
                'pending_sync' => VendorProduct::where('sync_status', 'pending')->count(),
                'synced' => VendorProduct::where('sync_status', 'synced')->count(),
                'failed_sync' => VendorProduct::where('sync_status', 'failed')->count(),
                'pending_approval' => ProductDraft::where('status', 'pending')->count(),
                'total_value' => VendorProduct::where('status', 'active')->sum(DB::raw('price * stock_quantity')),
                'average_price' => VendorProduct::where('status', 'active')->avg('price'),
                'by_vendor' => VendorProduct::select('vendor_id', DB::raw('count(*) as count'))
                    ->with('vendor:id,name')
                    ->whereHas('vendor')
                    ->groupBy('vendor_id')
                    ->orderBy('count', 'desc')
                    ->limit(10)
                    ->get(),
                'top_products' => DB::table('order_items')
                    ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
                    ->select(
                        'vendor_products.id',
                        'vendor_products.uuid',
                        'vendor_products.name',
                        DB::raw('SUM(order_items.qty_ordered) as total_sold'),
                        DB::raw('SUM(order_items.qty_ordered * order_items.price) as total_revenue')
                    )
                    ->groupBy('vendor_products.id', 'vendor_products.uuid', 'vendor_products.name')
                    ->orderBy('total_sold', 'desc')
                    ->limit(10)
                    ->get(),
                'recent_products' => VendorProduct::with('vendor:id,name')
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(['id', 'uuid', 'name', 'price', 'status', 'vendor_id', 'created_at']),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
                'generated_at' => now(),
            ]);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Request product modification
     */
    public function requestModification(Request $request, $uuid)
    {
        try {
            $request->validate([
                'changes' => 'required|array|min:1',
                'reason' => 'required|string|min:10|max:1000',
            ]);

            $product = VendorProduct::where('uuid', $uuid)->firstOrFail();

            // TODO: Implement product modification workflow
            // This would typically create a ProductModificationRequest record

            return response()->json([
                'success' => false,
                'message' => 'Product modification requests are not implemented yet.',
                'product_id' => $product->uuid,
                'requested_changes' => $request->changes,
            ], 501);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to request modification',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Feature a product
     */
    public function feature($uuid)
    {
        try {
            $product = VendorProduct::where('uuid', $uuid)->firstOrFail();

            if ($product->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only active products can be featured',
                    'current_status' => $product->status,
                ], 422);
            }

            // TODO: Implement product featuring logic
            // This would typically set a 'is_featured' flag on the product

            return response()->json([
                'success' => false,
                'message' => 'Product featuring is not implemented yet.',
                'product_id' => $product->uuid,
            ], 501);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to feature product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Unfeature a product
     */
    public function unfeature($uuid)
    {
        try {
            $product = VendorProduct::where('uuid', $uuid)->firstOrFail();

            // TODO: Implement product unfeaturing logic

            return response()->json([
                'success' => false,
                'message' => 'Product unfeaturing is not implemented yet.',
                'product_id' => $product->uuid,
            ], 501);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to unfeature product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Bulk approve products
     */
    public function bulkApprove(Request $request)
    {
        try {
            $validated = $request->validate([
                'draft_ids' => 'required|array|min:1|max:50',
                'draft_ids.*' => 'required|integer|exists:product_drafts,id',
                'notes' => 'nullable|string|max:1000',
            ]);

            $results = [
                'approved' => [],
                'failed' => [],
            ];

            DB::beginTransaction();

            try {
                foreach ($validated['draft_ids'] as $draftId) {
                    try {
                        $draft = ProductDraft::find($draftId);
                        
                        if ($draft && $draft->status === 'pending') {
                            $this->productService->approveProduct($draft, auth()->id(), $validated['notes'] ?? null);
                            $results['approved'][] = $draftId;
                        } else {
                            $results['failed'][] = [
                                'id' => $draftId,
                                'reason' => $draft ? "Status is {$draft->status}" : 'Draft not found',
                            ];
                        }
                    } catch (Throwable $e) {
                        $results['failed'][] = [
                            'id' => $draftId,
                            'reason' => $e->getMessage(),
                        ];
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "Approved {$results['approved']} out of " . count($validated['draft_ids']) . " products",
                    'data' => $results,
                ]);

            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $e) {
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk approve products',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}