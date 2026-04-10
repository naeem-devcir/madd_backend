<?php

namespace App\Models\Notification;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'notifications';
    
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'channel',
        'title',
        'body',
        'data',
        'priority',
        'action_url',
        'read_at',
        'sent_at',
    ];

    protected $casts = [
        'title' => 'array',
        'body' => 'array',
        'data' => 'array',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    // ========== Relationships ==========
    
    public function notifiable()
    {
        return $this->morphTo();
    }
    
    // ========== Scopes ==========
    
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
    
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }
    
    public function scopeByChannel($query, $channel)
    {
        return $query->where('channel', $channel);
    }
    
    public function scopeHighPriority($query)
    {
        return $query->where('priority', 'high');
    }
    
    // ========== Accessors ==========
    
    public function getIsReadAttribute(): bool
    {
        return !is_null($this->read_at);
    }
    
    public function getTitleTextAttribute(): string
    {
        $locale = app()->getLocale();
        return $this->title[$locale] ?? $this->title['en'] ?? '';
    }
    
    public function getBodyTextAttribute(): string
    {
        $locale = app()->getLocale();
        return $this->body[$locale] ?? $this->body['en'] ?? '';
    }
    
    // ========== Methods ==========
    
    public function markAsRead(): void
    {
        if (!$this->is_read) {
            $this->read_at = now();
            $this->save();
        }
    }
    
    public function markAsSent(): void
    {
        $this->sent_at = now();
        $this->save();
    }
}