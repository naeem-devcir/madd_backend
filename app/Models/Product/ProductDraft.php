<?php

namespace App\Models\Product;

use App\Events\Product\ProductApproved;
use App\Jobs\Notification\SendProductApprovalNotification;
use App\Jobs\Product\SyncProductToMagento;
use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDraft extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'product_drafts';

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

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function store()
    {
        return $this->belongsTo(VendorStore::class, 'vendor_store_id');
    }

    public function product()
    {
        return $this->belongsTo(VendorProduct::class, 'vendor_product_id');
    }

    public function parentDraft()
    {
        return $this->belongsTo(self::class, 'parent_draft_id');
    }

    public function childDrafts()
    {
        return $this->hasMany(self::class, 'parent_draft_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approval()
    {
        return $this->hasOne(ProductApproval::class, 'product_draft_id');
    }

    public function getCurrentPriceAttribute(): float
    {
        if (
            $this->special_price &&
            (! $this->special_price_from || $this->special_price_from <= now()) &&
            (! $this->special_price_to || $this->special_price_to >= now())
        ) {
            return (float) $this->special_price;
        }

        return (float) $this->price;
    }

    public function submitForApproval(): void
    {
        $this->update(['status' => 'pending']);

        ProductApproval::create([
            'product_draft_id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'approval_type' => $this->vendor_product_id ? 'update' : 'new',
            'submitted_data' => $this->toArray(),
            'status' => 'pending',
        ]);

        SendProductApprovalNotification::dispatch($this);
    }

    public function approve(User $admin, ?string $notes = null): void
    {
        $this->update([
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        if ($this->approval) {
            $this->approval->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'admin_notes' => $notes,
            ]);
        }

        SyncProductToMagento::dispatch($this);
        event(new ProductApproved($this));
    }

    public function reject(User $admin, string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        if ($this->approval) {
            $this->approval->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);
        }
    }

    public function requestModification(User $admin, string $notes): void
    {
        $this->update([
            'status' => 'needs_modification',
            'review_notes' => $notes,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        if ($this->approval) {
            $this->approval->update([
                'status' => 'needs_modification',
                'admin_notes' => $notes,
                'reviewed_by' => $admin->id,
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
        $newVersion->uuid = null;
        $newVersion->save();

        return $newVersion;
    }
}
