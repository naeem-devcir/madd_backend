<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThemeResource;
use App\Models\Config\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminThemeController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Theme::query();

            // Apply filters
            if ($request->has('search')) {
                $searchTerm = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', $searchTerm)
                        ->orWhere('slug', 'like', $searchTerm)
                        ->orWhere('category', 'like', $searchTerm);
                });
            }

            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('is_premium')) {
                $query->where('is_premium', $request->boolean('is_premium'));
            }

            if ($request->has('price_min')) {
                $query->where('price', '>=', $request->price_min);
            }

            if ($request->has('price_max')) {
                $query->where('price', '<=', $request->price_max);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $allowedSorts = ['name', 'category', 'price', 'version', 'created_at'];

            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('name', 'asc');
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 100);

            $themes = $query->paginate($perPage)->appends($request->query());

            // Get summary statistics
            $summary = [
                'total' => Theme::count(),
                'active' => Theme::where('is_active', true)->count(),
                'inactive' => Theme::where('is_active', false)->count(),
                'premium' => Theme::where('is_premium', true)->count(),
                'free' => Theme::where('is_premium', false)->count(),
                'categories' => Theme::select('category')->distinct()->whereNotNull('category')->pluck('category'),
                'average_price' => Theme::where('is_premium', true)->avg('price'),
            ];

            return response()->json([
                'success' => true,
                'data' => ThemeResource::collection($themes),
                'summary' => $summary,
                'meta' => [
                    'current_page' => $themes->currentPage(),
                    'last_page' => $themes->lastPage(),
                    'per_page' => $themes->perPage(),
                    'total' => $themes->total(),
                    'from' => $themes->firstItem(),
                    'to' => $themes->lastItem(),
                ],
                'links' => [
                    'first' => $themes->url(1),
                    'last' => $themes->url($themes->lastPage()),
                    'prev' => $themes->previousPageUrl(),
                    'next' => $themes->nextPageUrl(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch themes',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100|unique:themes,slug',
            'description' => 'nullable|string',
            'preview_url' => 'nullable|url|max:500',
            'screenshot_url' => 'nullable|url|max:500',
            'category' => 'nullable|string|max:100',
            'config_schema' => 'nullable|array',
            'is_active' => 'boolean',
            'is_premium' => 'boolean',
            'price' => 'numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $theme = Theme::create($validated);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Theme created successfully',
                'data' => new ThemeResource($theme),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create theme',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $theme = Theme::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new ThemeResource($theme),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Theme not found',
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        $theme = Theme::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'slug' => ['sometimes', 'string', 'max:100', Rule::unique('themes', 'slug')->ignore($theme->id)],
            'description' => 'nullable|string',
            'preview_url' => 'nullable|url|max:500',
            'screenshot_url' => 'nullable|url|max:500',
            'category' => 'nullable|string|max:100',
            'config_schema' => 'nullable|array',
            'is_active' => 'boolean',
            'is_premium' => 'boolean',
            'price' => 'numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $theme->update($validated);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Theme updated successfully',
                'data' => new ThemeResource($theme),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update theme',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $theme = Theme::findOrFail($id);
            $theme->delete();

            return response()->json([
                'success' => true,
                'message' => 'Theme deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete theme',
            ], 500);
        }
    }

    public function setDefault(string $id)
    {
        // Note: Your themes table doesn't have an is_default column
        // You may want to add it or implement default theme logic differently
        return response()->json([
            'success' => false,
            'message' => 'Default theme functionality requires is_default column in themes table',
        ], 501);
    }
}
