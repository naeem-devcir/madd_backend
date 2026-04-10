<?php

namespace App\Models\Product;

use App\Models\Traits\HasUuid;
use App\Models\Vendor\Vendor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductApproval extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'product_approvals';
    
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'product_draft_id',
        'vendor_id',
        'approval_type',
        'status',
        'submitted_data',
        'admin_notes',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'submitted_data' => 'array',
        'admin_notes' => 'array',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ========== Relationships ==========
    
    /**
     * Get the product draft being approved
     */
    public function productDraft()
    {
        return $this->belongsTo(ProductDraft::class, 'product_draft_id', 'id');
    }
    
    /**
     * Get the vendor who submitted the product
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }
    
    /**
     * Get the admin who reviewed this approval
     */
    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'uuid');
    }
    
    // ========== Scopes ==========
    
    /**
     * Scope to pending approvals
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    /**
     * Scope to approved approvals
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
    
    /**
     * Scope to rejected approvals
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
    
    /**
     * Scope to needs modification approvals
     */
    public function scopeNeedsModification($query)
    {
        return $query->where('status', 'needs_modification');
    }
    
    /**
     * Scope by approval type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('approval_type', $type);
    }
    
    /**
     * Scope by vendor
     */
    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
    
    /**
     * Scope for today's approvals
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
    
    /**
     * Scope pending for more than X days
     */
    public function scopePendingForDays($query, $days)
    {
        return $query->where('status', 'pending')
            ->where('created_at', '<=', now()->subDays($days));
    }
    
    // ========== Accessors ==========
    
    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'pending' => 'Pending Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'needs_modification' => 'Needs Modification',
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
            'approved' => 'green',
            'rejected' => 'red',
            'needs_modification' => 'orange',
        ];
        
        return $colors[$this->status] ?? 'gray';
    }
    
    /**
     * Get approval type label
     */
    public function getApprovalTypeLabelAttribute(): string
    {
        $labels = [
            'new' => 'New Product',
            'update' => 'Product Update',
            'restore' => 'Product Restore',
            'delete' => 'Product Deletion',
        ];
        
        return $labels[$this->approval_type] ?? ucfirst($this->approval_type);
    }
    
    /**
     * Get days pending
     */
    public function getDaysPendingAttribute(): int
    {
        if ($this->status !== 'pending') {
            return 0;
        }
        
        return now()->diffInDays($this->created_at);
    }
    
    /**
     * Check if approval is urgent (pending > 3 days)
     */
    public function getIsUrgentAttribute(): bool
    {
        return $this->status === 'pending' && $this->days_pending >= 3;
    }
    
    /**
     * Get submitted product name
     */
    public function getProductNameAttribute(): ?string
    {
        return $this->submitted_data['name'] ?? null;
    }
    
    /**
     * Get submitted product SKU
     */
    public function getProductSkuAttribute(): ?string
    {
        return $this->submitted_data['sku'] ?? null;
    }
    
    /**
     * Get submitted product price
     */
    public function getProductPriceAttribute(): ?float
    {
        return $this->submitted_data['price'] ?? null;
    }
    
    // ========== Methods ==========
    
    /**
     * Approve the product
     */
    public function approve(User $admin, ?string $notes = null): void
    {
        $this->status = 'approved';
        $this->admin_notes = $notes ? ['approval_notes' => $notes] : $this->admin_notes;
        $this->reviewed_by = $admin->uuid;
        $this->reviewed_at = now();
        $this->save();
        
        // Update the associated draft
        if ($this->productDraft) {
            $this->productDraft->approve($admin, $notes);
        }
    }
    
    /**
     * Reject the product
     */
    public function reject(User $admin, string $reason): void
    {
        $this->status = 'rejected';
        $this->rejection_reason = $reason;
        $this->reviewed_by = $admin->uuid;
        $this->reviewed_at = now();
        $this->save();
        
        // Update the associated draft
        if ($this->productDraft) {
            $this->productDraft->reject($admin, $reason);
        }
    }
    
    /**
     * Request modifications
     */
    public function requestModification(User $admin, string $notes): void
    {
        $this->status = 'needs_modification';
        $this->admin_notes = array_merge($this->admin_notes ?? [], ['modification_notes' => $notes]);
        $this->reviewed_by = $admin->uuid;
        $this->reviewed_at = now();
        $this->save();
        
        // Update the associated draft
        if ($this->productDraft) {
            $this->productDraft->requestModification($admin, $notes);
        }
    }
    
    /**
     * Resubmit for approval after modification
     */
    public function resubmit(): void
    {
        $this->status = 'pending';
        $this->rejection_reason = null;
        $this->reviewed_by = null;
        $this->reviewed_at = null;
        $this->save();
    }
    
    /**
     * Get approval timeline
     */
    public function getTimeline(): array
    {
        $timeline = [
            [
                'event' => 'Submitted',
                'status' => 'pending',
                'timestamp' => $this->created_at->toIso8601String(),
            ]
        ];
        
        if ($this->reviewed_at) {
            $timeline[] = [
                'event' => $this->status === 'approved' ? 'Approved' : ($this->status === 'rejected' ? 'Rejected' : 'Modification Requested'),
                'status' => $this->status,
                'timestamp' => $this->reviewed_at->toIso8601String(),
                'notes' => $this->rejection_reason ?? $this->admin_notes['modification_notes'] ?? null,
                'reviewed_by' => $this->reviewedBy?->full_name,
            ];
        }
        
        return $timeline;
    }
    
    /**
     * Get queue time (time from submission to review)
     */
    public function getQueueTimeAttribute(): ?int
    {
        if (!$this->reviewed_at) {
            return null;
        }
        
        return $this->created_at->diffInHours($this->reviewed_at);
    }
}
