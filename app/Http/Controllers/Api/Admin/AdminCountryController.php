<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CountryResource;
use App\Models\Config\CountryConfig;
use App\Models\Config\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCountryController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Country::with('config');

            // Apply filters
            if ($request->has('search')) {
                $searchTerm = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', $searchTerm)
                        ->orWhere('iso2', 'like', $searchTerm)
                        ->orWhere('iso3', 'like', $searchTerm)
                        ->orWhere('capital', 'like', $searchTerm);
                });
            }

            if ($request->has('region')) {
                $query->where('region', $request->region);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('eu_member')) {
                $query->whereHas('config', function ($q) use ($request) {
                    $q->where('eu_member', $request->boolean('eu_member'));
                });
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $allowedSorts = ['name', 'iso2', 'phone_code', 'region', 'created_at'];

            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('name', 'asc');
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 100); // Max 100 per page

            $countries = $query->paginate($perPage)->appends($request->query());

            // Get summary statistics
            $summary = [
                'total' => Country::count(),
                'active' => Country::where('is_active', true)->count(),
                'inactive' => Country::where('is_active', false)->count(),
                'eu_members' => Country::whereHas('config', function ($q) {
                    $q->where('eu_member', true);
                })->count(),
                'regions' => Country::select('region')->distinct()->whereNotNull('region')->pluck('region'),
            ];

            return response()->json([
                'success' => true,
                'data' => CountryResource::collection($countries),
                'summary' => $summary,
                'meta' => [
                    'current_page' => $countries->currentPage(),
                    'last_page' => $countries->lastPage(),
                    'per_page' => $countries->perPage(),
                    'total' => $countries->total(),
                    'from' => $countries->firstItem(),
                    'to' => $countries->lastItem(),
                ],
                'links' => [
                    'first' => $countries->url(1),
                    'last' => $countries->url($countries->lastPage()),
                    'prev' => $countries->previousPageUrl(),
                    'next' => $countries->nextPageUrl(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch countries',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'iso2' => 'required|string|size:2|unique:countries,iso2',
            'iso3' => 'nullable|string|size:3',
            'phone_code' => 'nullable|string|max:10',
            'currency_code' => 'nullable|string|size:3',
            'region' => 'nullable|string',
            'subregion' => 'nullable|string',
            'capital' => 'nullable|string',
            'flag' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $country = Country::create($validated);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Country created successfully',
                'data' => new CountryResource($country),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create country',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $country = Country::with('config')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new CountryResource($country),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Country not found',
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        $country = Country::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'iso2' => ['sometimes', 'string', 'size:2', Rule::unique('countries', 'iso2')->ignore($country->id)],
            'iso3' => 'nullable|string|size:3',
            'phone_code' => 'nullable|string|max:10',
            'currency_code' => 'nullable|string|size:3',
            'region' => 'nullable|string',
            'subregion' => 'nullable|string',
            'capital' => 'nullable|string',
            'flag' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $country->update($validated);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Country updated successfully',
                'data' => new CountryResource($country),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update country',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $country = Country::findOrFail($id);
            $country->delete();

            return response()->json([
                'success' => true,
                'message' => 'Country deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete country',
            ], 500);
        }
    }

    public function activate(string $code)
    {
        try {
            $country = Country::where('iso2', $code)->firstOrFail();
            $country->is_active = !$country->is_active;
            $country->save();

            return response()->json([
                'success' => true,
                'message' => $country->is_active ? 'Country activated' : 'Country deactivated',
                'data' => ['is_active' => $country->is_active],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update country status',
            ], 500);
        }
    }
}
