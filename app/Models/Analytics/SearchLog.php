<?php

namespace App\Models\Analytics;

use App\Models\Product\VendorProduct;
use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'store_id',
        'user_id',
        'query',
        'results_count',
        'filters_applied',
        'session_id',
        'ip_address',
        'user_agent',
        'clicked_product',
        'clicked_at',
        'response_time_ms',
        'is_successful',
    ];

    protected $casts = [
        'filters_applied' => 'array',
        'clicked_at' => 'datetime',
        'is_successful' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(VendorStore::class, 'store_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function clickedProduct()
    {
        return $this->belongsTo(VendorProduct::class, 'clicked_product');
    }
}
