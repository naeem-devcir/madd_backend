<?php

namespace App\Models;

use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'customers';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'magento_id',
        'magento_store_id',
        'magento_website_id',
        'email',
        'firstname',
        'lastname',
        'middlename',
        'prefix',
        'suffix',
        'is_active',
        'is_confirmed',
        'is_subscribed',
        'phone',
        'mobile',
        'fax',
        'dob',
        'gender',
        'taxvat',
        'group_id',
        'default_billing',
        'default_shipping',
        'password_hash',
        'addresses',
        'custom_attributes',
        'magento_data',
        'last_synced_at',
        'magento_updated_at',
        'last_login_at',
        'email_verified_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_confirmed' => 'boolean',
        'is_subscribed' => 'boolean',
        'addresses' => 'array',
        'custom_attributes' => 'array',
        'magento_data' => 'array',
        'dob' => 'date',
        'last_synced_at' => 'datetime',
        'magento_updated_at' => 'datetime',
        'last_login_at' => 'datetime',
        'email_verified_at' => 'datetime'
    ];

    protected $hidden = ['password_hash'];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->firstname} {$this->lastname}");
    }

    public function getFullNameWithPrefixAttribute(): string
    {
        $parts = array_filter([$this->prefix, $this->firstname, $this->middlename, $this->lastname, $this->suffix]);
        return implode(' ', $parts);
    }
}