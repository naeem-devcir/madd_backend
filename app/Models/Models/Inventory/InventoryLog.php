<?php

namespace App\Models\Inventory;

use App\Models\Product\VendorProduct;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class InventoryLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'inventory_logs';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
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

    // ========== Relationships ==========

    public function product()
    {
        return $this->belongsTo(VendorProduct::class, 'vendor_product_id', 'id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by', 'id');
    }

    public function source()
    {
        return $this->morphTo();
    }

    // ========== Scopes ==========

    public function scopeForProduct($query, $productId)
    {
        return $query->where('vendor_product_id', $productId);
    }

    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('change_type', $type);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // ========== Accessors ==========

    public function getChangeDirectionAttribute(): string
    {
        if ($this->change > 0) {
            return 'increase';
        } elseif ($this->change < 0) {
            return 'decrease';
        }

        return 'no_change';
    }

    public function getChangeSymbolAttribute(): string
    {
        if ($this->change > 0) {
            return '+'.$this->change;
        }

        return (string) $this->change;
    }

    public function getChangeTypeLabelAttribute(): string
    {
        $labels = [
            'manual' => 'Manual Update',
            'order' => 'Order Placed',
            'order_cancelled' => 'Order Cancelled',
            'return' => 'Product Returned',
            'restock' => 'Restock',
            'adjustment' => 'Inventory Adjustment',
            'sync' => 'Sync from Magento',
        ];

        return $labels[$this->change_type] ?? ucfirst($this->change_type);
    }

    // ========== Methods ==========

    public static function log(
        $vendorProductId,
        $vendorId,
        $previousQuantity,
        $newQuantity,
        $reason = null,
        $changeType = 'manual',
        $sourceType = null,
        $sourceId = null,
        $changedBy = null,
        $metadata = []
    ): self {
        $change = $newQuantity - $previousQuantity;

        return self::create([
            'id' => (string) Str::uuid(),
            'vendor_product_id' => $vendorProductId,
            'vendor_id' => $vendorId,
            'previous_quantity' => $previousQuantity,
            'new_quantity' => $newQuantity,
            'change' => $change,
            'reason' => $reason,
            'change_type' => $changeType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'changed_by' => $changedBy,
            'metadata' => $metadata,
        ]);
    }

    public function getFormattedChangeAttribute(): string
    {
        $direction = $this->change > 0 ? '+' : '';

        return $direction.number_format($this->change);
    }
}
