<?php

namespace App\Services\Vendor;

use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminVendorService
{
    public function create(array $data, ?int $adminId = null): Vendor
    {
        return DB::transaction(function () use ($data, $adminId) {
            $user = User::create($this->userValues($data, $adminId));

            if (method_exists($user, 'assignRole')) {
                $user->assignRole('vendor');
            }

            $vendor = Vendor::create($this->vendorValues($data, $user));

            return $vendor->fresh(['user', 'plan', 'stores']);
        });
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        return DB::transaction(function () use ($vendor, $data) {
            $userData = $this->userUpdateValues($data);
            if (! empty($userData)) {
                $vendor->user?->update($userData);
            }

            $vendorData = $this->vendorUpdateValues($data, $vendor);
            if (! empty($vendorData)) {
                $vendor->update($vendorData);
            }

            return $vendor->fresh(['user', 'plan', 'stores']);
        });
    }

    public function delete(Vendor $vendor): void
    {
        DB::transaction(function () use ($vendor) {
            $vendor->stores()->delete();
            $vendor->delete();
            $vendor->user?->delete();
        });
    }

    public function findByIdOrUuid(string|int $id): Vendor
    {
        return is_numeric($id)
            ? Vendor::findOrFail($id)
            : Vendor::where('uuid', $id)->firstOrFail();
    }

    private function userValues(array $data, ?int $adminId): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'email' => $data['email'],
            'password' => $data['password'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'user_type' => 'vendor',
            'status' => 'active',
            'country_code' => $data['country_code'],
            'email_verified_at' => now(),
            'is_email_verified' => true,
            'is_phone_verified' => false,
            'is_kyc_verified' => ($data['kyc_status'] ?? 'pending') === 'verified',
            'kyc_status' => $data['kyc_status'] ?? 'pending',
            'timezone' => $data['timezone'] ?? 'UTC',
            'created_by' => $adminId,
        ];
    }

    private function userUpdateValues(array $data): array
    {
        return array_filter([
            'email' => $data['email'] ?? null,
            'password' => $data['password'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'kyc_status' => $data['kyc_status'] ?? null,
            'timezone' => $data['timezone'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function vendorValues(array $data, User $user): array
    {
        $status = $data['status'] ?? 'pending';
        $planDurationMonths = (int) ($data['plan_duration_months'] ?? 12);
        $planId = $data['plan_id'] ?? null;

        return array_merge($this->vendorBaseValues($data), [
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'company_slug' => $this->uniqueSlug($data['company_slug'] ?? Str::slug($data['company_name'])),
            'plan_id' => $planId,
            'plan_starts_at' => $planId ? now() : null,
            'plan_ends_at' => $planId ? now()->addMonths($planDurationMonths) : null,
            'plan_duration_months' => $planDurationMonths,
            'status' => $status,
            'kyc_status' => $data['kyc_status'] ?? 'pending',
            'onboarding_step' => $status === 'active' ? 5 : 1,
            'approved_by' => $status === 'active' ? $user->created_by : null,
            'approved_at' => $status === 'active' ? now() : null,
        ]);
    }

    private function vendorUpdateValues(array $data, Vendor $vendor): array
    {
        $values = $this->vendorBaseValues($data);

        if (array_key_exists('company_slug', $data)) {
            $values['company_slug'] = $this->uniqueSlug($data['company_slug'] ?: Str::slug($data['company_name'] ?? $vendor->company_name), $vendor->id);
        }

        foreach (['plan_id', 'plan_duration_months', 'status', 'kyc_status'] as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = $data[$field];
            }
        }

        if (($data['status'] ?? null) === 'active' && $vendor->status !== 'active') {
            $values['approved_by'] = auth()->id();
            $values['approved_at'] = now();
            $values['onboarding_step'] = max((int) $vendor->onboarding_step, 5);
        }

        return $values;
    }

    private function vendorBaseValues(array $data): array
    {
        $fields = [
            'company_name',
            'legal_name',
            'trading_name',
            'vat_number',
            'registration_number',
            'contact_email',
            'phone',
            'website',
            'country_code',
            'address_line1',
            'address_line2',
            'city',
            'postal_code',
            'logo_url',
            'banner_url',
            'description',
            'commission_rate',
            'commission_type',
            'timezone',
            'metadata',
            'magento_base_url',
            'magento_admin_username',
            'magento_admin_pass',
            'magento_access_token',
            'magento_admin_token',
            'magento_website_id',
            'magento_store_group_id',
            'magento_root_category_id',
        ];

        $values = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = $data[$field];
            }
        }

        return $values;
    }

    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug) ?: 'vendor';
        $candidate = $base;
        $counter = 1;

        while (
            Vendor::where('company_slug', $candidate)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $base . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }
}
