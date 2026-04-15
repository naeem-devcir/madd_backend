<?php

namespace App\Models\Notification;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasFactory;

    protected $table = 'notification_templates';

    protected $fillable = [
        'code',
        'name',
        'subject',
        'body',
        'channels',
        'variables',
        'is_active',
    ];

    protected $casts = [
        'subject' => 'array',
        'body' => 'array',
        'channels' => 'array',
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ========== Methods ==========

    public function render(array $data, string $locale = 'en'): array
    {
        $subject = $this->subject[$locale] ?? $this->subject['en'] ?? '';
        $body = $this->body[$locale] ?? $this->body['en'] ?? '';

        foreach ($data as $key => $value) {
            $subject = str_replace('{{'.$key.'}}', $value, $subject);
            $body = str_replace('{{'.$key.'}}', $value, $body);
        }

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }
}
