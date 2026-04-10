<?php

namespace App\Models\Product;

use App\Models\Traits\HasUuid;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Models\Order\OrderItem;
use App\Models\Review\Review;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorProduct extends Model
{
    use HasFactory, SoftDeletes, HasUuid;
    
    protected $table = 'vendor_products';
    
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    
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
    ];
    
    // ========== Relationships ==========
    
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }
    
    public function store()
    {
        return $this->belongsTo(VendorStore::class, 'vendor_store_id', 'uuid');
    }
    
    public function draft()
    {
        return $this->hasOne(ProductDraft::class, 'vendor_product_id', 'id')->latest();
    }
    
    public function drafts()
    {
        return $this->hasMany(ProductDraft::class, 'vendor_product_id', 'id');
    }
    
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'vendor_product_id', 'id');
    }
    
    public function reviews()
    {
        return $this->hasMany(Review::class, 'vendor_product_id', 'id');
    }
    
    public function sharing()
    {
        return $this->hasMany(ProductSharing::class, 'source_product_id', 'id');
    }
    
    public function sharedToStores()
    {
        return $this->belongsToMany(VendorStore::class, 'product_sharing', 'source_product_id', 'target_store_id', 'id', 'uuid')
            ->withPivot('commission_percentage', 'markup_percentage', 'status');
    }
    
    // ========== Scopes ==========
    
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
    
    public function scopeBySku($query, $sku)
    {
        return $query->where('sku', $sku);
    }
    
    // ========== Accessors ==========
    
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }
    
    public function getIsSyncedAttribute(): bool
    {
        return $this->sync_status === 'synced';
    }
    
    public function getIsPendingSyncAttribute(): bool
    {
        return $this->sync_status === 'pending';
    }
    
    public function getProductUrlAttribute(): string
    {
        return route('product.show', $this->sku);
    }
    
    public function getAdminUrlAttribute(): string
    {
        return route('admin.products.edit', $this->id);
    }
    
    // ========== Methods ==========
    
    public function markAsSynced(): void
    {
        $this->sync_status = 'synced';
        $this->last_synced_at = now();
        $this->sync_errors = null;
        $this->save();
    }
    
    public function markSyncFailed(string $error): void
    {
        $this->sync_status = 'failed';
        $this->sync_errors = array_merge($this->sync_errors ?? [], [['error' => $error, 'timestamp' => now()]]);
        $this->save();
    }
    
    public function activate(): void
    {
        $this->status = 'active';
        $this->save();
        
        // Sync to Magento
        \App\Jobs\Product\SyncProductToMagento::dispatch($this);
    }
    
    public function deactivate(): void
    {
        $this->status = 'inactive';
        $this->save();
        
        // Sync to Magento
        \App\Jobs\Product\SyncProductToMagento::dispatch($this);
    }
    
    public function getTotalSoldQuantity(): int
    {
        return $this->orderItems()->sum('qty_ordered');
    }
    
    public function getTotalRevenue(): float
    {
        return $this->orderItems()->sum('row_total');
    }
    
    public function getAverageRating(): float
    {
        return $this->reviews()->where('status', 'approved')->avg('rating') ?? 0;
    }
    
    public function getTotalReviews(): int
    {
        return $this->reviews()->where('status', 'approved')->count();
    }
}
