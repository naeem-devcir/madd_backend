<?php

namespace App\Models\Product;

use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductSharing extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'product_sharing';

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

    public function sourceProduct()
    {
        return $this->belongsTo(VendorProduct::class, 'source_product_id');
    }

    public function targetStore()
    {
        return $this->belongsTo(VendorStore::class, 'target_store_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approve(User $admin): void
    {
        $this->update([
            'status' => 'active',
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
    }
}
