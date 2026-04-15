<?php

namespace App\Models\Config;

use App\Models\Traits\HasUuid;
use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Domain extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'domains';

    protected $fillable = [
        'uuid',
        'vendor_store_id',
        'domain',
        'type',
        'dns_verified',
        'dns_verified_at',
        'verification_token',
        'ssl_status',
        'ssl_provider',
        'ssl_issued_at',
        'ssl_expires_at',
        'ssl_auto_renew',
        'expires_at',
        'redirect_type',
        'www_redirect',
        'registrar',
        'is_primary',
    ];

    protected $casts = [
        'dns_verified' => 'boolean',
        'dns_verified_at' => 'datetime',
        'ssl_issued_at' => 'datetime',
        'ssl_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'ssl_auto_renew' => 'boolean',
        'www_redirect' => 'boolean',
        'is_primary' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(VendorStore::class, 'vendor_store_id');
    }
}
