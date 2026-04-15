<?php

namespace App\Models\Product;

use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductApproval extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'product_approvals';

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
        'reviewed_at' => 'datetime',
    ];

    public function productDraft()
    {
        return $this->belongsTo(ProductDraft::class, 'product_draft_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
