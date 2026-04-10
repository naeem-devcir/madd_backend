<?php

namespace App\Models\Config;

use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Courier extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'couriers';

    protected $fillable = [
        'name',
        'code',
        'api_type',
        'credentials',
        'countries',
        'service_levels',
        'tracking_url_template',
        'logo_url',
        'support_contact',
        'settlement_contact',
        'weight_limit_kg',
        'insurance_options',
        'data_processing_agreement',
        'contract_reference',
        'settlement_due_day',
        'is_active',
    ];

    protected $casts = [
        'credentials' => 'array',
        'countries' => 'array',
        'service_levels' => 'array',
        'support_contact' => 'array',
        'settlement_contact' => 'array',
        'insurance_options' => 'array',
        'weight_limit_kg' => 'decimal:2',
        'data_processing_agreement' => 'boolean',
        'is_active' => 'boolean',
        'settlement_due_day' => 'integer',
    ];

    // ========== Relationships ==========
    
    public function orders()
    {
        return $this->hasMany(Order::class, 'carrier_id', 'uuid');
    }
    
    // ========== Scopes ==========
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function scopeByCountry($query, $countryCode)
    {
        return $query->whereJsonContains('countries', $countryCode);
    }
    
    public function scopeByApiType($query, $apiType)
    {
        return $query->where('api_type', $apiType);
    }
    
    // ========== Accessors ==========
    
    public function getTrackingUrl(string $trackingNumber): string
    {
        if ($this->tracking_url_template) {
            return str_replace('{tracking_number}', $trackingNumber, $this->tracking_url_template);
        }
        
        return '#';
    }
    
    public function getHasDpaAttribute(): bool
    {
        return (bool) $this->data_processing_agreement;
    }
    
    // ========== Methods ==========
    
    public function supportsCountry(string $countryCode): bool
    {
        return in_array($countryCode, $this->countries ?? []);
    }
    
    public function getServiceLevel(string $level): ?array
    {
        return $this->service_levels[$level] ?? null;
    }
}
