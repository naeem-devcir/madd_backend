<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CountryConfig extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'country_configs';

    protected $fillable = [
        'code',
        'name',
        'phone_code',
        'eu_member',
        'currency_code',
        'currency_symbol',
        'tax_rate',
        'timezone',
        'language_codes',
        'madd_company_id',
        'is_active',
    ];

    protected $casts = [
        'eu_member' => 'boolean',
        'is_active' => 'boolean',
        'tax_rate' => 'decimal:2',
        'language_codes' => 'array',
    ];

    // ========== Relationships ==========

    public function maddCompany()
    {
        return $this->belongsTo(MaddCompany::class, 'madd_company_id', 'id');
    }

    public function salesPolicies()
    {
        return $this->hasMany(SalesPolicy::class, 'country_code', 'code');
    }

    public function vendors()
    {
        return $this->hasMany(Vendor::class, 'country_code', 'code');
    }

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEuMembers($query)
    {
        return $query->where('eu_member', true);
    }

    // ========== Accessors ==========

    public function getIsEuMemberAttribute(): bool
    {
        return (bool) $this->eu_member;
    }

    public function getFormattedTaxRateAttribute(): string
    {
        return $this->tax_rate.'%';
    }

    // ========== Methods ==========

    public function getDefaultLanguage(): string
    {
        return $this->language_codes[0] ?? 'en';
    }
}
