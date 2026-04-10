<?php

namespace App\Models\Product;

use App\Models\Traits\HasUuid;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDraft extends Model
{
    use HasFactory, HasUuid;
    
    protected $table = 'product_drafts';
    
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'uuid',
        'vendor_id',
        'vendor_store_id',
        'vendor_product_id',
        'parent_draft_id',
        'version',
        'sku',
        'name',
        'description',
        'short_description',
        'price',
        'special_price',
        'special_price_from',
        'special_price_to',
        'quantity',
        'weight',
        'status',
        'product_data',
        'media_gallery',
        'categories',
        'attributes',
        'seo_data',
        'review_notes',
        'rejection_reason',
        'auto_approve',
        'scheduled_publish_at',
        'magento_product_id',
        'reviewed_by',
        'reviewed_at',
        'published_at',
    ];
    
    protected $casts = [
        'special_price_from' => 'datetime',
        'special_price_to' => 'datetime',
        'scheduled_publish_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'price' => 'decimal:4',
        'special_price' => 'decimal:4',
        'weight' => 'decimal:4',
        'quantity' => 'integer',
        'version' => 'integer',
        'product_data' => 'array',
        'media_gallery' => 'array',
        'categories' => 'array',
        'attributes' => 'array',
        'seo_data' => 'array',
        'auto_approve' => 'boolean',
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
    
    public function product()
    {
        return $this->belongsTo(VendorProduct::class, 'vendor_product_id', 'id');
    }
    
    public function parentDraft()
    {
        return $this->belongsTo(ProductDraft::class, 'parent_draft_id', 'id');
    }
    
    public function childDrafts()
    {
        return $this->hasMany(ProductDraft::class, 'parent_draft_id', 'id');
    }
    
    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'uuid');
    }
    
    public function approval()
    {
        return $this->hasOne(ProductApproval::class, 'product_draft_id', 'id');
    }
    
    // ========== Scopes ==========
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
    
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
    
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }
    
    public function scopeAutoApprove($query)
    {
        return $query->where('auto_approve', true);
    }
    
    // ========== Accessors ==========
    
    public function getIsDraftAttribute(): bool
    {
        return $this->status === 'draft';
    }
    
    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }
    
    public function getIsApprovedAttribute(): bool
    {
        return $this->status === 'approved';
    }
    
    public function getIsRejectedAttribute(): bool
    {
        return $this->status === 'rejected';
    }
    
    public function getIsNeedsModificationAttribute(): bool
    {
        return $this->status === 'needs_modification';
    }
    
    public function getCurrentPriceAttribute(): float
    {
        if ($this->special_price && 
            (!$this->special_price_from || $this->special_price_from <= now()) &&
            (!$this->special_price_to || $this->special_price_to >= now())) {
            return $this->special_price;
        }
        
        return $this->price;
    }
    
    // ========== Methods ==========
    
    public function submitForApproval(): void
    {
        $this->status = 'pending';
        $this->save();
        
        // Create approval record
        ProductApproval::create([
            'product_draft_id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'approval_type' => $this->vendor_product_id ? 'update' : 'new',
            'submitted_data' => $this->toArray(),
            'status' => 'pending',
        ]);
        
        // Notify admins
        \App\Jobs\Notification\SendProductApprovalNotification::dispatch($this);
    }
    
    public function approve(User $admin, ?string $notes = null): void
    {
        $this->status = 'approved';
        $this->reviewed_by = $admin->uuid;
        $this->reviewed_at = now();
        $this->review_notes = $notes;
        $this->save();
        
        // Update approval record
        if ($this->approval) {
            $this->approval->update([
                'status' => 'approved',
                'reviewed_by' => $admin->uuid,
                'reviewed_at' => now(),
                'admin_notes' => $notes,
            ]);
        }
        
        // Sync to Magento
        \App\Jobs\Product\SyncProductToMagento::dispatch($this);
        
        event(new \App\Events\Product\ProductApproved($this));
    }
    
    public function reject(User $admin, string $reason): void
    {
        $this->status = 'rejected';
        $this->rejection_reason = $reason;
        $this->reviewed_by = $admin->uuid;
        $this->reviewed_at = now();
        $this->save();
        
        // Update approval record
        if ($this->approval) {
            $this->approval->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->uuid,
                'reviewed_at' => now(),
            ]);
        }
    }
    
    public function requestModification(User $admin, string $notes): void
    {
        $this->status = 'needs_modification';
        $this->review_notes = $notes;
        $this->reviewed_by = $admin->uuid;
        $this->reviewed_at = now();
        $this->save();
        
        // Update approval record
        if ($this->approval) {
            $this->approval->update([
                'status' => 'needs_modification',
                'admin_notes' => $notes,
                'reviewed_by' => $admin->uuid,
                'reviewed_at' => now(),
            ]);
        }
    }
    
    public function createNewVersion(): self
    {
        $newVersion = $this->replicate();
        $newVersion->version = $this->version + 1;
        $newVersion->parent_draft_id = $this->id;
        $newVersion->status = 'draft';
        $newVersion->reviewed_by = null;
        $newVersion->reviewed_at = null;
        $newVersion->rejection_reason = null;
        $newVersion->save();
        
        return $newVersion;
    }
    
    public function publish(): void
    {
        if ($this->status !== 'approved') {
            throw new \Exception('Cannot publish draft that is not approved');
        }
        
        $this->published_at = now();
        $this->save();
        
        // Create or update vendor product
        if ($this->vendor_product_id) {
            $product = $this->product;
            $product->update([
                'sku' => $this->sku,
                'name' => $this->name,
                'status' => 'active',
                'sync_status' => 'pending',
            ]);
        } else {
            $product = VendorProduct::create([
                'vendor_id' => $this->vendor_id,
                'vendor_store_id' => $this->vendor_store_id,
                'sku' => $this->sku,
                'name' => $this->name,
                'status' => 'active',
                'sync_status' => 'pending',
            ]);
            $this->vendor_product_id = $product->id;
            $this->save();
        }
        
        event(new \App\Events\Product\ProductCreated($product, $this));
    }
}
