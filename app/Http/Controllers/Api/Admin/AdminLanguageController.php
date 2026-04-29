<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LanguageResource;
use App\Models\Config\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminLanguageController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Language::query();

            // Apply filters
            if ($request->has('search')) {
                $searchTerm = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('code', 'like', $searchTerm)
                        ->orWhere('name', 'like', $searchTerm)
                        ->orWhere('native_name', 'like', $searchTerm)
                        ->orWhere('locale', 'like', $searchTerm);
                });
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('is_default')) {
                $query->where('is_default', $request->boolean('is_default'));
            }

            if ($request->has('is_rtl')) {
                $query->where('is_rtl', $request->boolean('is_rtl'));
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $allowedSorts = ['code', 'name', 'locale', 'created_at'];

            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('name', 'asc');
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 100);

            $languages = $query->paginate($perPage)->appends($request->query());

            // Get summary statistics
            $summary = [
                'total' => Language::count(),
                'active' => Language::where('is_active', true)->count(),
                'inactive' => Language::where('is_active', false)->count(),
                'default_language' => Language::where('is_default', true)->first()?->code,
                'rtl_languages' => Language::where('is_rtl', true)->count(),
                'ltr_languages' => Language::where('is_rtl', false)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => LanguageResource::collection($languages),
                'summary' => $summary,
                'meta' => [
                    'current_page' => $languages->currentPage(),
                    'last_page' => $languages->lastPage(),
                    'per_page' => $languages->perPage(),
                    'total' => $languages->total(),
                    'from' => $languages->firstItem(),
                    'to' => $languages->lastItem(),
                ],
                'links' => [
                    'first' => $languages->url(1),
                    'last' => $languages->url($languages->lastPage()),
                    'prev' => $languages->previousPageUrl(),
                    'next' => $languages->nextPageUrl(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch languages',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10|unique:languages,code',
            'name' => 'required|string|max:100',
            'locale' => 'required|string|max:20',
            'is_rtl' => 'boolean',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $language = Language::create($validated);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Language created successfully',
                'data' => new LanguageResource($language),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create language',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $code)
    {
        try {
            $language = Language::findOrFail($code);

            return response()->json([
                'success' => true,
                'data' => new LanguageResource($language),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Language not found',
            ], 404);
        }
    }

    public function update(Request $request, string $code)
    {
        $language = Language::findOrFail($code);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'locale' => 'sometimes|string|max:20',
            'is_rtl' => 'boolean',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $language->update($validated);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Language updated successfully',
                'data' => new LanguageResource($language),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update language',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $code)
    {
        try {
            $language = Language::findOrFail($code);
            $language->delete();

            return response()->json([
                'success' => true,
                'message' => 'Language deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete language',
            ], 500);
        }
    }
}
