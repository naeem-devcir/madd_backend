<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrencyResource;
use App\Models\Config\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCurrencyController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Currency::query();

            // Apply filters
            if ($request->has('search')) {
                $searchTerm = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('code', 'like', $searchTerm)
                        ->orWhere('name', 'like', $searchTerm);
                });
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('is_default')) {
                $query->where('is_default', $request->boolean('is_default'));
            }

            if ($request->has('exchange_rate_min')) {
                $query->where('exchange_rate', '>=', $request->exchange_rate_min);
            }

            if ($request->has('exchange_rate_max')) {
                $query->where('exchange_rate', '<=', $request->exchange_rate_max);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'code');
            $sortOrder = $request->get('sort_order', 'asc');
            $allowedSorts = ['code', 'name', 'exchange_rate', 'decimal_places', 'created_at'];

            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('code', 'asc');
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 100);

            $currencies = $query->paginate($perPage)->appends($request->query());

            // Get summary statistics
            $summary = [
                'total' => (int) Currency::count(),
                'active' => (int) Currency::where('is_active', true)->count(),
                'inactive' => (int) Currency::where('is_active', false)->count(),
                'average_exchange_rate' => (float) Currency::avg('exchange_rate') ?? 0,
            ];

            return response()->json([
                'success' => true,
                'data' => CurrencyResource::collection($currencies),
                'summary' => $summary,
                'meta' => [
                    'current_page' => $currencies->currentPage(),
                    'last_page' => $currencies->lastPage(),
                    'per_page' => $currencies->perPage(),
                    'total' => $currencies->total(),
                    'from' => $currencies->firstItem(),
                    'to' => $currencies->lastItem(),
                ],
                'links' => [
                    'first' => $currencies->url(1),
                    'last' => $currencies->url($currencies->lastPage()),
                    'prev' => $currencies->previousPageUrl(),
                    'next' => $currencies->nextPageUrl(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch currencies',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:3|unique:currencies,code',
            'name' => 'required|string|max:100',
            'symbol' => 'required|string|max:10',
            'exchange_rate' => 'required|numeric|min:0',
            'decimal_places' => 'integer|min:0|max:4',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $currency = Currency::create($validated);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Currency created successfully',
                'data' => new CurrencyResource($currency),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create currency',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $code)
    {
        try {
            $currency = Currency::findOrFail($code);

            return response()->json([
                'success' => true,
                'data' => new CurrencyResource($currency),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Currency not found',
            ], 404);
        }
    }

    public function update(Request $request, string $code)
    {
        $currency = Currency::findOrFail($code);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'symbol' => 'sometimes|string|max:10',
            'exchange_rate' => 'sometimes|numeric|min:0',
            'decimal_places' => 'sometimes|integer|min:0|max:4',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $currency->update($validated);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Currency updated successfully',
                'data' => new CurrencyResource($currency),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update currency',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $code)
    {
        try {
            $currency = Currency::findOrFail($code);
            $currency->delete();

            return response()->json([
                'success' => true,
                'message' => 'Currency deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete currency',
            ], 500);
        }
    }

    public function updateExchangeRate(Request $request, string $code)
    {
        $validated = $request->validate([
            'exchange_rate' => 'required|numeric|min:0',
        ]);

        try {
            $currency = Currency::findOrFail($code);
            $currency->exchange_rate = $validated['exchange_rate'];
            $currency->save();

            return response()->json([
                'success' => true,
                'message' => 'Exchange rate updated successfully',
                'data' => ['exchange_rate' => $currency->exchange_rate],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update exchange rate',
            ], 500);
        }
    }
}
