<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourierResource;
use App\Models\Config\Courier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Http;

class AdminCourierController extends Controller
{
  public function index(Request $request)
    {
        try {
            $query = Courier::query();
            
            // Apply filters
            if ($request->has('search')) {
                $searchTerm = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', $searchTerm)
                        ->orWhere('code', 'like', $searchTerm)
                        ->orWhere('api_type', 'like', $searchTerm);
                });
            }
            
            if ($request->has('api_type')) {
                $query->where('api_type', $request->api_type);
            }
            
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }
            
            if ($request->has('country_code')) {
                $query->whereJsonContains('countries', $request->country_code);
            }
            
            if ($request->has('data_processing_agreement')) {
                $query->where('data_processing_agreement', $request->boolean('data_processing_agreement'));
            }
            
            if ($request->has('weight_limit_min')) {
                $query->where('weight_limit_kg', '>=', $request->weight_limit_min);
            }
            
            if ($request->has('weight_limit_max')) {
                $query->where('weight_limit_kg', '<=', $request->weight_limit_max);
            }
            
            // Apply sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $allowedSorts = ['name', 'code', 'api_type', 'weight_limit_kg', 'settlement_due_day', 'created_at'];
            
            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('name', 'asc');
            }
            
            // Pagination
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 100);
            
            $couriers = $query->paginate($perPage)->appends($request->query());
            
            // Get summary statistics
            $summary = [
                'total' => Courier::count(),
                'active' => Courier::where('is_active', true)->count(),
                'inactive' => Courier::where('is_active', false)->count(),
                'api_types' => Courier::select('api_type')->distinct()->pluck('api_type'),
                'countries_served' => $this->getUniqueCountriesServed(),
                'avg_weight_limit' => Courier::avg('weight_limit_kg'),
                'avg_settlement_days' => Courier::avg('settlement_due_day'),
                'data_processing_agreed' => Courier::where('data_processing_agreement', true)->count(),
            ];
            
            return response()->json([
                'success' => true,
                'data' => CourierResource::collection($couriers),
                'summary' => $summary,
                'meta' => [
                    'current_page' => $couriers->currentPage(),
                    'last_page' => $couriers->lastPage(),
                    'per_page' => $couriers->perPage(),
                    'total' => $couriers->total(),
                    'from' => $couriers->firstItem(),
                    'to' => $couriers->lastItem(),
                ],
                'links' => [
                    'first' => $couriers->url(1),
                    'last' => $couriers->url($couriers->lastPage()),
                    'prev' => $couriers->previousPageUrl(),
                    'next' => $couriers->nextPageUrl(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch couriers',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
    
    /**
     * Get unique countries served by couriers.
     */
    private function getUniqueCountriesServed()
    {
        $allCountries = Courier::whereNotNull('countries')->pluck('countries');
        $uniqueCountries = [];
        
        foreach ($allCountries as $countries) {
            $countriesArray = is_string($countries) ? json_decode($countries, true) : $countries;
            if (is_array($countriesArray)) {
                $uniqueCountries = array_merge($uniqueCountries, $countriesArray);
            }
        }
        
        return array_values(array_unique($uniqueCountries));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:couriers,code',
            'api_type' => 'required|string|max:50',
            'credentials' => 'nullable|array',
            'countries' => 'required|array',
            'service_levels' => 'nullable|array',
            'tracking_url_template' => 'nullable|string',
            'logo_url' => 'nullable|url|max:500',
            'support_contact' => 'nullable|array',
            'settlement_contact' => 'nullable|array',
            'weight_limit_kg' => 'nullable|numeric|min:0',
            'insurance_options' => 'nullable|array',
            'data_processing_agreement' => 'boolean',
            'contract_reference' => 'nullable|string',
            'settlement_due_day' => 'integer|min:1|max:31',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();
        
        try {
            $courier = Courier::create($validated);
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Courier created successfully',
                'data' => new CourierResource($courier),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create courier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $courier = Courier::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => new CourierResource($courier),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Courier not found',
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        $courier = Courier::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('couriers', 'code')->ignore($courier->id)],
            'api_type' => 'sometimes|string|max:50',
            'credentials' => 'nullable|array',
            'countries' => 'sometimes|array',
            'service_levels' => 'nullable|array',
            'tracking_url_template' => 'nullable|string',
            'logo_url' => 'nullable|url|max:500',
            'support_contact' => 'nullable|array',
            'settlement_contact' => 'nullable|array',
            'weight_limit_kg' => 'nullable|numeric|min:0',
            'insurance_options' => 'nullable|array',
            'data_processing_agreement' => 'boolean',
            'contract_reference' => 'nullable|string',
            'settlement_due_day' => 'integer|min:1|max:31',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();
        
        try {
            $courier->update($validated);
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Courier updated successfully',
                'data' => new CourierResource($courier),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update courier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $courier = Courier::findOrFail($id);
            $courier->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Courier deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete courier',
            ], 500);
        }
    }

    public function testConnection(string $id)
    {
        try {
            $courier = Courier::findOrFail($id);
            
            // For now, return success since actual API testing depends on courier type
            return response()->json([
                'success' => true,
                'message' => 'Connection test initiated. This feature will be implemented based on courier API specifications.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to test connection: ' . $e->getMessage(),
            ], 500);
        }
    }
}