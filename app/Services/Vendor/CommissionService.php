<?php

namespace App\Services\Vendor;

use App\Models\Order\Order;

class CommissionService
{
    public function calculateOrderCommission(Order $order): float
    {
        $rate = $order->vendor?->effective_commission_rate ?? 0;

        return round(((float) $order->grand_total) * ($rate / 100), 2);
    }

    public function storeCommission(Order $order, float $amount): array
    {
        return [
            'order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'amount' => $amount,
            'status' => 'calculated',
        ];
    }
}
