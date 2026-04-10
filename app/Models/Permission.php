<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    use HasFactory, SoftDeletes;
    
    protected $fillable = [
        'name',
        'display_name',
        'permission_description',
        'module',
        'guard_name',
        'is_system',
    ];
    
    protected $casts = [
        'is_system' => 'boolean',
    ];
    
    // ========== Scopes ==========
    
    public function scopeByModule($query, $module)
    {
        return $query->where('module', $module);
    }
    
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }
    
    // ========== Accessors ==========
    
    public function getModuleNameAttribute(): string
    {
        return ucfirst(str_replace('_', ' ', $this->module));
    }
    
    public function getDisplayNameAttribute($value): string
    {
        return $value ?? ucfirst(str_replace('.', ' - ', $this->name));
    }
}