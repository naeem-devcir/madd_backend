<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\log;

class AdminUserController extends Controller
{
    protected $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Get all users with filters
     */
    public function index(Request $request)
    {
        $query = User::with(['roles', 'vendor']);

        // Apply filters
        if ($request->has('user_type')) {
            $query->where('user_type', $request->user_type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('kyc_status')) {
            $query->where('kyc_status', $request->kyc_status);
        }

        if ($request->has('country_code')) {
            $query->where('country_code', $request->country_code);
        }

        if ($request->has('email_verified')) {
            if ($request->boolean('email_verified')) {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('email', 'like', '%' . $request->search . '%')
                    ->orWhere('first_name', 'like', '%' . $request->search . '%')
                    ->orWhere('last_name', 'like', '%' . $request->search . '%')
                    ->orWhere('phone', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        // Add summary statistics
        $summary = [
            'total' => User::count(),
            'by_type' => [
                'admin' => User::where('user_type', 'admin')->count(),
                'vendor' => User::where('user_type', 'vendor')->count(),
                'customer' => User::where('user_type', 'customer')->count(),
                'mlm_agent' => User::where('user_type', 'mlm_agent')->count(),
            ],
            'by_status' => [
                'active' => User::where('status', 'active')->count(),
                'suspended' => User::where('status', 'suspended')->count(),
                'pending' => User::where('status', 'pending')->count(),
                'banned' => User::where('status', 'banned')->count(),
            ],
            'email_verified' => User::whereNotNull('email_verified_at')->count(),
            'email_unverified' => User::whereNull('email_verified_at')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($users),
            'summary' => $summary,
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Get single user
     */
    public function show($id)
    {


        // $user = User::with(['roles', 'permissions', 'vendor', 'mlmAgent', 'socialAccounts'])
        //     ->findOrFail($id);
        $user = User::with(['roles', 'permissions', 'vendor', 'mlmAgent', 'socialAccounts'])
            ->where('uuid', $id)
            ->firstOrFail();

        // Get user statistics
        $stats = [
            'total_orders' => $user->orders()->count(),
            'total_spent' => $user->orders()->sum('grand_total'),
            'average_order_value' => $user->orders()->avg('grand_total'),
            'last_order_at' => $user->orders()->latest()->first()?->created_at,
            'total_reviews' => $user->reviews()->count(),
            'average_rating' => $user->reviews()->avg('rating'),
        ];

        // Get login history (last 10)
        $loginHistory = DB::table('user_sessions')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'statistics' => $stats,
                'login_history' => $loginHistory,
                'permissions' => $user->getPermissionArray(),
            ],
        ]);
    }

    /**
     * Create new user
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'user_type' => 'required|in:admin,vendor,customer,mlm_agent',
            'country_code' => 'required|string|size:2',
            'locale' => 'nullable|string|size:2',  // Made nullable
            'timezone' => 'nullable|string',       // Made nullable
            'status' => 'nullable|in:active,suspended,pending,banned',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        DB::beginTransaction();

        try {
            $user = User::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), // Add this
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']), // Hash password
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'user_type' => $validated['user_type'],
                'country_code' => $validated['country_code'],
                'locale' => $validated['locale'] ?? 'en',
                'timezone' => $validated['timezone'] ?? 'UTC',
                'status' => $validated['status'] ?? 'active',
                'email_verified_at' => now(),
            ]);

            // Assign roles
            if (isset($validated['roles']) && !empty($validated['roles'])) {
                $user->syncRoles($validated['roles']);
            } else {
                $user->assignRole($validated['user_type']);
            }

            // Assign direct permissions
            if (isset($validated['permissions']) && !empty($validated['permissions'])) {
                $user->syncPermissions($validated['permissions']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => new UserResource($user->load('roles', 'permissions')),
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();

            if ($e->errorInfo[1] == 1062) { // Duplicate entry
                return response()->json([
                    'success' => false,
                    'message' => 'Email already exists',
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update user
     */
    public function update(Request $request, $id)
    {
        // $user = User::findOrFail($id);
        $user = User::where('uuid', $id)->firstOrFail();


        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'phone' => 'nullable|string|max:20|unique:users,phone,' . $user->id,
            'country_code' => 'sometimes|string|size:2',
            'locale' => 'sometimes|string|size:2',
            'timezone' => 'sometimes|string',
            'status' => 'sometimes|in:active,suspended,pending,banned',
            'kyc_status' => 'sometimes|in:pending,verified,rejected',
            'password' => 'nullable|string|min:8',
        ]);

        DB::beginTransaction();

        try {
            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            $user->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => new UserResource($user->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign role to user
     */
    public function assignRole(Request $request, $id)
    {
        $request->validate([
            'role' => 'required|in:admin,vendor,mlm_agent,customer',
        ]);

        // $user = User::findOrFail($id);
        $user = User::where('uuid', $id)->firstOrFail();
        $user->assignRole($request->role);

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully',
            'data' => [
                'user_id' => $user->id,
                'roles' => $user->getRoleNames(),
            ],
        ]);
    }

    /**
     * Remove role from user
     */
    public function removeRole(Request $request, $id)
    {
        $request->validate([
            'role' => 'required|exists:roles,name',
        ]);

        // $user = User::findOrFail($id);
        $user = User::where('uuid', $id)->firstOrFail();

        $user->removeRole($request->role);

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully',
            'data' => [
                'user_id' => $user->id,
                'roles' => $user->getRoleNames(),
            ],
        ]);
    }

    /**
     * Grant permission to user
     */
    public function grantPermission(Request $request, $id)
    {
        $request->validate([
            'permission' => 'required|exists:permissions,name',
        ]);

        $user = User::findOrFail($id);
        $user->givePermissionTo($request->permission);

        return response()->json([
            'success' => true,
            'message' => 'Permission granted successfully',
            'data' => [
                'user_id' => $user->id,
                'permissions' => $user->getPermissionArray(),
            ],
        ]);
    }

    /**
     * Revoke permission from user
     */
    public function revokePermission(Request $request, $id)
    {
        $request->validate([
            'permission' => 'required|exists:permissions,name',
        ]);

        // $user = User::findOrFail($id);
        $user = User::where('uuid', $id)->firstOrFail();


        $user->revokePermissionTo($request->permission);

        return response()->json([
            'success' => true,
            'message' => 'Permission revoked successfully',
            'data' => [
                'user_id' => $user->id,
                'permissions' => $user->getPermissionArray(),
            ],
        ]);
    }

    /**
     * Suspend user
     */
    public function suspend(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        // $user = User::findOrFail($id);
        $user = User::where('uuid', $id)->firstOrFail();

        if ($user->is_super_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot suspend super admin user',
            ], 422);
        }

        $user->status = 'suspended';
        $user->metadata = array_merge($user->metadata ?? [], [
            'suspended_at' => now()->toIso8601String(),
            'suspension_reason' => $request->reason,
            'suspended_by' => auth()->user()->id,
        ]);
        $user->save();

        // Revoke all tokens
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'User suspended successfully',
            'data' => [
                'user_id' => $user->id,
                'status' => $user->status,
            ],
        ]);
    }

    /**
     * Activate suspended user
     */
    public function activate($id)
    {
        // $user = User::findOrFail($id);
        $user = User::where('uuid', $id)->firstOrFail();

        if (!in_array($user->status, ['suspended', 'banned'])) {
            return response()->json([
                'success' => false,
                'message' => 'User is not suspended or banned',
            ], 422);
        }

        $user->status = 'active';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User activated successfully',
            'data' => [
                'user_id' => $user->id,
                'status' => $user->status,
            ],
        ]);
    }

    /**
     * Ban user
     */
    public function ban(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        // $user = User::findOrFail($id);
        $user = User::where('uuid', $id)->firstOrFail();


        if ($user->is_super_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot ban super admin user',
            ], 422);
        }

        $user->status = 'banned';
        $user->metadata = array_merge($user->metadata ?? [], [
            'banned_at' => now()->toIso8601String(),
            'ban_reason' => $request->reason,
            'banned_by' => auth()->user()->id,
        ]);
        $user->save();

        // Revoke all tokens
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'User banned successfully',
            'data' => [
                'user_id' => $user->id,
                'status' => $user->status,
            ],
        ]);
    }

    /**
     * Delete user (GDPR Right to Erasure)
     */
    public function destroy($id)
    {

        try {
            // Log::info('Deleting user with UUID: ' . $id);

            // Find user by UUID
            $user = User::where('uuid', $id)->firstOrFail();
            Log::info('User Orders:', [
                'user_id' => $user->id,
                'orders' => $user->orders()->get()->toArray(),
            ]);
            // Check if super admin
            if ($user->is_super_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete super admin user',
                ], 422);
            }


            // Check if user has orders
            if ($user->orders()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete user with existing orders. Anonymize instead.',
                ], 422);
            }

            DB::beginTransaction();

            try {
                // Anonymize user data
                $user->update([
                    'email' => 'deleted_' . $user->id . '_' . time() . '@deleted.com',
                    'phone' => null,
                    'first_name' => 'Deleted',
                    'last_name' => 'User',
                    'avatar_url' => null,
                    'status' => 'banned', // Use 'banned' instead of 'deleted'
                    'email_verified_at' => null,
                    'gdpr_consent_at' => null,
                    'magento_customer_id' => null,
                    'two_factor_secret' => null,
                    'metadata' => json_encode(array_merge(
                        json_decode($user->metadata ?? '{}', true) ?? [],
                        ['deleted_at' => now()->toIso8601String()]
                    )),
                    'preferences' => null,
                    'is_email_verified' => false,
                    'is_phone_verified' => false,
                    'is_kyc_verified' => false,
                    'login_attempts' => 0,
                    'locked_until' => null,
                    'user_signin_method' => null,
                    'marketing_opt_in' => null,
                ]);

                // Revoke all tokens
                if (method_exists($user, 'tokens')) {
                    $user->tokens()->delete();
                }

                // Soft delete the user
                $user->delete();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'User deleted successfully',
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Delete user error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Impersonate user
     */
    public function impersonate($id)
    {
        $adminUser = auth()->user();

        // ✅ Permission check (BEST PRACTICE)
        if (! $adminUser->can('impersonate users')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to impersonate users',
            ], 403);
        }

        // ✅ Get user by UUID
        $user = User::where('uuid', $id)->firstOrFail();

        // ❌ Prevent impersonating privileged accounts
        if ($user->hasRole(['super_admin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'This user cannot be impersonated',
            ], 403);
        }

        // ✅ Create impersonation token
        $token = $adminUser->createToken(
            'impersonation_' . $user->id,
            ['impersonate:' . $user->id]
        )->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Impersonation token created',
            'data' => [
                'access_token' => $token,
                'user_id' => $user->id,
                'user_name' => $user->full_name,
                'user_email' => $user->email,
            ],
        ]);
    }

    /**
     * Stop impersonating
     */
    public function stopImpersonating()
    {
        $user = auth()->user();

        // Get original token
        $currentToken = $user->currentAccessToken();

        if ($currentToken && str_contains($currentToken->name, 'impersonation')) {
            $currentToken->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Impersonation stopped',
        ]);
    }

    /**
     * Get user statistics
     */
    public function statistics()
    {
        $stats = [
            'total' => User::count(),
            'new_today' => User::whereDate('created_at', today())->count(),
            'new_this_week' => User::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'new_this_month' => User::whereMonth('created_at', now()->month)->count(),
            'by_type' => [
                'admin' => User::where('user_type', 'admin')->count(),
                'vendor' => User::where('user_type', 'vendor')->count(),
                'customer' => User::where('user_type', 'customer')->count(),
                'mlm_agent' => User::where('user_type', 'mlm_agent')->count(),
            ],
            'by_status' => [
                'active' => User::where('status', 'active')->count(),
                'suspended' => User::where('status', 'suspended')->count(),
                'pending' => User::where('status', 'pending')->count(),
                'banned' => User::where('status', 'banned')->count(),
            ],
            'by_country' => User::select('country_code', DB::raw('count(*) as count'))
                ->whereNotNull('country_code')
                ->groupBy('country_code')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get(),
            'email_verification' => [
                'verified' => User::whereNotNull('email_verified_at')->count(),
                'unverified' => User::whereNull('email_verified_at')->count(),
            ],
            'kyc_status' => [
                'pending' => User::where('kyc_status', 'pending')->count(),
                'verified' => User::where('kyc_status', 'verified')->count(),
                'rejected' => User::where('kyc_status', 'rejected')->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Export users to CSV
     */
    public function export(Request $request)
    {
        $query = User::with(['roles']);

        if ($request->has('user_type')) {
            $query->where('user_type', $request->user_type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->get();

        $filename = 'users_export_' . date('Y-m-d_His') . '.csv';
        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'ID',
            'Email',
            'First Name',
            'Last Name',
            'Phone',
            'User Type',
            'Status',
            'KYC Status',
            'Country',
            'Email Verified',
            'Created At',
            'Last Login',
            'Roles',
        ]);

        // Data
        foreach ($users as $user) {
            fputcsv($handle, [
                $user->id,
                $user->email,
                $user->first_name,
                $user->last_name,
                $user->phone,
                $user->user_type,
                $user->status,
                $user->kyc_status,
                $user->country_code,
                $user->email_verified_at ? 'Yes' : 'No',
                $user->created_at,
                $user->last_login_at,
                $user->roles->pluck('name')->implode(', '),
            ]);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $filename,
                'content' => base64_encode($csvContent),
                'mime_type' => 'text/csv',
                'row_count' => $users->count(),
            ],
        ]);
    }
}
