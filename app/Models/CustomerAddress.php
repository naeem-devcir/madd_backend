<?php

namespace App\Models;

use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerAddress extends Model
{
    use HasFactory;

    protected $table = 'customer_addresses';

    protected $fillable = [
        'uuid',
        'customer_id',
        'vendor_id',
        'magento_id',
        'magento_customer_id',
        'firstname',
        'lastname',
        'middlename',
        'prefix',
        'suffix',
        'company',
        'street',
        'city',
        'region',
        'region_id',
        'postcode',
        'country_id',
        'telephone',
        'fax',
        'vat_id',
        'is_default_billing',
        'is_default_shipping',
        'is_active',
        'custom_attributes',
        'magento_data',
        'last_synced_at'
    ];

    protected $casts = [
        'is_default_billing' => 'boolean',
        'is_default_shipping' => 'boolean',
        'is_active' => 'boolean',
        'custom_attributes' => 'array',
        'magento_data' => 'array',
        'last_synced_at' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->street,
            $this->city,
            $this->region,
            $this->postcode,
            $this->country_id
        ]);
        return implode(', ', $parts);
    }
}