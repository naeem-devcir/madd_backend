<?php

namespace App\Models\Inventory;

use App\Models\Product\VendorProduct;
use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryLog extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'inventory_logs';

    protected $fillable = [
        'uuid',
        'vendor_product_id',
        'vendor_id',
        'previous_quantity',
        'new_quantity',
        'change',
        'reason',
        'change_type',
        'source_type',
        'source_id',
        'changed_by',
        'metadata',
    ];

    protected $casts = [
        'previous_quantity' => 'integer',
        'new_quantity' => 'integer',
        'change' => 'integer',
        'metadata' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(VendorProduct::class, 'vendor_product_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function source()
    {
        return $this->morphTo();
    }

    public static function log(
        int $vendorProductId,
        int $vendorId,
        int $previousQuantity,
        int $newQuantity,
        ?string $reason = null,
        string $changeType = 'manual',
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $changedBy = null,
        array $metadata = []
    ): self {
        return self::create([
            'vendor_product_id' => $vendorProductId,
            'vendor_id' => $vendorId,
            'previous_quantity' => $previousQuantity,
            'new_quantity' => $newQuantity,
            'change' => $newQuantity - $previousQuantity,
            'reason' => $reason,
            'change_type' => $changeType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'changed_by' => $changedBy,
            'metadata' => $metadata,
        ]);
    }
}
