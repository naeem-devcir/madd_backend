<?php

namespace App\Models\Review;

use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Models\Product\VendorProduct;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'reviews';

    protected $fillable = [
        'magento_review_id',
        'customer_id',
        'vendor_id',
        'vendor_store_id',
        'vendor_product_id',
        'magento_product_id',
        'rating',
        'title',
        'body',
        'images',
        'language_code',
        'verified_purchased',
        'helpful_count',
        'status',
        'rejected_reason',
        'moderated_by',
        'moderated_at',
        'vendor_response',
        'vendor_response_at',
    ];

    protected $casts = [
        'images' => 'array',
        'rating' => 'integer',
        'verified_purchased' => 'boolean',
        'helpful_count' => 'integer',
        'moderated_at' => 'datetime',
        'vendor_response_at' => 'datetime',
    ];

    // ========== Relationships ==========

    // public function customer()
    // {
    //     return $this->belongsTo(User::class, 'customer_id', 'id');
    // }

    // public function vendor()
    // {
    //     return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    // }

    // public function store()
    // {
    //     return $this->belongsTo(VendorStore::class, 'vendor_store_id', 'id');
    // }

    // public function product()
    // {
    //     return $this->belongsTo(VendorProduct::class, 'vendor_product_id', 'id');
    // }

    // public function moderatedBy()
    // {
    //     return $this->belongsTo(User::class, 'moderated_by', 'id');
    // }


    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id', 'uuid');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }

    public function store()
    {
        return $this->belongsTo(VendorStore::class, 'vendor_store_id', 'uuid');
    }

    public function product()
    {
        return $this->belongsTo(VendorProduct::class, 'vendor_product_id', 'id');
    }

    public function moderatedBy()
    {
        return $this->belongsTo(User::class, 'moderated_by', 'uuid');
    }




    public function helpfulVotes()
    {
        return $this->hasMany(ReviewHelpfulVote::class, 'review_id', 'id');
    }

    public function flags()
    {
        return $this->hasMany(ReviewFlag::class, 'review_id', 'id');
    }

    // ========== Scopes ==========

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeFlagged($query)
    {
        return $query->where('status', 'flagged');
    }

    public function scopeVerifiedPurchase($query)
    {
        return $query->where('verified_purchased', true);
    }

    public function scopeByRating($query, $rating)
    {
        return $query->where('rating', $rating);
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('vendor_product_id', $productId);
    }

    // ========== Accessors ==========

    public function getRatingStarsAttribute(): string
    {
        return str_repeat('★', $this->rating) . str_repeat('☆', 5 - $this->rating);
    }

    public function getIsApprovedAttribute(): bool
    {
        return $this->status === 'approved';
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    public function getIsRejectedAttribute(): bool
    {
        return $this->status === 'rejected';
    }

    public function getIsFlaggedAttribute(): bool
    {
        return $this->status === 'flagged';
    }

    public function getHasVendorResponseAttribute(): bool
    {
        return !is_null($this->vendor_response);
    }

    // ========== Methods ==========

    public function approve(User $moderator): void
    {
        $this->status = 'approved';
        $this->moderated_by = $moderator->uuid;
        $this->moderated_at = now();
        $this->save();

        // Update product rating
        $this->updateProductRating();
    }

    public function reject(User $moderator, string $reason): void
    {
        $this->status = 'rejected';
        $this->rejected_reason = $reason;
        $this->moderated_by = $moderator->uuid;
        $this->moderated_at = now();
        $this->save();
    }

    public function flag(User $user, string $reason): void
    {
        $this->status = 'flagged';
        $this->save();

        ReviewFlag::create([
            'review_id' => $this->id,
            'user_id' => $user->id,
            'reason' => $reason,
            'status' => 'pending',
        ]);
    }

    public function addVendorResponse(string $response, User $vendor): void
    {
        $this->vendor_response = $response;
        $this->vendor_response_at = now();
        $this->save();
    }

    protected function updateProductRating(): void
    {
        if ($this->product) {
            $avgRating = Review::where('vendor_product_id', $this->product->id)
                ->where('status', 'approved')
                ->avg('rating');

            $this->product->update([
                'rating_average' => $avgRating,
                'total_reviews' => Review::where('vendor_product_id', $this->product->id)
                    ->where('status', 'approved')
                    ->count(),
            ]);
        }

        if ($this->vendor) {
            $avgRating = Review::where('vendor_id', $this->vendor->getKey())
                ->where('status', 'approved')
                ->avg('rating');

            $this->vendor->update([
                'rating_average' => $avgRating,
                'total_reviews' => Review::where('vendor_id', $this->vendor->getKey())
                    ->where('status', 'approved')
                    ->count(),
            ]);
        }
    }
}
