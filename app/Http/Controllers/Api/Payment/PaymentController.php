<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Financial\PaymentTransaction;
use App\Models\Financial\Refund;
use App\Services\Payment\PaymentService;
use App\Services\Payment\StripeService;
use App\Services\Payment\PayPalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    protected $paymentService;
    protected $stripeService;
    protected $payPalService;

    public function __construct(
        PaymentService $paymentService,
        StripeService $stripeService,
        PayPalService $payPalService
    ) {
        $this->paymentService = $paymentService;
        $this->stripeService = $stripeService;
        $this->payPalService = $payPalService;
    }

    /**
     * Create payment intent for order
     */
    public function createIntent(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|string|in:stripe,paypal,card',
            'success_url' => 'nullable|url',
            'cancel_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($orderId);

        // Check if order is already paid
        if ($order->payment_status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Order already paid'
            ], 422);
        }

        try {
            $paymentIntent = $this->paymentService->createPaymentIntent($order, $request->payment_method);

            return response()->json([
                'success' => true,
                'data' => [
                    'client_secret' => $paymentIntent['client_secret'] ?? null,
                    'intent_id' => $paymentIntent['id'],
                    'amount' => $order->grand_total,
                    'currency' => $order->currency_code,
                    'payment_method' => $request->payment_method,
                    'status' => $paymentIntent['status'],
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment intent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm payment
     */
    public function confirm(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'payment_intent_id' => 'required|string',
            'payment_method_id' => 'required_if:payment_method,stripe|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($orderId);

        try {
            $result = $this->paymentService->confirmPayment($order, $request->payment_intent_id, $request->all());

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment confirmed successfully',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'payment_status' => $order->payment_status,
                        'transaction_id' => $result['transaction_id'] ?? null,
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Payment confirmation failed',
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment status for order
     */
    public function status($orderId)
    {
        $order = Order::with('paymentTransactions')->findOrFail($orderId);

        $paymentTransaction = $order->paymentTransactions()->latest()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_status' => $order->payment_status,
                'amount' => $order->grand_total,
                'currency' => $order->currency_code,
                'transaction' => $paymentTransaction ? [
                    'id' => $paymentTransaction->id,
                    'gateway' => $paymentTransaction->gateway,
                    'gateway_transaction_id' => $paymentTransaction->gateway_transaction_id,
                    'status' => $paymentTransaction->status,
                    'amount' => $paymentTransaction->amount,
                    'created_at' => $paymentTransaction->created_at->toIso8601String(),
                ] : null,
            ]
        ]);
    }

    /**
     * Process refund for order
     */
    public function refund(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($orderId);

        if (!$order->canBeRefunded()) {
            return response()->json([
                'success' => false,
                'message' => 'Order cannot be refunded'
            ], 422);
        }

        $refundAmount = $request->amount ?? $order->grand_total;
        $maxRefund = $order->grand_total - $order->total_refunded_amount;

        if ($refundAmount > $maxRefund) {
            return response()->json([
                'success' => false,
                'message' => 'Refund amount exceeds remaining order amount',
                'data' => [
                    'max_refund' => $maxRefund,
                    'requested' => $refundAmount,
                ]
            ], 422);
        }

        DB::beginTransaction();

        try {
            $paymentTransaction = $order->paymentTransactions()
                ->where('status', 'captured')
                ->first();

            if (!$paymentTransaction) {
                throw new \Exception('No captured payment found for this order');
            }

            $refund = $this->paymentService->processRefund($paymentTransaction, $refundAmount, $request->reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Refund processed successfully',
                'data' => [
                    'refund_id' => $refund->id,
                    'amount' => $refund->refund_amount,
                    'status' => $refund->status,
                    'transaction_id' => $refund->gateway_refund_id,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to process refund',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment methods
     */
    public function methods(Request $request)
    {
        $countryCode = $request->get('country_code', 'DE');
        
        $paymentMethods = $this->paymentService->getAvailablePaymentMethods($countryCode);

        return response()->json([
            'success' => true,
            'data' => $paymentMethods
        ]);
    }

    /**
     * Save payment method for future use
     */
    public function saveMethod(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|string',
            'set_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();

        try {
            $savedMethod = $this->paymentService->savePaymentMethod($user, $request->payment_method_id, $request->set_default ?? false);

            return response()->json([
                'success' => true,
                'message' => 'Payment method saved successfully',
                'data' => $savedMethod
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save payment method',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get saved payment methods for user
     */
    public function getSavedMethods()
    {
        $user = auth()->user();
        
        $methods = $this->paymentService->getSavedPaymentMethods($user);

        return response()->json([
            'success' => true,
            'data' => $methods
        ]);
    }

    /**
     * Delete saved payment method
     */
    public function deleteSavedMethod($methodId)
    {
        $user = auth()->user();

        try {
            $this->paymentService->deletePaymentMethod($user, $methodId);

            return response()->json([
                'success' => true,
                'message' => 'Payment method deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete payment method',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment transaction details
     */
    public function transaction($transactionId)
    {
        $transaction = PaymentTransaction::with('order')->findOrFail($transactionId);

        // Check permission
        $user = auth()->user();
        if (!$user->is_admin && $transaction->order->customer_id !== $user->uuid) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'gateway_transaction_id' => $transaction->gateway_transaction_id,
                'type' => $transaction->transaction_type,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status,
                'payment_method_details' => $transaction->payment_method_details,
                'created_at' => $transaction->created_at->toIso8601String(),
                'captured_at' => $transaction->captured_at?->toIso8601String(),
                'order' => [
                    'id' => $transaction->order->id,
                    'number' => $transaction->order->order_number,
                ],
            ]
        ]);
    }

    /**
     * Get payment statistics (admin only)
     */
    public function statistics(Request $request)
    {
        $query = PaymentTransaction::query();

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $stats = [
            'total_transactions' => $query->count(),
            'total_volume' => $query->sum('amount'),
            'successful_payments' => (clone $query)->where('status', 'captured')->count(),
            'failed_payments' => (clone $query)->where('status', 'failed')->count(),
            'refunded_payments' => (clone $query)->where('status', 'refunded')->count(),
            'by_gateway' => [
                'stripe' => (clone $query)->where('gateway', 'stripe')->count(),
                'paypal' => (clone $query)->where('gateway', 'paypal')->count(),
            ],
            'average_transaction_value' => $query->avg('amount') ?? 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
