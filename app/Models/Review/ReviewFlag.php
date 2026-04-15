<?php

namespace App\Models\Review;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReviewFlag extends Model
{
    use HasFactory;

    protected $table = 'review_flags';

    protected $fillable = [
        'review_id',
        'user_id',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    // ========== Relationships ==========

    public function review()
    {
        return $this->belongsTo(Review::class, 'review_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }

    // ========== Scopes ==========

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // ========== Methods ==========

    public function dismiss(User $reviewer): void
    {
        $this->status = 'dismissed';
        $this->reviewed_by = $reviewer->id;
        $this->reviewed_at = now();
        $this->save();
    }

    public function uphold(User $reviewer): void
    {
        $this->status = 'reviewed';
        $this->reviewed_by = $reviewer->id;
        $this->reviewed_at = now();
        $this->save();

        $this->review->status = 'rejected';
        $this->review->save();
    }
}
