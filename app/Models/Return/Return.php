<?php

namespace App\Models\Return;

use App\Models\Config\Courier;
use App\Models\Order\Order;
use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnModel extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'returns';

    protected $fillable = [
        'uuid',
        'rma_number',
        'order_id',
        'customer_id',
        'vendor_id',
        'status',
        'reason',
        'notes',
        'refund_amount',
        'courier_id',
        'tracking_number',
        'return_label_url',
        'received_at',
        'refunded_at',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:4',
        'received_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function courier()
    {
        return $this->belongsTo(Courier::class, 'courier_id');
    }

    public function items()
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }
}
