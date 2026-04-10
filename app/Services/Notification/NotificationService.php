<?php

namespace App\Services\Notification;

use App\Services\Support\StubService;

class NotificationService extends StubService
{
    public function send(array $payload = []): bool
    {
        return true;
    }
}
