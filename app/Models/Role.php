<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'guard_name',
        'is_system',
        'level',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'level' => 'integer',
    ];

    // ========== Relationships ==========

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_has_roles', 'role_id', 'user_id');
    }

    // ========== Scopes ==========

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeNonSystem($query)
    {
        return $query->where('is_system', false);
    }

    public function scopeByLevel($query, $level)
    {
        return $query->where('level', '>=', $level);
    }

    // ========== Accessors ==========

    public function getIsSuperAdminAttribute(): bool
    {
        return $this->name === 'super_admin';
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->name === 'admin';
    }

    public function getIsVendorAttribute(): bool
    {
        return $this->name === 'vendor';
    }

    public function getIsCustomerAttribute(): bool
    {
        return $this->name === 'customer';
    }
}
