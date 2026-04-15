<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Jobs\Notification\SendVendorPaymentFailedNotification;
use App\Jobs\Notification\SendVendorPaymentOverdueNotification;
use App\Models\Financial\PaymentTransaction;
use App\Models\Financial\PlatformFee;
use App\Models\Financial\Refund;
use App\Models\Order\Order;
use App\Models\Vendor\Vendor;
use App\Services\Payment\PayPalService;
use App\Services\Payment\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class WebhookController extends Controller
{
    protected $stripeService;

    protected $payPalService;

    public function __construct(StripeService $stripeService, PayPalService $payPalService)
    {
        $this->stripeService = $stripeService;
        $this->payPalService = $payPalService;
    }

    /**
     * Handle Stripe webhook
     */
    public function handleStripe(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid Stripe webhook payload', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Invalid Stripe webhook signature', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        Log::info('Stripe webhook received', ['type' => $event->type, 'id' => $event->id]);

        // Process based on event type
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($event->data->object);
                break;

            case 'payment_intent.payment_failed':
                $this->handlePaymentIntentFailed($event->data->object);
                break;

            case 'charge.refunded':
                $this->handleChargeRefunded($event->data->object);
                break;

            case 'charge.dispute.created':
                $this->handleDisputeCreated($event->data->object);
                break;

            case 'charge.dispute.updated':
                $this->handleDisputeUpdated($event->data->object);
                break;

            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($event->data->object);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event->data->object);
                break;

            case 'invoice.payment_succeeded':
                $this->handleInvoicePaymentSucceeded($event->data->object);
                break;

            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($event->data->object);
                break;

            default:
                Log::info('Unhandled Stripe webhook event', ['type' => $event->type]);
        }

        return response()->json(['success' => true], 200);
    }

    /**
     * Handle PayPal webhook
     */
    public function handlePayPal(Request $request)
    {
        $payload = $request->getContent();
        $headers = $request->headers->all();

        // Verify webhook signature (implement PayPal signature verification)
        // $this->verifyPayPalSignature($request);

        $event = json_decode($payload, true);
        $eventType = $event['event_type'] ?? null;

        Log::info('PayPal webhook received', ['type' => $eventType, 'id' => $event['id'] ?? null]);

        switch ($eventType) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                $this->handlePayPalPaymentCompleted($event['resource']);
                break;

            case 'PAYMENT.CAPTURE.DENIED':
                $this->handlePayPalPaymentDenied($event['resource']);
                break;

            case 'PAYMENT.CAPTURE.REFUNDED':
                $this->handlePayPalRefundCompleted($event['resource']);
                break;

            case 'CHECKOUT.ORDER.APPROVED':
                $this->handlePayPalOrderApproved($event['resource']);
                break;

            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                $this->handlePayPalSubscriptionActivated($event['resource']);
                break;

            case 'BILLING.SUBSCRIPTION.CANCELLED':
                $this->handlePayPalSubscriptionCancelled($event['resource']);
                break;

            case 'CUSTOMER.DISPUTE.CREATED':
                $this->handlePayPalDisputeCreated($event['resource']);
                break;

            default:
                Log::info('Unhandled PayPal webhook event', ['type' => $eventType]);
        }

        return response()->json(['success' => true], 200);
    }

    /**
     * Handle payment intent succeeded
     */
    private function handlePaymentIntentSucceeded($paymentIntent)
    {
        $orderId = $paymentIntent->metadata->order_id ?? null;

        if (! $orderId) {
            Log::warning('No order_id in payment intent metadata', ['payment_intent_id' => $paymentIntent->id]);

            return;
        }

        DB::beginTransaction();

        try {
            $order = Order::find($orderId);

            if (! $order) {
                Log::error('Order not found for payment intent', ['order_id' => $orderId]);

                return;
            }

            // Create payment transaction record
            $transaction = PaymentTransaction::create([
                'order_id' => $order->id,
                'gateway' => 'stripe',
                'gateway_transaction_id' => $paymentIntent->id,
                'transaction_type' => 'capture',
                'amount' => $paymentIntent->amount / 100,
                'currency' => strtoupper($paymentIntent->currency),
                'status' => 'captured',
                'payment_method_details' => json_encode($paymentIntent->payment_method_types),
                'captured_at' => now(),
            ]);

            // Update order payment status
            $order->updatePaymentStatus('paid');

            // Calculate commission
            $order->calculateCommission();

            DB::commit();

            Log::info('Payment processed successfully', ['order_id' => $order->id, 'transaction_id' => $transaction->id]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process payment intent', ['error' => $e->getMessage(), 'payment_intent_id' => $paymentIntent->id]);
        }
    }

    /**
     * Handle payment intent failed
     */
    private function handlePaymentIntentFailed($paymentIntent)
    {
        $orderId = $paymentIntent->metadata->order_id ?? null;

        if (! $orderId) {
            return;
        }

        DB::beginTransaction();

        try {
            $order = Order::find($orderId);

            if ($order) {
                // Create failed transaction record
                PaymentTransaction::create([
                    'order_id' => $order->id,
                    'gateway' => 'stripe',
                    'gateway_transaction_id' => $paymentIntent->id,
                    'transaction_type' => 'sale',
                    'amount' => $paymentIntent->amount / 100,
                    'currency' => strtoupper($paymentIntent->currency),
                    'status' => 'failed',
                    'error_message' => $paymentIntent->last_payment_error->message ?? 'Payment failed',
                ]);

                // Update order payment status
                $order->updatePaymentStatus('failed', $paymentIntent->last_payment_error->message ?? 'Payment failed');
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process failed payment', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle charge refunded
     */
    private function handleChargeRefunded($charge)
    {
        $paymentIntentId = $charge->payment_intent;

        DB::beginTransaction();

        try {
            $paymentTransaction = PaymentTransaction::where('gateway_transaction_id', $paymentIntentId)->first();

            if (! $paymentTransaction) {
                Log::warning('Payment transaction not found for refund', ['payment_intent_id' => $paymentIntentId]);

                return;
            }

            // Create refund record
            $refund = Refund::create([
                'order_id' => $paymentTransaction->order_id,
                'payment_transaction_id' => $paymentTransaction->id,
                'refund_amount' => $charge->amount_refunded / 100,
                'reason' => $charge->refund_reason ?? 'Customer request',
                'status' => 'processed',
                'gateway_refund_id' => $charge->refunds->data[0]->id ?? null,
                'processed_at' => now(),
            ]);

            // Update payment transaction status
            $paymentTransaction->status = 'refunded';
            $paymentTransaction->refunded_at = now();
            $paymentTransaction->save();

            // Update order payment status
            $order = $paymentTransaction->order;

            if ($charge->amount_refunded >= $charge->amount) {
                $order->updatePaymentStatus('refunded', 'Full refund processed');
                $order->updateStatus('refunded', 'Order fully refunded');
            } else {
                $order->updatePaymentStatus('partially_refunded', 'Partial refund processed');
            }

            DB::commit();

            Log::info('Refund processed successfully', ['order_id' => $order->id, 'refund_id' => $refund->id]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process refund', ['error' => $e->getMessage()]);
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
                'metadata' => array_merge($paymentTransaction->metadata ?? [], [
                    'dispute_reason' => $dispute->reason,
                    'dispute_status' => $dispute->status,
                ]),
            ]);

            $paymentTransaction->order->updatePaymentStatus('disputed', 'Payment disputed by customer');
        }
    }

    /**
     * Handle dispute updated
     */
    private function handleDisputeUpdated($dispute)
    {
        $chargeId = $dispute->charge;

        $paymentTransaction = PaymentTransaction::where('gateway_transaction_id', $chargeId)->first();

        if ($paymentTransaction) {
            $metadata = $paymentTransaction->metadata ?? [];
            $metadata['dispute_status'] = $dispute->status;

            $paymentTransaction->update(['metadata' => $metadata]);

            if ($dispute->status === 'won') {
                $paymentTransaction->order->updatePaymentStatus('paid', 'Dispute resolved in merchant favor');
            } elseif ($dispute->status === 'lost') {
                $paymentTransaction->order->updatePaymentStatus('chargeback_lost', 'Dispute lost');
            }
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

            if ($vendor && $subscription->status === 'active') {
                $vendor->activate();
            } elseif ($vendor && $subscription->status === 'past_due') {
                // Send notification about past due payment
                SendVendorPaymentOverdueNotification::dispatch($vendor);
            }
        }
    }

    /**
     * Handle subscription deleted
     */
    private function handleSubscriptionDeleted($subscription)
    {
        $vendorId = $subscription->metadata->vendor_id ?? null;

        if ($vendorId) {
            $vendor = Vendor::find($vendorId);

            if ($vendor) {
                $vendor->suspend('Subscription cancelled');
            }
        }
    }

    /**
     * Handle invoice payment succeeded
     */
    private function handleInvoicePaymentSucceeded($invoice)
    {
        $vendorId = $invoice->metadata->vendor_id ?? null;

        if ($vendorId) {
            // Record platform fee payment
            PlatformFee::create([
                'vendor_id' => $vendorId,
                'fee_type' => 'subscription',
                'amount' => $invoice->amount_paid / 100,
                'currency' => strtoupper($invoice->currency),
                'status' => 'paid',
                'paid_at' => now(),
                'description' => 'Subscription payment - '.$invoice->billing_reason,
            ]);
        }
    }

    /**
     * Handle invoice payment failed
     */
    private function handleInvoicePaymentFailed($invoice)
    {
        $vendorId = $invoice->metadata->vendor_id ?? null;

        if ($vendorId) {
            // Record failed payment
            PlatformFee::create([
                'vendor_id' => $vendorId,
                'fee_type' => 'subscription',
                'amount' => $invoice->amount_due / 100,
                'currency' => strtoupper($invoice->currency),
                'status' => 'pending',
                'description' => 'Failed subscription payment - '.$invoice->billing_reason,
            ]);

            // Send notification
            $vendor = Vendor::find($vendorId);
            if ($vendor) {
                SendVendorPaymentFailedNotification::dispatch($vendor);
            }
        }
    }

    /**
     * Handle PayPal payment completed
     */
    private function handlePayPalPaymentCompleted($resource)
    {
        $orderId = $resource['custom_id'] ?? null;

        if (! $orderId) {
            Log::warning('No custom_id in PayPal payment', ['payment_id' => $resource['id']]);

            return;
        }

        DB::beginTransaction();

        try {
            $order = Order::find($orderId);

            if ($order) {
                // Create payment transaction
                PaymentTransaction::create([
                    'order_id' => $order->id,
                    'gateway' => 'paypal',
                    'gateway_transaction_id' => $resource['id'],
                    'transaction_type' => 'capture',
                    'amount' => $resource['amount']['value'],
                    'currency' => $resource['amount']['currency_code'],
                    'status' => 'captured',
                    'payment_method_details' => json_encode(['paypal_account' => $resource['seller_receivable_breakdown'] ?? null]),
                    'captured_at' => now(),
                ]);

                // Update order
                $order->updatePaymentStatus('paid');
                $order->calculateCommission();
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process PayPal payment', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle PayPal payment denied
     */
    private function handlePayPalPaymentDenied($resource)
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
     * Handle PayPal refund completed
     */
    private function handlePayPalRefundCompleted($resource)
    {
        $saleId = $resource['sale_id'] ?? null;

        $paymentTransaction = PaymentTransaction::where('gateway_transaction_id', $saleId)->first();

        if ($paymentTransaction) {
            Refund::create([
                'order_id' => $paymentTransaction->order_id,
                'payment_transaction_id' => $paymentTransaction->id,
                'refund_amount' => $resource['amount']['value'],
                'status' => 'processed',
                'gateway_refund_id' => $resource['id'],
                'processed_at' => now(),
            ]);

            $paymentTransaction->status = 'refunded';
            $paymentTransaction->refunded_at = now();
            $paymentTransaction->save();
        }
    }

    /**
     * Handle PayPal order approved
     */
    private function handlePayPalOrderApproved($resource)
    {
        // Capture the order after approval
        $orderId = $resource['purchase_units'][0]['custom_id'] ?? null;

        if ($orderId) {
            $this->payPalService->captureOrder($resource['id']);
        }
    }

    /**
     * Handle PayPal subscription activated
     */
    private function handlePayPalSubscriptionActivated($resource)
    {
        $vendorId = $resource['custom_id'] ?? null;

        if ($vendorId) {
            $vendor = Vendor::find($vendorId);
            if ($vendor) {
                $vendor->activate();
            }
        }
    }

    /**
     * Handle PayPal subscription cancelled
     */
    private function handlePayPalSubscriptionCancelled($resource)
    {
        $vendorId = $resource['custom_id'] ?? null;

        if ($vendorId) {
            $vendor = Vendor::find($vendorId);
            if ($vendor) {
                $vendor->suspend('PayPal subscription cancelled');
            }
        }
    }

    /**
     * Handle PayPal dispute created
     */
    private function handlePayPalDisputeCreated($resource)
    {
        $transactionId = $resource['disputed_transactions'][0]['seller_transaction_id'] ?? null;

        if ($transactionId) {
            $paymentTransaction = PaymentTransaction::where('gateway_transaction_id', $transactionId)->first();

            if ($paymentTransaction) {
                $paymentTransaction->update([
                    'status' => 'disputed',
                    'metadata' => array_merge($paymentTransaction->metadata ?? [], [
                        'dispute_reason' => $resource['reason'],
                        'dispute_id' => $resource['dispute_id'],
                    ]),
                ]);
            }
        }
    }

    /**
     * Verify PayPal webhook signature (implement as needed)
     */
    private function verifyPayPalSignature(Request $request): bool
    {
        // Implement PayPal signature verification using their API
        // This requires calling PayPal's webhook verification endpoint
        return true;
    }
}

