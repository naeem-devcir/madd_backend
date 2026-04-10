<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\Order\SyncOrderToLaravel;
use App\Jobs\Product\SyncProductFromMagento;
use App\Jobs\Inventory\UpdateInventoryFromMagento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MagentoWebhookController extends Controller
{
    /**
     * Handle order created webhook
     */
    public function handleOrderCreated(Request $request)
    {
        // Verify webhook signature
        if (!$this->verifySignature($request)) {
            Log::warning('Invalid Magento webhook signature', ['payload' => $request->all()]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->input('order');

        // Dispatch job to process order
        SyncOrderToLaravel::dispatch($payload);

        // Return quickly to avoid timeout
        return response()->json(['success' => true], 200);
    }

    /**
     * Handle order updated webhook
     */
    public function handleOrderUpdated(Request $request)
    {
        if (!$this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->input('order');

        // Update order status in Laravel
        SyncOrderToLaravel::dispatch($payload, true);

        return response()->json(['success' => true], 200);
    }

    /**
     * Handle product updated webhook
     */
    public function handleProductUpdated(Request $request)
    {
        if (!$this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->input('product');

        SyncProductFromMagento::dispatch($payload);

        return response()->json(['success' => true], 200);
    }

    /**
     * Handle inventory updated webhook
     */
    public function handleInventoryUpdated(Request $request)
    {
        if (!$this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->input('inventory');

        UpdateInventoryFromMagento::dispatch($payload);

        return response()->json(['success' => true], 200);
    }

    /**
     * Verify webhook signature
     */
    private function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Magento-Signature');
        $payload = $request->getContent();
        $secret = config('services.magento.webhook_secret');

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    public function handleOrderCancelled(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Order cancellation webhook is not implemented yet.',
        ], 501);
    }

    public function handleProductCreated(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Product creation webhook is not implemented yet.',
        ], 501);
    }

    public function handleProductDeleted(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Product deletion webhook is not implemented yet.',
        ], 501);
    }

    public function handleCustomerCreated(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Customer creation webhook is not implemented yet.',
        ], 501);
    }

    public function handleCustomerUpdated(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Customer update webhook is not implemented yet.',
        ], 501);
    }

    public function handleReviewCreated(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Review creation webhook is not implemented yet.',
        ], 501);
    }
}
