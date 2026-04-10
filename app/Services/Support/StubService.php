<?php

namespace App\Services\Support;

class StubService
{
    public function __call(string $name, array $arguments): mixed
    {
        if (str_starts_with($name, 'get') || str_starts_with($name, 'list') || str_starts_with($name, 'find')) {
            return [];
        }

        if (str_starts_with($name, 'calculate')) {
            return 0;
        }

        if (str_starts_with($name, 'is') || str_starts_with($name, 'has') || str_starts_with($name, 'can') || str_starts_with($name, 'verify')) {
            return false;
        }

        return null;
    }
}
