<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Address;
use App\Services\Customer\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerProfileController extends Controller
{
    protected $customerService;

    public function __construct(CustomerService $customerService)
    {
        $this->customerService = $customerService;
    }

    /**
     * Get customer profile
     */
    public function show(Request $request)
    {
        $user = $request->user()->load(['addresses', 'orders' => function($query) {
            $query->latest()->limit(5);
        }]);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user)
        ]);
    }

    /**
     * Update customer profile
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'phone' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('users')->ignore($user->id),
            ],
            'avatar_url' => 'nullable|url|max:500',
            'locale' => 'sometimes|string|size:2',
            'timezone' => 'sometimes|string|max:50',
            'marketing_opt_in' => 'sometimes|boolean',
            'newsletter_subscription' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();

        try {
            // Update user
            $user->update($validated);

            // Update newsletter subscription if provided
            if (isset($validated['newsletter_subscription'])) {
                $this->updateNewsletterSubscription($user, $validated['newsletter_subscription']);
            }

            // Upload avatar if provided
            if ($request->hasFile('avatar')) {
                $avatarPath = $this->uploadAvatar($request->file('avatar'), $user);
                $user->avatar_url = $avatarPath;
                $user->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => new UserResource($user->fresh())
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed|different:current_password',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = $request->new_password;
        $user->save();

        // Revoke all other tokens except current
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully. You have been logged out from other devices.'
        ]);
    }

    /**
     * Delete customer account (GDPR Right to Erasure)
     */
    public function deleteAccount(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
            'confirmation' => 'required|accepted',
            'reason' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        DB::beginTransaction();

        try {
            // Store deletion reason for analytics
            if ($request->reason) {
                $metadata = $user->metadata ?? [];
                $metadata['deletion_reason'] = $request->reason;
                $metadata['deletion_requested_at'] = now()->toIso8601String();
                $user->metadata = $metadata;
                $user->save();
            }

            // Anonymize user data for GDPR compliance
            $user->update([
                'email' => 'deleted_' . $user->id . '_' . time() . '@deleted.com',
                'phone' => null,
                'first_name' => 'Deleted',
                'last_name' => 'User',
                'avatar_url' => null,
                'password' => null,
                'status' => 'deleted',
                'email_verified_at' => null,
                'phone_verified_at' => null,
                'magento_customer_id' => null,
                'two_factor_secret' => null,
                'gdpr_consent_at' => null,
                'marketing_opt_in' => false,
                'preferences' => null,
                'metadata' => array_merge($metadata ?? [], [
                    'deleted_at' => now()->toIso8601String(),
                    'deleted_by' => 'user_request',
                ]),
            ]);

            // Revoke all tokens
            $user->tokens()->delete();

            // Anonymize addresses
            if ($user->addresses) {
                foreach ($user->addresses as $address) {
                    $address->update([
                        'address_line1' => 'Deleted',
                        'address_line2' => null,
                        'city' => 'Deleted',
                        'postal_code' => '00000',
                        'phone' => null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Your account has been permanently deleted. We\'re sorry to see you go.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer addresses
     */
    public function getAddresses(Request $request)
    {
        $user = $request->user();
        $addresses = $user->addresses()->orderBy('is_default', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $addresses
        ]);
    }

    /**
     * Add new address
     */
    public function addAddress(Request $request)
    {
        $validated = $request->validate([
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'required|string|max:20',
            'country_code' => 'required|string|size:2',
            'phone' => 'nullable|string|max:20',
            'is_default' => 'boolean',
            'address_type' => 'nullable|in:shipping,billing,both',
        ]);

        $user = $request->user();

        DB::beginTransaction();

        try {
            // If this is the first address or marked as default, unset other defaults
            if ($validated['is_default'] ?? false || $user->addresses()->count() === 0) {
                $user->addresses()->update(['is_default' => false]);
                $validated['is_default'] = true;
            }

            $address = $user->addresses()->create($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Address added successfully',
                'data' => $address
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add address',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update address
     */
    public function updateAddress(Request $request, $addressId)
    {
        $user = $request->user();
        $address = $user->addresses()->findOrFail($addressId);

        $validated = $request->validate([
            'address_line1' => 'sometimes|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'sometimes|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'sometimes|string|max:20',
            'country_code' => 'sometimes|string|size:2',
            'phone' => 'nullable|string|max:20',
            'is_default' => 'boolean',
            'address_type' => 'nullable|in:shipping,billing,both',
        ]);

        DB::beginTransaction();

        try {
            // If setting as default, unset other defaults
            if (isset($validated['is_default']) && $validated['is_default']) {
                $user->addresses()->where('id', '!=', $addressId)->update(['is_default' => false]);
            }

            $address->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Address updated successfully',
                'data' => $address
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update address',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete address
     */
    public function deleteAddress($addressId)
    {
        $user = request()->user();
        $address = $user->addresses()->findOrFail($addressId);

        // Prevent deletion if it's the only address
        if ($user->addresses()->count() === 1) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your only address. Please add another address first.'
            ], 422);
        }

        // If deleting default address, set another as default
        if ($address->is_default) {
            $newDefault = $user->addresses()->where('id', '!=', $addressId)->first();
            if ($newDefault) {
                $newDefault->is_default = true;
                $newDefault->save();
            }
        }

        $address->delete();

        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully'
        ]);
    }

    /**
     * Set default address
     */
    public function setDefaultAddress($addressId)
    {
        $user = request()->user();
        $address = $user->addresses()->findOrFail($addressId);

        DB::beginTransaction();

        try {
            $user->addresses()->update(['is_default' => false]);
            $address->is_default = true;
            $address->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Default address updated successfully',
                'data' => $address
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to set default address',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer preferences
     */
    public function getPreferences(Request $request)
    {
        $user = $request->user();
        $preferences = $user->preferences ?? [
            'language' => $user->locale,
            'currency' => 'EUR',
            'notifications' => [
                'email' => true,
                'sms' => false,
                'push' => true,
            ],
            'privacy' => [
                'share_data' => false,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $preferences
        ]);
    }

    /**
     * Update customer preferences
     */
    public function updatePreferences(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'language' => 'nullable|string|size:2',
            'currency' => 'nullable|string|size:3',
            'notifications.email' => 'nullable|boolean',
            'notifications.sms' => 'nullable|boolean',
            'notifications.push' => 'nullable|boolean',
            'privacy.share_data' => 'nullable|boolean',
        ]);

        $preferences = $user->preferences ?? [];
        
        // Merge new preferences
        foreach ($validated as $key => $value) {
            if (is_array($value)) {
                $preferences[$key] = array_merge($preferences[$key] ?? [], $value);
            } else {
                $preferences[$key] = $value;
            }
        }

        $user->preferences = $preferences;
        $user->save();

        // Update locale if changed
        if (isset($validated['language'])) {
            $user->locale = $validated['language'];
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Preferences updated successfully',
            'data' => $preferences
        ]);
    }

    /**
     * Get customer statistics
     */
    public function getStatistics(Request $request)
    {
        $user = $request->user();

        $statistics = [
            'total_orders' => $user->orders()->count(),
            'total_spent' => $user->orders()->sum('grand_total'),
            'average_order_value' => $user->orders()->avg('grand_total') ?? 0,
            'total_reviews' => $user->reviews()->count(),
            'member_since' => $user->created_at->format('F Y'),
            'last_order' => $user->orders()->latest()->first()?->created_at?->toIso8601String(),
            'saved_addresses' => $user->addresses()->count(),
            'wishlist_count' => $user->wishlist()->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * Upload avatar
     */
    protected function uploadAvatar($file, User $user): string
    {
        $path = $file->store("avatars/{$user->id}", 'public');
        
        // Delete old avatar if exists
        if ($user->avatar_url && !str_contains($user->avatar_url, 'ui-avatars.com')) {
            \Storage::disk('public')->delete($user->avatar_url);
        }
        
        return $path;
    }

    /**
     * Update newsletter subscription
     */
    protected function updateNewsletterSubscription(User $user, bool $subscribe): void
    {
        // Integrate with newsletter service (e.g., Mailchimp, SendGrid)
        // This is a placeholder for actual implementation
        $preferences = $user->preferences ?? [];
        $preferences['newsletter_subscribed'] = $subscribe;
        $preferences['newsletter_updated_at'] = now()->toIso8601String();
        $user->preferences = $preferences;
        $user->save();
        
        // Example: Mailchimp integration
        // if ($subscribe) {
        //     Newsletter::subscribe($user->email, [
        //         'FNAME' => $user->first_name,
        //         'LNAME' => $user->last_name,
        //     ]);
        // } else {
        //     Newsletter::unsubscribe($user->email);
        // }
    }
}