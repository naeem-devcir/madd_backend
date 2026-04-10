<?php

namespace App\Models\Config;

use App\Models\Financial\Settlement;
use App\Models\Financial\Invoice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaddCompany extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'madd_companies';

    protected $fillable = [
        'name',
        'country_code',
        'vat_number',
        'registration_number',
        'legal_representative',
        'contact_email',
        'contact_phone',
        'tax_office',
        'address',
        'bank_details',
        'logo_url',
        'invoice_prefix',
        'fiscal_year_start',
        'is_active',
    ];

    protected $casts = [
        'address' => 'array',
        'bank_details' => 'array',
        'fiscal_year_start' => 'date',
        'is_active' => 'boolean',
    ];

    // ========== Relationships ==========
    
    public function country()
    {
        return $this->belongsTo(CountryConfig::class, 'country_code', 'code');
    }
    
    public function settlements()
    {
        return $this->hasMany(Settlement::class, 'madd_company_id', 'id');
    }
    
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'madd_company_id', 'id');
    }
    
    // ========== Scopes ==========
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    // ========== Accessors ==========
    
    public function getFullAddressAttribute(): string
    {
        $address = $this->address;
        $parts = [];
        
        if (!empty($address['street'])) {
            $parts[] = $address['street'];
        }
        if (!empty($address['city'])) {
            $parts[] = $address['city'];
        }
        if (!empty($address['postal_code'])) {
            $parts[] = $address['postal_code'];
        }
        if (!empty($address['country'])) {
            $parts[] = $address['country'];
        }
        
        return implode(', ', $parts);
    }
    
    // ========== Methods ==========
    
    public function generateInvoiceNumber(): string
    {
        $prefix = $this->invoice_prefix . date('Ymd');
        $last = Invoice::where('invoice_number', 'like', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();
            
        $number = $last ? intval(substr($last->invoice_number, -4)) + 1 : 1;
        
        return $prefix . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}