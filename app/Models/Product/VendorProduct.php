<?php

namespace App\Models\Product;

use App\Models\Inventory\InventoryLog;
use App\Models\Order\OrderItem;
use App\Models\Review\Review;
use App\Models\Traits\HasUuid;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorProduct extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'vendor_products';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'vendor_store_id',
        'magento_product_id',
        'magento_sku',
        'sku',
        'name',
        'type_id',
        'attribute_set_id',
        'price',
        'quantity',
        'status',
        'sync_status',
        'last_synced_at',
        'sync_errors',
        'metadata',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'sync_errors' => 'array',
        'metadata' => 'array',
        'magento_product_id' => 'integer',
        'quantity' => 'integer',
        'price' => 'decimal:4',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function store()
    {
        return $this->belongsTo(VendorStore::class, 'vendor_store_id');
    }

    public function draft()
    {
        return $this->hasOne(ProductDraft::class, 'vendor_product_id')->latest();
    }

    public function drafts()
    {
        return $this->hasMany(ProductDraft::class, 'vendor_product_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'vendor_product_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'vendor_product_id');
    }

    public function sharing()
    {
        return $this->hasMany(ProductSharing::class, 'source_product_id');
    }

    public function sharedToStores()
    {
        return $this->belongsToMany(
            VendorStore::class,
            'product_sharing',
            'source_product_id',
            'target_store_id'
        )->withPivot('commission_percentage', 'markup_percentage', 'status');
    }

    public function inventoryLogs()
    {
        return $this->hasMany(InventoryLog::class, 'vendor_product_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSynced($query)
    {
        return $query->where('sync_status', 'synced');
    }

    public function scopePendingSync($query)
    {
        return $query->where('sync_status', 'pending');
    }

    public function scopeBySku($query, string $sku)
    {
        return $query->where('sku', $sku);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getCurrentStockAttribute(): int
    {
        return $this->quantity ?? 0;
    }

    public function getIsLowStockAttribute(): bool
    {
        $threshold = $this->metadata['low_stock_threshold'] ?? 5;

        return $this->current_stock <= $threshold && $this->current_stock > 0;
    }

    public function getIsOutOfStockAttribute(): bool
    {
        return $this->current_stock <= 0;
    }

    public function markAsSynced(): void
    {
        $this->update([
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'sync_errors' => null,
        ]);
    }

    public function markSyncFailed(string $error): void
    {
        $errors = $this->sync_errors ?? [];
        $errors[] = ['error' => $error, 'timestamp' => now()->toIso8601String()];

        $this->update([
            'sync_status' => 'failed',
            'sync_errors' => $errors,
        ]);
    }
}
