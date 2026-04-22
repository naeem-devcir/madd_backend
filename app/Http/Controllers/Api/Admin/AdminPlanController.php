<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Vendor\VendorPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AdminPlanController extends Controller
{
    /**
     * Display a listing of vendor plans (mapped for frontend)
     */
    public function index(): JsonResponse
    {
        $plans = VendorPlan::active()
            ->orderBy('sort_order')
            ->orderBy('price_monthly')
            ->get();
        
        // Transform data to match frontend expected structure
        $transformedPlans = $plans->map(function($plan) {
            return $this->transformForFrontend($plan);
        });
        
        return response()->json($transformedPlans);
    }

    /**
     * Store a newly created vendor plan
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription_name' => 'required|string|max:100',
            'billing_type' => 'required|string|in:monthly,yearly,one-time',
            'price' => 'required|numeric|min:0',
            'feature' => 'nullable|array',
            'status' => 'boolean',
            'description' => 'nullable|string',
            'setup_fee' => 'numeric|min:0',
            'transaction_fee_percentage' => 'numeric|min:0|max:100',
            'commission_rate' => 'numeric|min:0|max:100',
            'max_products' => 'integer|min:0',
            'max_stores' => 'integer|min:0',
            'max_users' => 'integer|min:0',
            'trial_period_days' => 'integer|min:0',
        ]);

        // Map frontend fields to backend fields
        $planData = [
            'name' => $validated['subscription_name'],
            'slug' => Str::slug($validated['subscription_name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['status'] ?? true,
            'features' => $validated['feature'] ?? [],
            'trial_period_days' => $validated['trial_period_days'] ?? 0,
            'max_products' => $validated['max_products'] ?? 100,
            'max_stores' => $validated['max_stores'] ?? 1,
            'max_users' => $validated['max_users'] ?? 1,
            'setup_fee' => $validated['setup_fee'] ?? 0,
            'transaction_fee_percentage' => $validated['transaction_fee_percentage'] ?? 0,
            'commission_rate' => $validated['commission_rate'] ?? 0,
        ];

        // Handle price based on billing type
        switch ($validated['billing_type']) {
            case 'monthly':
                $planData['price_monthly'] = $validated['price'];
                $planData['price_yearly'] = $validated['price'] * 12;
                break;
            case 'yearly':
                $planData['price_monthly'] = $validated['price'] / 12;
                $planData['price_yearly'] = $validated['price'];
                break;
            case 'one-time':
                $planData['price_monthly'] = $validated['price'];
                $planData['price_yearly'] = $validated['price'];
                break;
        }

        // Check for duplicate slug
        if (VendorPlan::where('slug', $planData['slug'])->exists()) {
            $planData['slug'] = $planData['slug'] . '-' . uniqid();
        }

        $plan = VendorPlan::create($planData);

        return response()->json($this->transformForFrontend($plan), 201);
    }

    /**
     * Display the specified vendor plan
     */
    public function show(string $id): JsonResponse
    {
        $plan = VendorPlan::withCount('vendors')->findOrFail($id);
        
        return response()->json($this->transformForFrontend($plan));
    }

    /**
     * Update the specified vendor plan
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $plan = VendorPlan::findOrFail($id);
        
        $validated = $request->validate([
            'subscription_name' => 'sometimes|string|max:100',
            'billing_type' => 'sometimes|string|in:monthly,yearly,one-time',
            'price' => 'sometimes|numeric|min:0',
            'feature' => 'nullable|array',
            'status' => 'boolean',
            'description' => 'nullable|string',
            'setup_fee' => 'numeric|min:0',
            'transaction_fee_percentage' => 'numeric|min:0|max:100',
            'commission_rate' => 'numeric|min:0|max:100',
            'max_products' => 'integer|min:0',
            'max_stores' => 'integer|min:0',
            'max_users' => 'integer|min:0',
            'trial_period_days' => 'integer|min:0',
        ]);

        // Map frontend fields to backend fields
        if (isset($validated['subscription_name'])) {
            $plan->name = $validated['subscription_name'];
            $plan->slug = Str::slug($validated['subscription_name']);
        }
        
        if (isset($validated['description'])) {
            $plan->description = $validated['description'];
        }
        
        if (isset($validated['status'])) {
            $plan->is_active = $validated['status'];
        }
        
        if (isset($validated['feature'])) {
            $plan->features = $validated['feature'];
        }
        
        if (isset($validated['trial_period_days'])) {
            $plan->trial_period_days = $validated['trial_period_days'];
        }
        
        if (isset($validated['max_products'])) {
            $plan->max_products = $validated['max_products'];
        }
        
        if (isset($validated['max_stores'])) {
            $plan->max_stores = $validated['max_stores'];
        }
        
        if (isset($validated['max_users'])) {
            $plan->max_users = $validated['max_users'];
        }
        
        if (isset($validated['setup_fee'])) {
            $plan->setup_fee = $validated['setup_fee'];
        }
        
        if (isset($validated['transaction_fee_percentage'])) {
            $plan->transaction_fee_percentage = $validated['transaction_fee_percentage'];
        }
        
        if (isset($validated['commission_rate'])) {
            $plan->commission_rate = $validated['commission_rate'];
        }

        // Handle price based on billing type
        if (isset($validated['price']) && isset($validated['billing_type'])) {
            switch ($validated['billing_type']) {
                case 'monthly':
                    $plan->price_monthly = $validated['price'];
                    $plan->price_yearly = $validated['price'] * 12;
                    break;
                case 'yearly':
                    $plan->price_monthly = $validated['price'] / 12;
                    $plan->price_yearly = $validated['price'];
                    break;
                case 'one-time':
                    $plan->price_monthly = $validated['price'];
                    $plan->price_yearly = $validated['price'];
                    break;
            }
        }

        $plan->save();

        return response()->json($this->transformForFrontend($plan));
    }

    /**
     * Remove the specified vendor plan
     */
    public function destroy(string $id): JsonResponse
    {
        $plan = VendorPlan::findOrFail($id);
        
        // Check if plan has vendors
        if ($plan->vendors()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete plan with active vendors'
            ], 400);
        }
        
        // Prevent deletion of default plan
        if ($plan->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete the default plan'
            ], 400);
        }
        
        $plan->delete();

        return response()->json(null, 204);
    }

    /**
     * Set a plan as default
     */
    public function setDefault(string $id): JsonResponse
    {
        $plan = VendorPlan::findOrFail($id);
        
        if (!$plan->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot set inactive plan as default'
            ], 400);
        }
        
        // Remove default from all plans
        VendorPlan::where('is_default', true)->update(['is_default' => false]);
        
        // Set new default plan
        $plan->update(['is_default' => true]);
        
        return response()->json([
            'success' => true,
            'message' => 'Default plan set successfully'
        ]);
    }

    /**
     * Transform backend plan data to frontend expected format
     */
    private function transformForFrontend(VendorPlan $plan): array
    {
        // Determine billing type and price based on pricing structure
        $billingType = 'monthly'; // default
        $price = $plan->price_monthly;
        
        if ($plan->price_yearly && $plan->price_yearly == $plan->price_monthly * 12) {
            $billingType = 'monthly';
            $price = $plan->price_monthly;
        } elseif ($plan->price_monthly && $plan->price_yearly && $plan->price_yearly < $plan->price_monthly * 12) {
            $billingType = 'yearly';
            $price = $plan->price_yearly;
        } elseif ($plan->price_monthly == $plan->price_yearly) {
            $billingType = 'one-time';
            $price = $plan->price_monthly;
        }

        return [
            'id' => $plan->id,
            'subscription_name' => $plan->name,
            'billing_type' => $billingType,
            'price' => (float) $price,
            'feature' => $plan->features ?? [],
            'status' => $plan->is_active ? 1 : 0,
            'created_at' => $plan->created_at ? $plan->created_at->toISOString() : now()->toISOString(),
            'updated_at' => $plan->updated_at ? $plan->updated_at->toISOString() : now()->toISOString(),
            'description' => $plan->description,
            'setup_fee' => (float) $plan->setup_fee,
            'transaction_fee_percentage' => (float) $plan->transaction_fee_percentage,
            'transaction_fee_fixed' => (float) $plan->transaction_fee_fixed,
            'commission_rate' => (float) $plan->commission_rate,
            'max_products' => $plan->max_products,
            'max_stores' => $plan->max_stores,
            'max_users' => $plan->max_users,
            'bandwidth_limit_mb' => $plan->bandwidth_limit_mb,
            'storage_limit_mb' => $plan->storage_limit_mb,
            'trial_period_days' => $plan->trial_period_days,
            'is_default' => $plan->is_default,
            'sort_order' => $plan->sort_order,
        ];
    }
}