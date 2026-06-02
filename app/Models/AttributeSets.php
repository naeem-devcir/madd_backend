<?php

namespace App\Models;

use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AttributeSets extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'attribute_sets';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'magento_attr_set_id',
        'attribute_set_name',
        'sort_order',
        'entity_type_id',
        'magento_entity_type_code',
        'description',
        'assigned_attribute_ids',
        'attribute_group_data',
        'last_synced_at',
        'sync_status',
        'sync_error_message',
        'sync_attempts',
        'is_active',
        'local_display_name',
        'local_notes',
    ];

    protected $casts = [
        'uuid' => 'string',
        'assigned_attribute_ids' => 'array',
        'attribute_group_data' => 'array',
        'last_synced_at' => 'datetime',
        'sort_order' => 'integer',
        'entity_type_id' => 'integer',
        'sync_attempts' => 'integer',
        'is_active' => 'boolean',
        'magento_attr_set_id' => 'integer',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the vendor that owns the attribute set.
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * Scope a query to only include attribute sets for a specific vendor by UUID.
     */
    public function scopeForVendorUuid($query, string $vendorUuid)
    {
        return $query->whereHas('vendor', function ($q) use ($vendorUuid) {
            $q->where('uuid', $vendorUuid);
        });
    }

    /**
     * Scope a query to only include attribute sets for a specific vendor by ID.
     */
    public function scopeForVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeSynced($query)
    {
        return $query->where('sync_status', 'synced');
    }

    public function scopePendingSync($query)
    {
        return $query->whereIn('sync_status', ['pending', 'failed']);
    }

    // Accessors
    public function getAttributeCountAttribute(): int
    {
        return count($this->assigned_attribute_ids ?? []);
    }

    public function getIsFullySyncedAttribute(): bool
    {
        return $this->sync_status === 'synced' && !is_null($this->magento_attr_set_id);
    }

    // Helper methods for Magento API operations
    public function getMagentoApiEndpoint(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/rest/V1/products/attribute-sets/' . $this->magento_attr_set_id;
    }

    public function markAsSynced(int $magentoId): void
    {
        $this->update([
            'magento_attr_set_id' => $magentoId,
            'last_synced_at' => now(),
            'sync_status' => 'synced',
            'sync_error_message' => null,
            'sync_attempts' => 0,
        ]);
    }

    public function markSyncFailed(string $errorMessage): void
    {
        $this->update([
            'sync_status' => 'failed',
            'sync_error_message' => $errorMessage,
            'sync_attempts' => $this->sync_attempts + 1,
        ]);
    }
}