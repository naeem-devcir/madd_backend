<?php

namespace App\Models\Financial;

use App\Models\Order\Order;
use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'transactions';

    protected $fillable = [
        'uuid',
        'settlement_id',
        'order_id',
        'vendor_id',
        'initiated_by',
        'payable_type',
        'payable_id',
        'type',
        'status',
        'amount',
        'gateway_fee',
        'currency_code',
        'balance_after',
        'gateway',
        'gateway_transaction_id',
        'reference',
        'failure_reason',
        'description',
        'metadata',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'gateway_fee' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    public $timestamps = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function payable()
    {
        return $this->morphTo();
    }
}
