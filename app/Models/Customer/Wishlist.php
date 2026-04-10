<?php

namespace App\Models\Customer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Wishlist extends Model
{
    use HasFactory;

    protected $table = 'customer_wishlists';

    // ✅ UUID as primary key
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'customer_id',
        'product_id',
        'store_id',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    // Relations
    public function product()
    {
        return $this->belongsTo(
            \App\Models\Product\VendorProduct::class,
            'product_id',
            'id' // ya 'uuid' depending on your DB
        );
    }

    public function customer()
    {
        return $this->belongsTo(
            \App\Models\User::class,
            'customer_id',
            'id' // ya 'uuid'
        );
    }

    public function store()
    {
        return $this->belongsTo(
            \App\Models\Vendor\VendorStore::class,
            'store_id',
            'id' // ya 'uuid'
        );
    }
}