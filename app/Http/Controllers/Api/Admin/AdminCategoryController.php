<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Services\Product\CategoryService;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;


class AdminCategoryController extends Controller
{
    protected CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Get all categories for a vendor (DIRECT from local DB)
     */
    public function index(Request $request, $vendorId): JsonResponse
    {
        try {
            // Manually find vendor instead of relying on route model binding
            $vendor = Vendor::find($vendorId);

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found with ID: ' . $vendorId
                ], 404);
            }

            $query = Category::where('vendor_id', $vendor->id)
                ->with(['parent', 'children']);

            // Apply filters
            if ($request->has('parent_id')) {
                if ($request->parent_id === 'null' || $request->parent_id === null) {
                    $query->whereNull('parent_id');
                } else {
                    $query->where('parent_id', $request->parent_id);
                }
            }

            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->has('include_in_menu')) {
                $query->where('include_in_menu', filter_var($request->include_in_menu, FILTER_VALIDATE_BOOLEAN));
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'position');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination or all
            if ($request->has('per_page')) {
                $categories = $query->paginate($request->get('per_page', 15));
            } else {
                $categories = $query->get();
            }

            return response()->json([
                'success' => true,
                'data' => $categories,
                'vendor' => [
                    'id' => $vendor->id,
                    'name' => $vendor->legal_name,
                ],
                'meta' => $request->has('per_page') ? [
                    'current_page' => $categories->currentPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                ] : null
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get single category (DIRECT from local DB)
     */
    public function show(Request $request, $vendorId, string $uuid): JsonResponse
    {
        try {
            $vendor = Vendor::find($vendorId);

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $category = Category::where('vendor_id', $vendor->id)
                ->where('uuid', $uuid)
                ->with(['parent', 'children'])
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $category,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }
    }

    /**
     * Create new category (WRITE via service: Magento -> Local)
     */
    public function store(Request $request, $vendorId): JsonResponse
    {
        try {
            $vendor = Vendor::find($vendorId);

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'parent_id' => 'nullable|string|exists:categories,uuid',
                'is_active' => 'boolean',
                'include_in_menu' => 'boolean',
                'description' => 'nullable|string',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
                'position' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->categoryService
                ->forVendor($vendor)
                ->createCategory($request->all());

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update category (WRITE via service: Magento -> Local)
     */
    public function update(Request $request, mixed $vendorId, string $uuid): JsonResponse
    {
        
        try {
            $vendor = Vendor::find($vendorId);

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'parent_id' => 'nullable|string|exists:categories,uuid',
                'is_active' => 'boolean',
                'include_in_menu' => 'boolean',
                'description' => 'nullable|string',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
                'position' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->categoryService
                ->forVendor($vendor)
                ->updateCategory($uuid, $request->all());

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete category (WRITE via service: Magento -> Local)
     */
    public function destroy($vendorId, string $uuid): JsonResponse
    {
        try {
            $vendor = Vendor::find($vendorId);

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $result = $this->categoryService
                ->forVendor($vendor)
                ->deleteCategory($uuid);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync all categories from Magento to local (WRITE operation)
     */
    public function sync($vendorId): JsonResponse
    {
        try {
            $vendor = Vendor::find($vendorId);

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $result = $this->categoryService
                ->forVendor($vendor)
                ->syncAllCategories();

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get category tree (DIRECT from local DB)
     */
    public function tree(Request $request, $vendorId): JsonResponse
    {
        try {
            $vendor = Vendor::find($vendorId);

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            // Direct local DB query for tree
            $categories = Category::where('vendor_id', $vendor->id)
                ->with(['children' => function ($query) use ($request) {
                    $depth = $request->input('depth', 5);
                    if ($depth > 1) {
                        $query->with(['children' => function ($q) use ($depth) {
                            if ($depth > 2) {
                                $q->with('children');
                            }
                        }]);
                    }
                }])
                ->whereNull('parent_id')
                ->orderBy('position')
                ->get();

            $tree = $this->buildTree($categories);

            return response()->json([
                'success' => true,
                'data' => $tree
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch category tree',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Helper: Build tree structure from local DB data
     */
    private function buildTree($categories): array
    {
        $tree = [];

        foreach ($categories as $category) {
            $node = [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'slug' => $category->slug,
                'magento_id' => $category->magento_id,
                'level' => $category->level,
                'position' => $category->position,
                'is_active' => $category->is_active,
                'include_in_menu' => $category->include_in_menu,
                'children_count' => $category->children->count(),
            ];

            if ($category->children->isNotEmpty()) {
                $node['children'] = $this->buildTree($category->children);
            }

            $tree[] = $node;
        }

        return $tree;
    }


}
