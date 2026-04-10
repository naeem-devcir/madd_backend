<?php

namespace App\Models\Config;

use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Theme extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'themes';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'preview_url',
        'screenshot_url',
        'category',
        'config_schema',
        'is_active',
        'is_premium',
        'price',
    ];

    protected $casts = [
        'config_schema' => 'array',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
        'price' => 'decimal:2',
    ];

    // ========== Relationships ==========
    
    public function stores()
    {
        return $this->hasMany(VendorStore::class, 'theme_id', 'id');
    }
    
    // ========== Scopes ==========
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function scopeFree($query)
    {
        return $query->where('is_premium', false);
    }
    
    public function scopePremium($query)
    {
        return $query->where('is_premium', true);
    }
    
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }
    
    // ========== Accessors ==========
    
    public function getIsFreeAttribute(): bool
    {
        return !$this->is_premium;
    }
    
    public function getConfigAttribute(): array
    {
        return $this->config_schema ?? [];
    }
    
    public function getPreviewUrlAttribute($value): ?string
    {
        if ($value) {
            return $value;
        }
        
        return asset('themes/' . $this->slug . '/preview.jpg');
    }
}