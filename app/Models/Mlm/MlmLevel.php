<?php

namespace App\Models\Mlm;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MlmLevel extends Model
{
    use HasFactory;

    protected $table = 'mlm_levels';

    protected $fillable = [
        'level',
        'commission_percentage',
        'required_downline',
        'required_volume',
        'bonus_amount',
        'name',
        'description',
        'benefits',
        'is_active',
    ];

    protected $casts = [
        'level' => 'integer',
        'commission_percentage' => 'decimal:2',
        'required_downline' => 'integer',
        'required_volume' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'benefits' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get agents at this level
     */
    public function agents(): HasMany
    {
        return $this->hasMany(MlmAgent::class, 'level', 'level');
    }

    /**
     * Get next level
     */
    public function nextLevel(): ?MlmLevel
    {
        return self::where('level', $this->level + 1)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get previous level
     */
    public function previousLevel(): ?MlmLevel
    {
        return self::where('level', $this->level - 1)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Check if agent qualifies for this level
     */
    public function qualifies(int $downlineCount, float $totalVolume): bool
    {
        return $downlineCount >= $this->required_downline 
            && $totalVolume >= $this->required_volume;
    }

    /**
     * Get active levels ordered by level number
     */
    public static function getActiveLevels()
    {
        return self::where('is_active', true)
            ->orderBy('level')
            ->get();
    }

    /**
     * Get default levels (if table is empty)
     */
    public static function getDefaultLevels(): array
    {
        return [
            [
                'level' => 1,
                'commission_percentage' => 10.00,
                'required_downline' => 0,
                'required_volume' => 0,
                'bonus_amount' => 0,
                'name' => 'Starter',
                'description' => 'Entry level agent',
                'is_active' => true,
            ],
            [
                'level' => 2,
                'commission_percentage' => 8.00,
                'required_downline' => 5,
                'required_volume' => 1000,
                'bonus_amount' => 50,
                'name' => 'Bronze',
                'description' => 'Bronze level agent',
                'is_active' => true,
            ],
            [
                'level' => 3,
                'commission_percentage' => 6.00,
                'required_downline' => 10,
                'required_volume' => 2500,
                'bonus_amount' => 100,
                'name' => 'Silver',
                'description' => 'Silver level agent',
                'is_active' => true,
            ],
            [
                'level' => 4,
                'commission_percentage' => 5.00,
                'required_downline' => 20,
                'required_volume' => 5000,
                'bonus_amount' => 200,
                'name' => 'Gold',
                'description' => 'Gold level agent',
                'is_active' => true,
            ],
            [
                'level' => 5,
                'commission_percentage' => 4.00,
                'required_downline' => 35,
                'required_volume' => 10000,
                'bonus_amount' => 350,
                'name' => 'Platinum',
                'description' => 'Platinum level agent',
                'is_active' => true,
            ],
            [
                'level' => 6,
                'commission_percentage' => 3.00,
                'required_downline' => 50,
                'required_volume' => 20000,
                'bonus_amount' => 500,
                'name' => 'Diamond',
                'description' => 'Diamond level agent',
                'is_active' => true,
            ],
        ];
    }

    /**
     * Seed default levels if none exist
     */
    public static function seedDefaultLevels(): void
    {
        if (self::count() === 0) {
            foreach (self::getDefaultLevels() as $levelData) {
                self::create($levelData);
            }
        }
    }
}