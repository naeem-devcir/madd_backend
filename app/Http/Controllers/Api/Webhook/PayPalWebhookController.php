<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\Payment\ProcessPayPalPayment;
use App\Jobs\Payment\ProcessPayPalRefund;
use App\Models\Payment\PayPalWebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    /**
     * Handle PayPal webhook
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $headers = $request->headers->all();

        // Verify webhook signature
        if (!$this->verifySignature($request)) {
            Log::warning('Invalid PayPal webhook signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $event = json_decode($payload, true);
        $eventType = $event['event_type'] ?? null;

        // Log webhook
        PayPalWebhookLog::create([
            'event_id' => $event['id'] ?? null,
            'event_type' => $eventType,
            'payload' => $payload,
            'processed' => false,
        ]);

        // Process based on event type
        switch ($eventType) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                ProcessPayPalPayment::dispatch($event['resource']);
                break;

            case 'PAYMENT.CAPTURE.REFUNDED':
                ProcessPayPalRefund::dispatch($event['resource']);
                break;

            case 'PAYMENT.CAPTURE.DENIED':
                $this->handlePaymentDenied($event['resource']);
                break;

            case 'CUSTOMER.DISPUTE.CREATED':
                $this->handleDisputeCreated($event['resource']);
                break;

            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                $this->handleSubscriptionActivated($event['resource']);
                break;

            case 'BILLING.SUBSCRIPTION.CANCELLED':
                $this->handleSubscriptionCancelled($event['resource']);
                break;

            default:
                Log::info('Unhandled PayPal webhook event', ['type' => $eventType]);
        }

        // Mark as processed
        PayPalWebhookLog::where('event_id', $event['id'] ?? null)->update(['processed' => true]);

        return response()->json(['success' => true], 200);
    }

    /**
     * Verify PayPal webhook signature
     */
    private function verifySignature(Request $request): bool
    {
        // PayPal signature verification logic
        // This requires calling PayPal API to verify the webhook signature
        // Simplified for now
        return true;
    }

    /**
     * Handle payment denied
     */
    private function handlePaymentDenied($resource)
    {
        $orderId = $resource['custom_id'] ?? null;

        if ($orderId) {
            $order = Order::find($orderId);
            if ($order) {
                $order->updatePaymentStatus('failed', 'Payment denied by PayPal');
            }
        }
    }

    /**
     * Handle dispute created
     */
    private function handleDisputeCreated($dispute)
    {
        $transactionId = $dispute['disputed_transactions'][0]['seller_transaction_id'] ?? null;

        if ($transactionId) {
            $paymentTransaction = PaymentTransaction::where('gateway_transaction_id', $transactionId)->first();

            if ($paymentTransaction) {
                $paymentTransaction->update([
                    'status' => 'disputed',
                    'dispute_reason' => $dispute['reason'],
                ]);

                $paymentTransaction->order->updatePaymentStatus('chargeback');
            }
        }
    }

    /**
     * Handle subscription activated
     */
    private function handleSubscriptionActivated($subscription)
    {
        $vendorId = $subscription['custom_id'] ?? null;

        if ($vendorId) {
            $vendor = Vendor::find($vendorId);
            if ($vendor) {
                $vendor->activate();
            }
        }
    }

    /**
     * Handle subscription cancelled
     */
    private function handleSubscriptionCancelled($subscription)
    {
        $vendorId = $subscription['custom_id'] ?? null;

        if ($vendorId) {
            $vendor = Vendor::find($vendorId);
            if ($vendor) {
                $vendor->suspend('PayPal subscription cancelled');
            }
        }
    }
}