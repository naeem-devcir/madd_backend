<?php

namespace App\Services\Mlm;

class MlmCommissionService
{
    public function createCommission(array $data): array
    {
        return $data + ['created' => true];
    }
}
