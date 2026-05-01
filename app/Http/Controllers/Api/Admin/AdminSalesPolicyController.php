<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesPolicyResource;
use App\Models\Config\SalesPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminSalesPolicyController extends Controller
{
  public function index(Request $request)
    {
        try {
            $query = SalesPolicy::with('country');
            
            // Apply filters
            if ($request->has('search')) {
                $searchTerm = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', $searchTerm)
                        ->orWhere('tax_class', 'like', $searchTerm);
                });
            }
            
            if ($request->has('country_code')) {
                $query->where('country_code', $request->country_code);
            }
            
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }
            
            if ($request->has('guest_checkout_allowed')) {
                $query->where('guest_checkout_allowed', $request->boolean('guest_checkout_allowed'));
            }
            
            if ($request->has('min_order_amount_min')) {
                $query->where('min_order_amount', '>=', $request->min_order_amount_min);
            }
            
            if ($request->has('min_order_amount_max')) {
                $query->where('min_order_amount', '<=', $request->min_order_amount_max);
            }
            
            // Apply sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $allowedSorts = ['name', 'country_code', 'min_order_amount', 'return_window_days', 'created_at'];
            
            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('name', 'asc');
            }
            
            // Pagination
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 100);
            
            $policies = $query->paginate($perPage)->appends($request->query());
            
            // Get summary statistics
            $summary = [
                'total' => SalesPolicy::count(),
                'active' => SalesPolicy::where('is_active', true)->count(),
                'inactive' => SalesPolicy::where('is_active', false)->count(),
                'countries_covered' => SalesPolicy::distinct('country_code')->count('country_code'),
                'avg_min_order' => SalesPolicy::avg('min_order_amount'),
                'avg_return_days' => SalesPolicy::avg('return_window_days'),
            ];
            
            return response()->json([
                'success' => true,
                'data' => SalesPolicyResource::collection($policies),
                'summary' => $summary,
                'meta' => [
                    'current_page' => $policies->currentPage(),
                    'last_page' => $policies->lastPage(),
                    'per_page' => $policies->perPage(),
                    'total' => $policies->total(),
                    'from' => $policies->firstItem(),
                    'to' => $policies->lastItem(),
                ],
                'links' => [
                    'first' => $policies->url(1),
                    'last' => $policies->url($policies->lastPage()),
                    'prev' => $policies->previousPageUrl(),
                    'next' => $policies->nextPageUrl(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sales policies',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'country_code' => 'required|exists:countries,iso2',
            'name' => 'required|string|max:100',
            'payment_methods' => 'required|array',
            'shipping_methods' => 'required|array',
            'allowed_currencies' => 'nullable|array',
            'tax_class' => 'required|string|max:50',
            'min_order_amount' => 'numeric|min:0',
            'guest_checkout_allowed' => 'boolean',
            'return_window_days' => 'integer|min:0',
            'terms_url' => 'nullable|url|max:500',
            'privacy_policy_url' => 'nullable|url|max:500',
            'withdrawal_right_text' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();
        
        try {
            $policy = SalesPolicy::create($validated);
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Sales policy created successfully',
                'data' => new SalesPolicyResource($policy),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create sales policy',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $policy = SalesPolicy::with('country')->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => new SalesPolicyResource($policy),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sales policy not found',
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        $policy = SalesPolicy::findOrFail($id);
        
        $validated = $request->validate([
            'country_code' => 'sometimes|exists:countries,iso2',
            'name' => 'sometimes|string|max:100',
            'payment_methods' => 'sometimes|array',
            'shipping_methods' => 'sometimes|array',
            'allowed_currencies' => 'nullable|array',
            'tax_class' => 'sometimes|string|max:50',
            'min_order_amount' => 'numeric|min:0',
            'guest_checkout_allowed' => 'boolean',
            'return_window_days' => 'integer|min:0',
            'terms_url' => 'nullable|url|max:500',
            'privacy_policy_url' => 'nullable|url|max:500',
            'withdrawal_right_text' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();
        
        try {
            $policy->update($validated);
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Sales policy updated successfully',
                'data' => new SalesPolicyResource($policy),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update sales policy',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $policy = SalesPolicy::findOrFail($id);
            $policy->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Sales policy deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete sales policy',
            ], 500);
        }
    }
}