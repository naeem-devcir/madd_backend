<?php

namespace App\Models\Customer;

use App\Models\Product\VendorProduct;
use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'customer_wishlists';

    protected $fillable = [
        'uuid',
        'customer_id',
        'product_id',
        'store_id',
    ];

    public function product()
    {
        return $this->belongsTo(VendorProduct::class, 'product_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function store()
    {
        return $this->belongsTo(VendorStore::class, 'store_id');
    }
}
