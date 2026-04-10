<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\Payment\ProcessStripePayment;
use App\Jobs\Payment\ProcessStripeRefund;
use App\Models\Payment\StripeWebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    /**
     * Handle Stripe webhook
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (SignatureVerificationException $e) {
            Log::error('Invalid Stripe webhook signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Log webhook for debugging
        StripeWebhookLog::create([
            'event_id' => $event->id,
            'event_type' => $event->type,
            'payload' => $payload,
            'processed' => false,
        ]);

        // Process based on event type
        switch ($event->type) {
            case 'payment_intent.succeeded':
                ProcessStripePayment::dispatch($event->data->object);
                break;

            case 'payment_intent.payment_failed':
                $this->handleFailedPayment($event->data->object);
                break;

            case 'charge.refunded':
                ProcessStripeRefund::dispatch($event->data->object);
                break;

            case 'charge.dispute.created':
                $this->handleDisputeCreated($event->data->object);
                break;

            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($event->data->object);
                break;

            default:
                Log::info('Unhandled Stripe webhook event', ['type' => $event->type]);
        }

        // Mark webhook as processed
        StripeWebhookLog::where('event_id', $event->id)->update(['processed' => true]);

        return response()->json(['success' => true], 200);
    }

    /**
     * Handle failed payment
     */
    private function handleFailedPayment($paymentIntent)
    {
        // Update order payment status
        $orderId = $paymentIntent->metadata->order_id ?? null;

        if ($orderId) {
            $order = Order::find($orderId);
            if ($order) {
                $order->updatePaymentStatus('failed', $paymentIntent->last_payment_error->message ?? 'Payment failed');
            }
        }
    }

    /**
     * Handle dispute created
     */
    private function handleDisputeCreated($dispute)
    {
        $chargeId = $dispute->charge;
        $paymentTransaction = PaymentTransaction::where('gateway_transaction_id', $chargeId)->first();

        if ($paymentTransaction) {
            $paymentTransaction->update([
                'status' => 'disputed',
                'dispute_reason' => $dispute->reason,
            ]);

            $paymentTransaction->order->updatePaymentStatus('chargeback');
        }
    }

    /**
     * Handle subscription updated
     */
    private function handleSubscriptionUpdated($subscription)
    {
        $vendorId = $subscription->metadata->vendor_id ?? null;

        if ($vendorId) {
            $vendor = Vendor::find($vendorId);
            if ($vendor) {
                if ($subscription->status === 'active') {
                    $vendor->activate();
                } elseif ($subscription->status === 'canceled') {
                    $vendor->suspend('Subscription cancelled');
                }
            }
        }
    }
}