<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product\ProductDraft;
use App\Models\Product\VendorProduct;
use App\Services\Product\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminProductController extends Controller
{
    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    /**
     * Get pending product approvals
     */
    public function pending(Request $request)
    {
        $pendingProducts = ProductDraft::with(['vendor', 'store', 'product'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $pendingProducts,
            'meta' => [
                'total_pending' => ProductDraft::where('status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * Approve product draft
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $draft = ProductDraft::with(['vendor', 'product'])->findOrFail($id);

        if ($draft->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Product is not pending approval',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->productService->approveProduct($draft, auth()->id());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product approved successfully',
                'data' => [
                    'product_id' => $draft->vendor_product_id,
                    'status' => 'approved',
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject product draft
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        $draft = ProductDraft::findOrFail($id);

        if ($draft->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Product is not pending approval',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->productService->rejectProduct($draft, auth()->id(), $request->reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product rejected successfully',
                'data' => [
                    'draft_id' => $draft->id,
                    'status' => 'rejected',
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all products (admin view)
     */
    public function index(Request $request)
    {
        $query = VendorProduct::with(['vendor', 'store']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('sku', 'like', '%'.$request->search.'%');
            });
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
            ],
        ]);
    }

    /**
     * Get single product (admin view)
     */
    public function show($id)
    {
        $product = VendorProduct::with(['vendor', 'store', 'draft', 'reviews', 'orderItems'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Delete product (admin)
     */
    public function destroy($id)
    {
        $product = VendorProduct::findOrFail($id);

        DB::beginTransaction();

        try {
            $product->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get product statistics
     */
    public function statistics()
    {
        $stats = [
            'total' => VendorProduct::count(),
            'active' => VendorProduct::where('status', 'active')->count(),
            'inactive' => VendorProduct::where('status', 'inactive')->count(),
            'pending_sync' => VendorProduct::where('sync_status', 'pending')->count(),
            'failed_sync' => VendorProduct::where('sync_status', 'failed')->count(),
            'pending_approval' => ProductDraft::where('status', 'pending')->count(),
            'by_vendor' => VendorProduct::select('vendor_id', DB::raw('count(*) as count'))
                ->with('vendor')
                ->groupBy('vendor_id')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get(),
            'top_products' => DB::table('order_items')
                ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
                ->select('vendor_products.name', DB::raw('SUM(order_items.qty_ordered) as total_sold'))
                ->groupBy('vendor_products.id', 'vendor_products.name')
                ->orderBy('total_sold', 'desc')
                ->limit(10)
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Placeholder until product modification workflow is implemented.
     */
    public function requestModification(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Product modification requests are not implemented yet.',
            'product_id' => $id,
        ], 501);
    }

    /**
     * Placeholder until product featuring is implemented.
     */
    public function feature($id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Product featuring is not implemented yet.',
            'product_id' => $id,
        ], 501);
    }

    /**
     * Placeholder until product unfeaturing is implemented.
     */
    public function unfeature($id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Product unfeaturing is not implemented yet.',
            'product_id' => $id,
        ], 501);
    }
}

