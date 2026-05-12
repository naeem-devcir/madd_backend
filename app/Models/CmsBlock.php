<?php

namespace App\Models;

use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;


class CmsBlock extends Model
{
    use HasFactory;

    protected $table = 'cms_blocks';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'magento_id',
        'magento_store_id',
        'identifier',
        'title',
        'content',
        'is_active',
        'meta_data',
        'magento_data',
        'store_ids',
        'last_synced_at',
        'magento_updated_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta_data' => 'array',
        'magento_data' => 'array',
        'store_ids' => 'array',
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

    // Scopes
    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForStore($query, $storeId)
    {
        return $query->whereJsonContains('store_ids', $storeId)
            ->orWhereNull('store_ids');
    }

    // Accessors
    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function getShortContentAttribute($length = 100): string
    {
        return Str::limit(strip_tags($this->content), $length);
    }
}