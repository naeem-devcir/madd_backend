<?php
// app/Models/SocialAccount.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'provider_email',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'access_token' => 'encrypted',   // ✅ Auto-encrypts
        'refresh_token' => 'encrypted',  // ✅ Auto-encrypts
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
