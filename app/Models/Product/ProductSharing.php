<?php

namespace App\Models\Product;

use App\Models\Traits\HasUuid;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductSharing extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'product_sharing';
    
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'source_product_id',
        'target_store_id',
        'sharing_type',
        'commission_percentage',
        'markup_percentage',
        'status',
        'approved_by',
        'approved_at',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'commission_percentage' => 'decimal:2',
        'markup_percentage' => 'decimal:2',
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ========== Relationships ==========
    
    /**
     * Get the source product being shared
     */
    public function sourceProduct()
    {
        return $this->belongsTo(VendorProduct::class, 'source_product_id', 'id');
    }
    
    /**
     * Get the target store receiving the product
     */
    public function targetStore()
    {
        return $this->belongsTo(VendorStore::class, 'target_store_id', 'uuid');
    }
    
    /**
     * Get the vendor who owns the source product
     */
    public function sourceVendor()
    {
        return $this->hasOneThrough(
            Vendor::class,
            VendorProduct::class,
            'id',
            'uuid',
            'source_product_id',
            'vendor_id'
        );
    }
    
    /**
     * Get the vendor who owns the target store
     */
    public function targetVendor()
    {
        return $this->hasOneThrough(
            Vendor::class,
            VendorStore::class,
            'uuid',
            'uuid',
            'target_store_id',
            'vendor_id'
        );
    }
    
    /**
     * Get the admin who approved this sharing
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by', 'uuid');
    }
    
    // ========== Scopes ==========
    
    /**
     * Scope to active sharings
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }
    
    /**
     * Scope to pending sharings
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    /**
     * Scope to inactive sharings
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }
    
    /**
     * Scope by sharing type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('sharing_type', $type);
    }
    
    /**
     * Scope by source product
     */
    public function scopeBySourceProduct($query, $productId)
    {
        return $query->where('source_product_id', $productId);
    }
    
    /**
     * Scope by target store
     */
    public function scopeByTargetStore($query, $storeId)
    {
        return $query->where('target_store_id', $storeId);
    }
    
    /**
     * Scope to expiring soon (within 7 days)
     */
    public function scopeExpiringSoon($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(7))
            ->where('expires_at', '>', now());
    }
    
    /**
     * Scope to expired sharings
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
    
    // ========== Accessors ==========
    
    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'pending' => 'Pending Approval',
            'active' => 'Active',
            'inactive' => 'Inactive',
        ];
        
        return $labels[$this->status] ?? ucfirst($this->status);
    }
    
    /**
     * Get status color for UI
     */
    public function getStatusColorAttribute(): string
    {
        $colors = [
            'pending' => 'yellow',
            'active' => 'green',
            'inactive' => 'gray',
        ];
        
        return $colors[$this->status] ?? 'gray';
    }
    
    /**
     * Get sharing type label
     */
    public function getSharingTypeLabelAttribute(): string
    {
        $labels = [
            'full' => 'Full Product',
            'partial' => 'Partial (Custom Price)',
            'referral' => 'Referral Only',
        ];
        
        return $labels[$this->sharing_type] ?? ucfirst($this->sharing_type);
    }
    
    /**
     * Check if sharing is full product
     */
    public function getIsFullProductAttribute(): bool
    {
        return $this->sharing_type === 'full';
    }
    
    /**
     * Check if sharing has commission
     */
    public function getHasCommissionAttribute(): bool
    {
        return !is_null($this->commission_percentage);
    }
    
    /**
     * Check if sharing has markup
     */
    public function getHasMarkupAttribute(): bool
    {
        return !is_null($this->markup_percentage);
    }
    
    /**
     * Get effective price multiplier for target store
     */
    public function getPriceMultiplierAttribute(): float
    {
        if ($this->markup_percentage) {
            return 1 + ($this->markup_percentage / 100);
        }
        
        return 1;
    }
    
    /**
     * Get commission rate for source vendor
     */
    public function getCommissionRateAttribute(): float
    {
        return $this->commission_percentage ?? 0;
    }
    
    /**
     * Check if sharing is expired
     */
    public function getIsExpiredAttribute(): bool
    {
        if (!$this->expires_at) {
            return false;
        }
        
        return $this->expires_at->isPast();
    }
    
    /**
     * Get days until expiry
     */
    public function getDaysUntilExpiryAttribute(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }
        
        if ($this->is_expired) {
            return 0;
        }
        
        return now()->diffInDays($this->expires_at);
    }
    
    // ========== Methods ==========
    
    /**
     * Approve the product sharing
     */
    public function approve(User $admin): void
    {
        $this->status = 'active';
        $this->approved_by = $admin->uuid;
        $this->approved_at = now();
        $this->save();
    }
    
    /**
     * Reject the product sharing
     */
    public function reject(): void
    {
        $this->status = 'inactive';
        $this->save();
    }
    
    /**
     * Deactivate the product sharing
     */
    public function deactivate(): void
    {
        $this->status = 'inactive';
        $this->save();
    }
    
    /**
     * Renew the product sharing
     */
    public function renew(int $days = 30): void
    {
        $this->expires_at = now()->addDays($days);
        $this->status = 'active';
        $this->save();
    }
    
    /**
     * Calculate price for target store
     */
    public function calculatePrice(float $originalPrice): float
    {
        return round($originalPrice * $this->price_multiplier, 2);
    }
    
    /**
     * Calculate commission for source vendor
     */
    public function calculateCommission(float $salePrice): float
    {
        if (!$this->commission_percentage) {
            return 0;
        }
        
        return round($salePrice * ($this->commission_percentage / 100), 2);
    }
    
    /**
     * Get product data for target store
     */
    public function getSharedProductData(): array
    {
        $sourceProduct = $this->sourceProduct;
        
        if (!$sourceProduct) {
            return [];
        }
        
        $data = [
            'sku' => $sourceProduct->sku,
            'name' => $sourceProduct->name,
            'source_vendor_id' => $sourceProduct->vendor_id,
            'source_product_id' => $sourceProduct->id,
            'sharing_id' => $this->id,
        ];
        
        if ($this->sharing_type === 'full') {
            $data['price'] = $sourceProduct->price;
        } elseif ($this->sharing_type === 'partial') {
            $data['price'] = $this->calculatePrice($sourceProduct->price);
        }
        
        return $data;
    }
    
    /**
     * Check if product is available in target store
     */
    public function isAvailable(): bool
    {
        return $this->status === 'active' && !$this->is_expired;
    }
}
