<?php

namespace App\Models\Models\Config;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    //
}
<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use HasFactory;

    protected $table = 'languages';
    
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'locale',
        'is_rtl',
        'is_active',
    ];

    protected $casts = [
        'is_rtl' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ========== Scopes ==========
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function scopeRtl($query)
    {
        return $query->where('is_rtl', true);
    }
    
    public function scopeLtr($query)
    {
        return $query->where('is_rtl', false);
    }
    
    // ========== Accessors ==========
    
    public function getIsRtlAttribute(): bool
    {
        return (bool) $this->is_rtl;
    }
    
    public function getDirectionAttribute(): string
    {
        return $this->is_rtl ? 'rtl' : 'ltr';
    }
}