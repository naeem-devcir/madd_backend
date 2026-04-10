<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'token_jti',
        'refresh_token',
        'refresh_token_jti',
        'device_name',
        'device_type',
        'ip_address',
        'user_agent',
        'last_used_at',
        'expires_at',
        'is_revoked',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_revoked' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }
}
