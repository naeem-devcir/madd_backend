<?php

namespace App\Services\Payment;

use App\Models\Order\Order;
use stdClass;

class PaymentService
{
    public function createPaymentIntent(Order $order, string $paymentMethod): array
    {
        return [
            'id' => 'stub_intent_' . $order->id,
            'status' => 'not_implemented',
            'client_secret' => null,
            'payment_method' => $paymentMethod,
        ];
    }

    public function confirmPayment(Order $order, string $paymentIntentId, array $payload = []): array
    {
        return [
            'success' => false,
            'message' => 'Payment confirmation is not implemented yet.',
            'transaction_id' => null,
            'payment_intent_id' => $paymentIntentId,
        ];
    }

    public function processRefund($paymentTransaction, float $amount, string $reason): object
    {
        $refund = new stdClass();
        $refund->id = null;
        $refund->refund_amount = $amount;
        $refund->status = 'not_implemented';
        $refund->gateway_refund_id = null;

        return $refund;
    }

    public function getAvailablePaymentMethods(string $countryCode): array
    {
        return [];
    }

    public function savePaymentMethod($user, string $paymentMethodId, bool $setDefault = false): array
    {
        return [
            'payment_method_id' => $paymentMethodId,
            'set_default' => $setDefault,
        ];
    }

    public function getSavedPaymentMethods($user): array
    {
        return [];
    }

    public function deletePaymentMethod($user, string $methodId): bool
    {
        return true;
    }
}
