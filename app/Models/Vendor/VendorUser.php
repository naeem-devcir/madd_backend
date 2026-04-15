<?php

namespace App\Models\Vendor;

use App\Jobs\Notification\SendVendorUserInvitation;
use App\Models\Traits\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorUser extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'vendor_users';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'user_id',
        'role',
        'permissions',
        'is_active',
        'invited_by',
        'invited_at',
        'accepted_at',
        'last_login_at',
        'last_login_ip',
        'notification_prefs',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'notification_prefs' => 'array',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    // ========== Relationships ==========

    /**
     * Get the vendor this user belongs to
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }

    /**
     * Get the user account
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the user who invited this user
     */
    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by', 'id');
    }

    // ========== Scopes ==========

    /**
     * Scope to active users
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to inactive users
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope by role
     */
    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope to pending invitations (not accepted)
     */
    public function scopePendingInvitation($query)
    {
        return $query->whereNull('accepted_at')->where('is_active', true);
    }

    /**
     * Scope to accepted invitations
     */
    public function scopeAccepted($query)
    {
        return $query->whereNotNull('accepted_at');
    }

    /**
     * Scope by vendor
     */
    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    // ========== Accessors ==========

    /**
     * Get role label
     */
    public function getRoleLabelAttribute(): string
    {
        $labels = [
            'admin' => 'Administrator',
            'orders' => 'Order Manager',
            'products' => 'Product Manager',
            'marketing' => 'Marketing Manager',
            'seo' => 'SEO Specialist',
            'support' => 'Customer Support',
        ];

        return $labels[$this->role] ?? ucfirst($this->role);
    }

    /**
     * Get role icon
     */
    public function getRoleIconAttribute(): string
    {
        $icons = [
            'admin' => '👑',
            'orders' => '📦',
            'products' => '🏷️',
            'marketing' => '📢',
            'seo' => '🔍',
            'support' => '💬',
        ];

        return $icons[$this->role] ?? '👤';
    }

    /**
     * Get permission list
     */
    public function getPermissionListAttribute(): array
    {
        $basePermissions = $this->getBasePermissionsForRole();

        if ($this->permissions) {
            return array_merge($basePermissions, $this->permissions);
        }

        return $basePermissions;
    }

    /**
     * Check if invitation is pending
     */
    public function getIsInvitationPendingAttribute(): bool
    {
        return is_null($this->accepted_at) && $this->is_active;
    }

    /**
     * Check if user is accepted
     */
    public function getIsAcceptedAttribute(): bool
    {
        return ! is_null($this->accepted_at);
    }

    /**
     * Get user's full name
     */
    public function getFullNameAttribute(): string
    {
        return $this->user?->full_name ?? 'Unknown User';
    }

    /**
     * Get user's email
     */
    public function getEmailAttribute(): string
    {
        return $this->user?->email ?? 'unknown@example.com';
    }

    /**
     * Get days since invitation
     */
    public function getDaysSinceInvitationAttribute(): int
    {
        if (! $this->invited_at) {
            return 0;
        }

        return now()->diffInDays($this->invited_at);
    }

    // ========== Methods ==========

    /**
     * Get base permissions for role
     */
    protected function getBasePermissionsForRole(): array
    {
        $permissions = [
            'admin' => [
                'view_dashboard', 'manage_orders', 'manage_products', 'manage_stores',
                'manage_team', 'view_reports', 'manage_settings', 'view_settlements',
            ],
            'orders' => [
                'view_dashboard', 'view_orders', 'manage_orders', 'process_returns',
                'create_shipments', 'view_customers',
            ],
            'products' => [
                'view_dashboard', 'view_products', 'create_products', 'edit_products',
                'delete_products', 'manage_inventory', 'view_categories',
            ],
            'marketing' => [
                'view_dashboard', 'view_products', 'manage_coupons', 'view_reports',
                'manage_seo', 'view_analytics',
            ],
            'seo' => [
                'view_dashboard', 'view_products', 'edit_products_seo', 'manage_meta_tags',
                'view_analytics', 'manage_url_rewrites',
            ],
            'support' => [
                'view_dashboard', 'view_orders', 'view_customers', 'process_returns',
                'send_messages', 'view_tickets',
            ],
        ];

        return $permissions[$this->role] ?? [];
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permission_list);
    }

    /**
     * Accept invitation
     */
    public function accept(): void
    {
        $this->accepted_at = now();
        $this->save();

        // Assign role to user
        $this->user->assignRole('vendor_user');
    }

    /**
     * Resend invitation
     */
    public function resendInvitation(): void
    {
        $this->invited_at = now();
        $this->save();

        // Send invitation email
        SendVendorUserInvitation::dispatch($this);
    }

    /**
     * Deactivate user
     */
    public function deactivate(): void
    {
        $this->is_active = false;
        $this->save();

        // Revoke user tokens
        $this->user?->tokens()->delete();
    }

    /**
     * Activate user
     */
    public function activate(): void
    {
        $this->is_active = true;
        $this->save();
    }

    /**
     * Update last login info
     */
    public function recordLogin(string $ip): void
    {
        $this->last_login_at = now();
        $this->last_login_ip = $ip;
        $this->save();
    }

    /**
     * Get notification preference for channel
     */
    public function getNotificationPreference(string $channel, string $type): bool
    {
        return $this->notification_prefs[$channel][$type] ?? true;
    }

    /**
     * Update notification preferences
     */
    public function updateNotificationPreferences(array $preferences): void
    {
        $this->notification_prefs = array_merge($this->notification_prefs ?? [], $preferences);
        $this->save();
    }
}
