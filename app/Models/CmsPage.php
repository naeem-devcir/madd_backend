<?php

namespace App\Models;

use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CmsPage extends Model
{
    use HasFactory;

    protected $table = 'cms_pages';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'magento_id',
        'magento_store_id',
        'identifier',
        'title',
        'page_layout',
        'content',
        'content_heading',
        'is_active',
        'sort_order',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'custom_theme',
        'custom_root_template',
        'layout_update_xml',
        'custom_layout_update_xml',
        'custom_theme_from',
        'custom_theme_to',
        'meta_data',
        'magento_data',
        'store_ids',
        'last_synced_at',
        'magento_updated_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'layout_update_xml' => 'string',
        'custom_layout_update_xml' => 'string',
        'custom_theme_from' => 'date',
        'custom_theme_to' => 'date',
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

    public function getExcerptAttribute($length = 150): string
    {
        return Str::limit(strip_tags($this->content), $length);
    }

    public function getUrlAttribute(): string
    {
        return route('cms.page', $this->identifier);
    }
}
