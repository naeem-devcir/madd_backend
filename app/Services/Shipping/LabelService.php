<?php

namespace App\Services\Shipping;

class LabelService
{
    public function createShippingLabel(...$arguments): array
    {
        return [
            'label_url' => null,
            'tracking_number' => null,
        ];
    }
}
