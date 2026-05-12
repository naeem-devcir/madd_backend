<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $table = 'categories';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'magento_id',
        'magento_store_id',
        'name',
        'slug',
        'description',
        'parent_id',
        'parent_path',
        'position',
        'is_active',
        'include_in_menu',
        'image_url',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'meta_data',
        'magento_data',
        'level',
        'children_count',
        'last_synced_at',
        'magento_updated_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'include_in_menu' => 'boolean',
        'meta_data' => 'array',
        'magento_data' => 'array',
        'position' => 'integer',
        'level' => 'integer',
        'children_count' => 'integer',
        'last_synced_at' => 'datetime',
        'magento_updated_at' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // Scopes
    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRootLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    // Accessors
    public function getRouteKeyName()
    {
        return 'uuid'; // Use UUID for route binding
    }

    public function getFullPathAttribute(): string
    {
        if (!$this->parent_path) {
            return $this->name;
        }
        
        $parentNames = collect(explode('/', $this->parent_path))
            ->map(function($id) {
                $category = self::find($id);
                return $category ? $category->name : '';
            })
            ->filter()
            ->implode(' / ');
            
        return $parentNames ? $parentNames . ' / ' . $this->name : $this->name;
    }
}