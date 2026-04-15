<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    protected $table = 'currencies';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'exchange_rate',
        'decimal_places',
        'is_active',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:4',
        'decimal_places' => 'integer',
        'is_active' => 'boolean',
    ];

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ========== Accessors ==========

    public function getFormattedExchangeRateAttribute(): string
    {
        return '1 '.$this->code.' = '.$this->exchange_rate.' EUR';
    }

    // ========== Methods ==========

    public function format(float $amount): string
    {
        return $this->symbol.' '.number_format($amount, $this->decimal_places);
    }

    public function convertTo(float $amount, Currency $targetCurrency): float
    {
        $amountInBase = $amount / $this->exchange_rate;

        return $amountInBase * $targetCurrency->exchange_rate;
    }
}
