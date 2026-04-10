<?php

namespace App\Models\Analytics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SearchLog extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
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

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    // 🔗 Relation (optional)
    public function orderItem()
    {
        return $this->belongsTo(\App\Models\Order\OrderItem::class, 'clicked_product');
    }
}