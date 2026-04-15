<?php

namespace App\Models\Financial;

use App\Models\Config\MaddCompany;
use App\Models\Order\Order;
use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Settlement extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'settlements';

    protected $fillable = [
        'uuid',
        'payable_type',
        'payable_id',
        'vendor_id',
        'madd_company_id',
        'period_start',
        'period_end',
        'period_days',
        'gross_sales',
        'total_refunds',
        'total_commissions',
        'total_shipping_fees',
        'total_tax_collected',
        'adjustment_amount',
        'gateway_fees',
        'net_payout',
        'currency_code',
        'exchange_rate',
        'status',
        'payment_method',
        'payment_reference',
        'statement_pdf_path',
        'approved_by',
        'approved_at',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'gross_sales' => 'decimal:4',
        'total_refunds' => 'decimal:4',
        'total_commissions' => 'decimal:4',
        'total_shipping_fees' => 'decimal:4',
        'total_tax_collected' => 'decimal:4',
        'adjustment_amount' => 'decimal:4',
        'gateway_fees' => 'decimal:4',
        'net_payout' => 'decimal:4',
        'exchange_rate' => 'decimal:4',
        'period_days' => 'integer',
    ];

    public function payable()
    {
        return $this->morphTo();
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function maddCompany()
    {
        return $this->belongsTo(MaddCompany::class, 'madd_company_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'settlement_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'settlement_id');
    }
}
