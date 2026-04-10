<?php

namespace App\Models\Mlm;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MlmAgent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mlm_agents';

    protected $fillable = [
        'user_id',
        'parent_id',
        'level',
        'territory_type',
        'territory_code',
        'commission_rate',
        'total_vendors_recruited',
        'total_commissions_earned',
        'rank',
        'phone',
        'kyc_status',
        'status',
    ];

    protected $casts = [
        'level' => 'integer',
        'commission_rate' => 'decimal:2',
        'total_vendors_recruited' => 'integer',
        'total_commissions_earned' => 'decimal:4',
    ];

    // ========== Relationships ==========
    
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }
    
    public function parent()
    {
        return $this->belongsTo(MlmAgent::class, 'parent_id', 'id');
    }
    
    public function children()
    {
        return $this->hasMany(MlmAgent::class, 'parent_id', 'id');
    }
    
    public function commissions()
    {
        return $this->hasMany(MlmCommission::class, 'agent_id', 'uuid');
    }
    
    // ========== Scopes ==========
    
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
    
    public function scopeKycVerified($query)
    {
        return $query->where('kyc_status', 'verified');
    }
    
    public function scopeByTerritory($query, $type, $code)
    {
        return $query->where('territory_type', $type)->where('territory_code', $code);
    }
    
    // ========== Accessors ==========
    
    public function getFullNameAttribute(): string
    {
        return $this->user->full_name;
    }
    
    public function getEmailAttribute(): string
    {
        return $this->user->email;
    }
    
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }
    
    public function getIsKycVerifiedAttribute(): bool
    {
        return $this->kyc_status === 'verified';
    }
    
    // ========== Methods ==========
    
    public function getDownlineCount(int $maxLevel = null): int
    {
        $query = $this->children();
        
        if ($maxLevel) {
            // Recursive query would be needed for multi-level
            // Simplified for now
            return $this->children()->count();
        }
        
        return $this->children()->count();
    }
    
    public function getTotalCommissions(float $startDate = null, float $endDate = null): float
    {
        $query = $this->commissions()->where('status', 'paid');
        
        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }
        
        return $query->sum('amount');
    }
    
    public function calculateCommission(float $amount): float
    {
        return $amount * ($this->commission_rate / 100);
    }
}
