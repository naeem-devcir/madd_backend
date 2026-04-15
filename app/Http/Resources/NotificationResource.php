<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the notification resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'channel' => $this->channel,
            'title' => $this->getTitleTextAttribute(),
            'body' => $this->getBodyTextAttribute(),
            'data' => $this->data,
            'priority' => $this->priority,
            'action_url' => $this->action_url,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Formatted fields
            'time_ago' => $this->created_at?->diffForHumans(),
            'is_recent' => $this->created_at && $this->created_at->diffInDays(now()) <= 7,
        ];
    }
}
