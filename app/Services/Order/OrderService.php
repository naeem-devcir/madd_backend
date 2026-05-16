<?php

namespace App\Services\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OrderTracking;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Models\Product\VendorProduct;
use App\Models\User;
use App\Services\Integration\MagentoService;
use App\Services\Vendor\CommissionService;
use App\Services\Inventory\InventoryService;
use App\Events\Order\OrderCreated;
// use App\Events\Order\OrderStatusChanged;
use App\Events\Events\Order\OrderStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class OrderService
{
    protected CommissionService $commissionService;
    protected InventoryService $inventoryService;

    public function __construct(
        CommissionService $commissionService, 
        InventoryService $inventoryService
    ) {
        $this->commissionService = $commissionService;
        $this->inventoryService = $inventoryService;
    }

    // ─────────────────────────────────────────────────────
    // DATABASE QUERY METHODS (USED BY CONTROLLERS)
    // ─────────────────────────────────────────────────────

    /**
     * Get orders from local database with filters
     * 
     * @param string|null $vendorId - Vendor UUID
     * @param string|null $storeId - Vendor Store UUID
     * @param array $filters - Additional filters (status, date range, etc.)
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getOrdersFromDatabase(
        ?string $vendorId = null,
        ?string $storeId = null,
        array $filters = [],
        int $perPage = 15
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        
        $query = Order::with(['vendor', 'vendorStore', 'customer', 'items']);
        
        // Filter by vendor UUID
        if ($vendorId) {
            $query->whereHas('vendor', function (Builder $q) use ($vendorId) {
                $q->where('uuid', $vendorId);
            });
        }
        
        // Filter by store UUID
        if ($storeId) {
            $query->whereHas('vendorStore', function (Builder $q) use ($storeId) {
                $q->where('uuid', $storeId);
            });
        }
        
        // Apply status filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        // Apply payment status filter
        if (!empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }
        
        // Apply search filter
        if (!empty($filters['search'])) {
            $searchTerm = '%' . addcslashes($filters['search'], '%_') . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('magento_order_increment_id', 'like', $searchTerm)
                    ->orWhere('customer_email', 'like', $searchTerm)
                    ->orWhere('customer_firstname', 'like', $searchTerm)
                    ->orWhere('customer_lastname', 'like', $searchTerm);
            });
        }
        
        // Apply date range filter
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        
        // Apply amount range filter
        if (!empty($filters['amount_min'])) {
            $query->where('grand_total', '>=', $filters['amount_min']);
        }
        
        if (!empty($filters['amount_max'])) {
            $query->where('grand_total', '<=', $filters['amount_max']);
        }
        
        $perPage = min($perPage, 100);
        
        return $query->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get single order from local database by ID
     * 
     * @param string $orderId - Order UUID
     * @param string|null $vendorId - Optional vendor UUID for authorization
     * @param string|null $storeId - Optional store UUID for authorization
     * @return Order|null
     */
    public function getOrderFromDatabase(
        string $orderId, 
        ?string $vendorId = null, 
        ?string $storeId = null
    ): ?Order {
        $query = Order::with([
            'vendor',
            'vendorStore',
            'customer',
            'items',
            'items.vendorProduct',
            'statusHistory',
            'tracking',
            'tracking.carrier',
            'paymentTransactions',
            'refunds',
            'settlement',
        ]);
        
        // Apply authorization filters
        if ($vendorId) {
            $query->whereHas('vendor', function (Builder $q) use ($vendorId) {
                $q->where('uuid', $vendorId);
            });
        }
        
        if ($storeId) {
            $query->whereHas('vendorStore', function (Builder $q) use ($storeId) {
                $q->where('uuid', $storeId);
            });
        }
        
        return $query->where('uuid', $orderId)->first();
    }

    /**
     * Get order statistics from local database
     * 
     * @param string|null $vendorId
     * @param string|null $storeId
     * @param string $period
     * @return array
     */
    public function getOrderStatisticsFromDatabase(
        ?string $vendorId = null,
        ?string $storeId = null,
        string $period = '30_days'
    ): array {
        $query = Order::query();
        
        if ($vendorId) {
            $query->whereHas('vendor', function (Builder $q) use ($vendorId) {
                $q->where('uuid', $vendorId);
            });
        }
        
        if ($storeId) {
            $query->whereHas('vendorStore', function (Builder $q) use ($storeId) {
                $q->where('uuid', $storeId);
            });
        }
        
        $startDate = match ($period) {
            '7_days' => now()->subDays(7),
            '30_days' => now()->subDays(30),
            '90_days' => now()->subDays(90),
            'year' => now()->subYear(),
            default => now()->subDays(30),
        };
        
        $periodQuery = clone $query;
        $periodOrders = $periodQuery->where('created_at', '>=', $startDate);
        
        $totalQuery = clone $query;
        
        return [
            'total_orders' => $totalQuery->count(),
            'total_revenue' => $totalQuery->where('status', '!=', 'cancelled')->sum('grand_total'),
            'average_order_value' => $totalQuery->where('status', '!=', 'cancelled')->avg('grand_total') ?? 0,
            'pending_orders' => (clone $totalQuery)->where('status', 'pending')->count(),
            'processing_orders' => (clone $totalQuery)->where('status', 'processing')->count(),
            'shipped_orders' => (clone $totalQuery)->where('status', 'shipped')->count(),
            'delivered_orders' => (clone $totalQuery)->where('status', 'delivered')->count(),
            'cancelled_orders' => (clone $totalQuery)->where('status', 'cancelled')->count(),
            'period_orders' => $periodOrders->count(),
            'period_revenue' => $periodOrders->where('status', '!=', 'cancelled')->sum('grand_total'),
            'period_start' => $startDate->toDateString(),
            'period_end' => now()->toDateString(),
        ];
    }

    /**
     * Get order timeline from local database
     * 
     * @param Order $order
     * @return array
     */
    public function getOrderTimeline(Order $order): array
    {
        $timeline = [];

        $timeline[] = [
            'event' => 'Order Created',
            'status' => 'pending',
            'description' => 'Order has been placed',
            'timestamp' => $order->created_at->toIso8601String(),
        ];

        if ($order->payment_status === 'paid') {
            $timeline[] = [
                'event' => 'Payment Received',
                'status' => 'paid',
                'description' => 'Payment has been confirmed',
                'timestamp' => $order->payment_confirmed_at?->toIso8601String() ?? $order->updated_at->toIso8601String(),
            ];
        }

        if ($order->status === 'processing') {
            $timeline[] = [
                'event' => 'Order Processing',
                'status' => 'processing',
                'description' => 'Order is being prepared',
                'timestamp' => $order->processed_at?->toIso8601String() ?? $order->updated_at->toIso8601String(),
            ];
        }

        if ($order->shipped_at) {
            $timeline[] = [
                'event' => 'Order Shipped',
                'status' => 'shipped',
                'description' => 'Order has been shipped' . ($order->tracking_number ? ' - Tracking: ' . $order->tracking_number : ''),
                'timestamp' => $order->shipped_at->toIso8601String(),
            ];
        }

        if ($order->delivered_at) {
            $timeline[] = [
                'event' => 'Order Delivered',
                'status' => 'delivered',
                'description' => 'Order has been delivered',
                'timestamp' => $order->delivered_at->toIso8601String(),
            ];
        }

        return $timeline;
    }

    // ─────────────────────────────────────────────────────
    // SYNC METHODS (FETCH FROM MAGENTO, UPDATE LOCAL DB)
    // ─────────────────────────────────────────────────────

    /**
     * Sync orders from Magento to local database
     * This method is called from frontend to trigger sync
     * 
     * @param Vendor $vendor
     * @param array $options - Sync options (page_size, status_filter, etc.)
     * @return array - Sync results
     */
    public function syncOrdersFromMagento(Vendor $vendor, array $options = []): array
    {
        Log::info('Starting order sync from Magento', [
            'vendor_id' => $vendor->id,
            'vendor_uuid' => $vendor->uuid,
            'options' => $options
        ]);
        
        $magentoService = MagentoService::forVendor($vendor);
        
        $pageSize = $options['page_size'] ?? 50;
        $currentPage = $options['current_page'] ?? 1;
        $maxPages = $options['max_pages'] ?? 5; // Limit to avoid overloading
        
        $syncedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $errors = [];
        
        try {
            // Build search criteria for orders
            $searchCriteria = $this->buildOrderSearchCriteria($options);
            
            for ($page = $currentPage; $page <= $maxPages; $page++) {
                $searchCriteria['searchCriteria[currentPage]'] = $page;
                $searchCriteria['searchCriteria[pageSize]'] = $pageSize;
                
                Log::info('Fetching orders from Magento API', [
                    'vendor_id' => $vendor->id,
                    'page' => $page,
                    'page_size' => $pageSize
                ]);
                
                $response = $magentoService->get('orders', $searchCriteria);
                
                Log::info($response);
                
                if (empty($response['items'])) {
                    Log::info('No more orders found from Magento', [
                        'vendor_id' => $vendor->id,
                        'page' => $page
                    ]);
                    break;
                }
                
                foreach ($response['items'] as $magentoOrder) {
                    try {
                        $result = $this->syncSingleOrder($magentoOrder, $vendor, $options);
                        
                        if ($result['action'] === 'created') {
                            $syncedCount++;
                        } elseif ($result['action'] === 'updated') {
                            $updatedCount++;
                        } else {
                            $skippedCount++;
                        }
                    } catch (\Exception $e) {
                        $skippedCount++;
                        $errors[] = [
                            'order_id' => $magentoOrder['entity_id'] ?? 'unknown',
                            'increment_id' => $magentoOrder['increment_id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ];
                        Log::error('Failed to sync individual order', [
                            'order_id' => $magentoOrder['entity_id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                // Check if we've fetched all pages
                $totalCount = $response['total_count'] ?? 0;
                if (($page * $pageSize) >= $totalCount) {
                    break;
                }
            }
            
            // Update vendor's last sync timestamp
            $vendor->update([
                'magento_orders_synced_at' => now(),
                'magento_synced_at' => now(),
            ]);
            
            Log::info('Order sync completed', [
                'vendor_id' => $vendor->id,
                'synced' => $syncedCount,
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
                'errors' => count($errors)
            ]);
            
            return [
                'success' => true,
                'synced_count' => $syncedCount,
                'updated_count' => $updatedCount,
                'skipped_count' => $skippedCount,
                'errors' => $errors,
                'vendor_id' => $vendor->uuid,
                'synced_at' => now()->toIso8601String(),
            ];
            
        } catch (\Exception $e) {
            Log::error('Order sync failed', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'synced_count' => $syncedCount,
                'updated_count' => $updatedCount,
                'skipped_count' => $skippedCount,
            ];
        }
    }

    /**
     * Sync a single order from Magento
     * 
     * @param array $magentoOrder
     * @param Vendor $vendor
     * @return array
     */
    protected function syncSingleOrder(array $magentoOrder, Vendor $vendor, array $options = []): array
    {
        if (!empty($options['magento_store_id']) && (int) ($magentoOrder['store_id'] ?? 0) !== (int) $options['magento_store_id']) {
            return ['action' => 'skipped', 'reason' => 'store_mismatch'];
        }

        // Check if order already exists
        $existingOrder = Order::where('magento_order_id', $magentoOrder['entity_id'])
            ->orWhere('magento_order_increment_id', $magentoOrder['increment_id'])
            ->first();
        
        if ($existingOrder) {
            if ($options['only_create_missing'] ?? false) {
                return ['action' => 'skipped', 'reason' => 'already_exists', 'order' => $existingOrder];
            }

            return $this->updateExistingOrder($existingOrder, $magentoOrder);
        }
        
        return $this->createNewOrder($magentoOrder, $vendor, $options);
    }

    /**
     * Create new order from Magento data
     * 
     * @param array $magentoOrder
     * @param Vendor $vendor
     * @return array
     * @throws \Exception
     */
    protected function createNewOrder(array $magentoOrder, Vendor $vendor, array $options = []): array
    {
        DB::beginTransaction();

        try {
            // Find vendor store by Magento store ID
            $vendorStoreQuery = VendorStore::where('vendor_id', $vendor->id)
                ->where('magento_store_id', $magentoOrder['store_id']);

            if (!empty($options['store_uuid'])) {
                $vendorStoreQuery->where('uuid', $options['store_uuid']);
            }

            $vendorStore = $vendorStoreQuery->first();
            
            if (!$vendorStore) {
                throw new \Exception('Vendor store not found for Magento store ID: ' . $magentoOrder['store_id']);
            }

            // Find or create customer
            $customer = null;
            if (isset($magentoOrder['customer_id']) && $magentoOrder['customer_id']) {
                $customer = User::where('magento_customer_id', $magentoOrder['customer_id'])->first();
            }

            // Prepare order data
            $orderData = [
                'magento_order_id' => $magentoOrder['entity_id'],
                'magento_order_increment_id' => $magentoOrder['increment_id'],
                'vendor_id' => $vendor->id,
                'vendor_store_id' => $vendorStore->id,
                'customer_id' => $customer?->id,
                'customer_email' => $magentoOrder['customer_email'] ?? null,
                'customer_firstname' => $magentoOrder['customer_firstname'] ?? null,
                'customer_lastname' => $magentoOrder['customer_lastname'] ?? null,
                'status' => $magentoOrder['status'],
                'payment_status' => $this->determinePaymentStatus($magentoOrder),
                'currency_code' => $magentoOrder['order_currency_code'] ?? 'USD',
                'subtotal' => $magentoOrder['subtotal'] ?? 0,
                'tax_amount' => $magentoOrder['tax_amount'] ?? 0,
                'shipping_amount' => $magentoOrder['shipping_amount'] ?? 0,
                'discount_amount' => $magentoOrder['discount_amount'] ?? 0,
                'grand_total' => $magentoOrder['grand_total'] ?? 0,
                'payment_method' => $magentoOrder['payment']['method'] ?? 'unknown',
                'shipping_method' => $magentoOrder['shipping_method'] ?? null,
                'shipping_address' => $magentoOrder['extension_attributes']['shipping_assignments'][0]['shipping']['address'] ?? $magentoOrder['shipping_address'] ?? [],
                'billing_address' => $magentoOrder['billing_address'] ?? [],
                'synced_at' => now(),
                'sync_status' => 'synced',
            ];

            $order = Order::create($orderData);

            // Create order items
            foreach ($magentoOrder['items'] as $item) {
                $vendorProduct = VendorProduct::where('vendor_id', $vendor->id)
                    ->where('magento_sku', $item['sku'])
                    ->first();

                OrderItem::create([
                    'order_id' => $order->id,
                    'magento_item_id' => $item['item_id'],
                    'vendor_product_id' => $vendorProduct?->id,
                    'magento_product_id' => $item['product_id'],
                    'magento_sku' => $item['sku'],
                    'product_sku' => $item['sku'],
                    'product_name' => $item['name'],
                    'qty_ordered' => $item['qty_ordered'] ?? 0,
                    'price' => $item['price'] ?? 0,
                    'tax_amount' => $item['tax_amount'] ?? 0,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'row_total' => $item['row_total'] ?? 0,
                ]);
            }

            // Calculate commission
            try {
                $this->commissionService->calculateOrderCommission($order);
            } catch (\Exception $e) {
                Log::warning('Failed to calculate commission for order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
            }

            DB::commit();

            // Dispatch event
            event(new OrderCreated($order));

            Log::info('Order created from Magento sync', [
                'order_id' => $order->id,
                'magento_order_id' => $magentoOrder['entity_id']
            ]);

            return ['action' => 'created', 'order' => $order];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update existing order from Magento data
     * 
     * @param Order $order
     * @param array $magentoOrder
     * @return array
     */
    protected function updateExistingOrder(Order $order, array $magentoOrder): array
    {
        $oldStatus = $order->status;
        $newStatus = $magentoOrder['status'];
        
        $order->update([
            'status' => $newStatus,
            'payment_status' => $this->determinePaymentStatus($magentoOrder),
            'subtotal' => $magentoOrder['subtotal'] ?? $order->subtotal,
            'tax_amount' => $magentoOrder['tax_amount'] ?? $order->tax_amount,
            'shipping_amount' => $magentoOrder['shipping_amount'] ?? $order->shipping_amount,
            'discount_amount' => $magentoOrder['discount_amount'] ?? $order->discount_amount,
            'grand_total' => $magentoOrder['grand_total'] ?? $order->grand_total,
            'shipping_address' => $magentoOrder['extension_attributes']['shipping_assignments'][0]['shipping']['address'] ?? $magentoOrder['shipping_address'] ?? $order->shipping_address,
            'billing_address' => $magentoOrder['billing_address'] ?? $order->billing_address,
            'synced_at' => now(),
            'sync_status' => 'synced',
        ]);

        if ($oldStatus !== $newStatus) {
            event(new OrderStatusChanged($order, $oldStatus, $newStatus));
        }

        Log::info('Order updated from Magento sync', [
            'order_id' => $order->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);

        return ['action' => 'updated', 'order' => $order];
    }

    /**
     * Build search criteria for Magento orders API
     * 
     * @param array $options
     * @return array
     */
    protected function buildOrderSearchCriteria(array $options = []): array
    {
        $criteria = [];
        $filterIndex = 0;
        
        // Filter by status
        if (!empty($options['status'])) {
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'status';
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $options['status'];
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'eq';
            $filterIndex++;
        }

        // Filter by Magento store selected in admin.
        if (!empty($options['magento_store_id'])) {
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'store_id';
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $options['magento_store_id'];
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'eq';
            $filterIndex++;
        }
        
        // Filter by date range (created_at)
        if (!empty($options['from_date'])) {
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'created_at';
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $options['from_date'];
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'gteq';
            $filterIndex++;
        }
        
        if (!empty($options['to_date'])) {
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'created_at';
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $options['to_date'];
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'lteq';
            $filterIndex++;
        }
        
        // Filter by increment ID (order number)
        if (!empty($options['increment_id'])) {
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'increment_id';
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $options['increment_id'];
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'eq';
            $filterIndex++;
        }
        
        // Sorting
        $criteria["searchCriteria[sortOrders][0][field]"] = $options['sort_field'] ?? 'created_at';
        $criteria["searchCriteria[sortOrders][0][direction]"] = $options['sort_direction'] ?? 'DESC';
        
        return $criteria;
    }

    /**
     * Determine payment status from Magento order data
     * 
     * @param array $magentoOrder
     * @return string
     */
    protected function determinePaymentStatus(array $magentoOrder): string
    {
        // Check payment status from Magento order
        if (isset($magentoOrder['status'])) {
            $status = strtolower($magentoOrder['status']);
            
            if (in_array($status, ['canceled', 'cancelled'])) {
                return 'refunded';
            }
            
            if ($status === 'closed') {
                return 'paid';
            }
        }
        
        // Check payment additional information
        if (isset($magentoOrder['payment']['additional_information'])) {
            $paymentInfo = $magentoOrder['payment']['additional_information'];
            if (is_array($paymentInfo) && in_array('captured', $paymentInfo)) {
                return 'paid';
            }
        }
        
        // Default based on order status
        $orderStatus = $magentoOrder['status'] ?? '';
        if (in_array($orderStatus, ['pending', 'pending_payment'])) {
            return 'pending';
        }
        
        if (in_array($orderStatus, ['processing', 'complete', 'closed'])) {
            return 'paid';
        }
        
        return 'pending';
    }

    // ─────────────────────────────────────────────────────
    // ORDER MANAGEMENT METHODS (UPDATE, CANCEL, REFUND)
    // ─────────────────────────────────────────────────────

    /**
     * Update order status
     * 
     * @param Order $order
     * @param string $status
     * @param string|null $notes
     * @param User|null $user
     * @return Order
     */
    public function updateOrderStatus(Order $order, string $status, ?string $notes = null, ?User $user = null): Order
    {
        $oldStatus = $order->status;
        
        $order->status = $status;
        
        if ($status === 'shipped' && !$order->shipped_at) {
            $order->shipped_at = now();
        }
        
        if ($status === 'delivered' && !$order->delivered_at) {
            $order->delivered_at = now();
        }
        
        if ($status === 'processing' && !$order->processed_at) {
            $order->processed_at = now();
        }
        
        $order->save();
        
        // Add to status history if you have the model
        // OrderStatusHistory::create([
        //     'order_id' => $order->id,
        //     'old_status' => $oldStatus,
        //     'new_status' => $status,
        //     'notes' => $notes,
        //     'user_id' => $user?->id,
        // ]);
        
        event(new OrderStatusChanged($order, $oldStatus, $status));
        
        return $order;
    }

    /**
     * Cancel order
     * 
     * @param Order $order
     * @param string $reason
     * @param User|null $user
     * @return Order
     * @throws \Exception
     */
    public function cancelOrder(Order $order, string $reason, ?User $user = null): Order
    {
        if (!$order->canBeCancelled()) {
            throw new \Exception('Order cannot be cancelled at this stage');
        }

        DB::beginTransaction();

        try {
            // Restore inventory
            foreach ($order->items as $item) {
                if ($item->vendor_product_id) {
                    $this->inventoryService->restoreStock($item->vendor_product_id, $item->qty_ordered);
                }
            }

            // Update order status
            $order->status = 'cancelled';
            $order->save();

            // Add cancellation note
            $adminNotes = $order->admin_note ? json_decode($order->admin_note, true) : [];
            $adminNotes[] = [
                'type' => 'cancellation',
                'reason' => $reason,
                'cancelled_by' => $user?->id,
                'cancelled_at' => now()->toIso8601String(),
            ];
            $order->admin_note = json_encode($adminNotes);
            $order->save();

            // Process refund if payment was captured
            if ($order->payment_status === 'paid') {
                $this->processRefund($order);
            }

            DB::commit();

            return $order;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process refund for order
     * 
     * @param Order $order
     * @param float|null $amount
     * @param string|null $reason
     * @return array
     */
    public function processRefund(Order $order, ?float $amount = null, ?string $reason = null): array
    {
        $refundAmount = $amount ?? $order->grand_total;
        
        if ($refundAmount > $order->grand_total) {
            throw new \Exception('Refund amount cannot exceed order total');
        }
        
        // Create refund record (assuming Refund model exists)
        // $refund = Refund::create([
        //     'order_id' => $order->id,
        //     'vendor_id' => $order->vendor_id,
        //     'amount' => $refundAmount,
        //     'reason' => $reason,
        //     'status' => 'pending',
        //     'requested_by' => auth()->id(),
        //     'requested_at' => now(),
        // ]);
        
        // Update order payment status
        if ($refundAmount >= $order->grand_total) {
            $order->payment_status = 'refunded';
        } else {
            $order->payment_status = 'partial_refunded';
        }
        $order->save();
        
        return [
            'success' => true,
            'refund_amount' => $refundAmount,
            'status' => 'pending'
        ];
    }

    // Magento-first write methods live below. Controllers validate/scope requests,
    // Magento confirms the write, then these methods refresh local order state.

    public function createManualOrderInMagento(Vendor $vendor, VendorStore $store, array $orderData, ?int $userId = null): array
    {
        $magentoService = MagentoService::forVendor($vendor);
        $customerId = (int) data_get($orderData, 'customer.id');

        if (empty($store->magento_store_id)) {
            throw new \Exception('Selected store is not linked to a Magento store');
        }

        DB::beginTransaction();

        try {
            $cartIdResponse = $magentoService->post("customers/{$customerId}/carts");
            $cartId = $cartIdResponse['value'] ?? $cartIdResponse['id'] ?? $cartIdResponse['cart_id'] ?? null;

            if (!$cartId && is_numeric($cartIdResponse[0] ?? null)) {
                $cartId = $cartIdResponse[0];
            }

            if (!$cartId) {
                throw new \Exception('Magento did not return a cart ID for the selected customer');
            }

            foreach ($orderData['items'] as $item) {
                $magentoService->post("carts/{$cartId}/items", [
                    'cartItem' => [
                        'sku' => $item['sku'],
                        'qty' => (int) $item['qty'],
                        'quote_id' => (string) $cartId,
                    ],
                ]);
            }

            if (!empty($orderData['coupon_code'])) {
                $magentoService->put("carts/{$cartId}/coupons/" . rawurlencode($orderData['coupon_code']), []);
            }

            $shippingAddress = $this->formatMagentoAddress($orderData['shipping_address'], $orderData['customer']['email']);
            $billingAddress = $this->formatMagentoAddress($orderData['billing_address'], $orderData['customer']['email']);

            $shippingInfo = $magentoService->post("carts/{$cartId}/shipping-information", [
                'addressInformation' => [
                    'shipping_address' => $shippingAddress,
                    'billing_address' => $billingAddress,
                    'shipping_carrier_code' => $orderData['shipping_method']['carrier_code'],
                    'shipping_method_code' => $orderData['shipping_method']['method_code'],
                ],
            ]);

            $createdOrderResponse = $magentoService->post("carts/{$cartId}/payment-information", [
                'paymentMethod' => [
                    'method' => $orderData['payment_method'],
                ],
                'billing_address' => $billingAddress,
            ]);

            $magentoOrderId = $createdOrderResponse['value'] ?? $createdOrderResponse['order_id'] ?? $createdOrderResponse['entity_id'] ?? null;
            $magentoOrder = null;

            if ($magentoOrderId) {
                $magentoOrder = $magentoService->get("orders/{$magentoOrderId}");
            } else {
                $magentoOrder = $this->findLatestMagentoOrderForCustomer($magentoService, $customerId, $store->magento_store_id);
            }

            if (!$magentoOrder || empty($magentoOrder['entity_id'])) {
                throw new \Exception('Magento order was created but could not be fetched for synchronization');
            }

            $syncResult = $this->syncSingleOrder($magentoOrder, $vendor, [
                'store_uuid' => $store->uuid,
                'magento_store_id' => $store->magento_store_id,
                'only_create_missing' => false,
            ]);

            $localOrder = $syncResult['order'] ?? Order::where('magento_order_id', $magentoOrder['entity_id'])->first();

            if ($localOrder && !empty($orderData['history']['append_comment']) && !empty($orderData['history']['comment'])) {
                $this->recordOrderHistory($localOrder, $localOrder->status, $orderData['history']['comment'], $userId, [
                    'email_confirmation' => (bool) ($orderData['history']['email_confirmation'] ?? false),
                    'source' => 'manual_order_create',
                ]);
            }

            DB::commit();

            return [
                'cart_id' => $cartId,
                'magento_order_id' => $magentoOrder['entity_id'],
                'magento_order_increment_id' => $magentoOrder['increment_id'] ?? null,
                'shipping_information' => $shippingInfo,
                'payment_information' => $createdOrderResponse,
                'sync' => $syncResult,
                'order' => $localOrder,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateOrderStatusInMagento(Vendor $vendor, Order $order, VendorStore $store, array $data, ?int $userId = null): array
    {
        $comment = $data['comment'] ?? $data['notes'] ?? 'Status updated from admin';
        $response = MagentoService::forVendor($vendor)->post("orders/{$order->magento_order_id}/comments", [
            'statusHistory' => [
                'comment' => $comment,
                'status' => $data['status'],
                'is_customer_notified' => 0,
                'is_visible_on_front' => 0,
            ],
        ]);

        $this->recordOrderHistory($order, $data['status'], $comment, $userId, ['magento_response' => $response]);
        $refreshed = $this->refreshLocalOrderFromMagento($vendor, $order, $store);

        return ['message' => 'Order status updated in Magento and synchronized locally', 'magento_response' => $response, 'order' => $refreshed];
    }

    public function cancelOrderInMagento(Vendor $vendor, Order $order, VendorStore $store, array $data = [], ?int $userId = null): array
    {
        $response = MagentoService::forVendor($vendor)->post("orders/{$order->magento_order_id}/cancel");
        $this->recordOrderHistory($order, 'cancelled', $data['reason'] ?? $data['notes'] ?? 'Order cancelled in Magento', $userId, ['magento_response' => $response]);
        $refreshed = $this->refreshLocalOrderFromMagento($vendor, $order, $store);

        return ['message' => 'Order cancelled in Magento and synchronized locally', 'magento_response' => $response, 'order' => $refreshed];
    }

    public function holdOrderInMagento(Vendor $vendor, Order $order, VendorStore $store): array
    {
        $response = MagentoService::forVendor($vendor)->post("orders/{$order->magento_order_id}/hold");
        $this->recordOrderHistory($order, 'holded', 'Order placed on hold in Magento', auth()->id(), ['magento_response' => $response]);
        $refreshed = $this->refreshLocalOrderFromMagento($vendor, $order, $store);

        return ['message' => 'Order placed on hold in Magento and synchronized locally', 'magento_response' => $response, 'order' => $refreshed];
    }

    public function unholdOrderInMagento(Vendor $vendor, Order $order, VendorStore $store): array
    {
        $response = MagentoService::forVendor($vendor)->post("orders/{$order->magento_order_id}/unHold");
        $this->recordOrderHistory($order, 'unholded', 'Order released from hold in Magento', auth()->id(), ['magento_response' => $response]);
        $refreshed = $this->refreshLocalOrderFromMagento($vendor, $order, $store);

        return ['message' => 'Order released from hold in Magento and synchronized locally', 'magento_response' => $response, 'order' => $refreshed];
    }

    public function createInvoiceInMagento(Vendor $vendor, Order $order, VendorStore $store, array $invoiceData = []): array
    {
        $payload = [
            'capture' => (bool) ($invoiceData['capture'] ?? true),
            'notify' => (bool) ($invoiceData['notify'] ?? false),
            'appendComment' => (bool) ($invoiceData['append_comment'] ?? !empty($invoiceData['comment'])),
        ];

        if (!empty($invoiceData['comment'])) {
            $payload['comment'] = ['comment' => $invoiceData['comment'], 'is_visible_on_front' => 0];
        }

        $response = MagentoService::forVendor($vendor)->post("order/{$order->magento_order_id}/invoice", $payload);
        $this->recordOrderHistory($order, 'invoiced', $invoiceData['comment'] ?? 'Invoice created in Magento', auth()->id(), ['magento_response' => $response]);
        $refreshed = $this->refreshLocalOrderFromMagento($vendor, $order, $store);

        return ['message' => 'Invoice created in Magento and order synchronized locally', 'magento_response' => $response, 'order' => $refreshed];
    }

    public function createShipmentInMagento(Vendor $vendor, Order $order, VendorStore $store, array $shipmentData = []): array
    {
        $payload = [
            'notify' => (bool) ($shipmentData['notify'] ?? false),
            'appendComment' => (bool) ($shipmentData['append_comment'] ?? !empty($shipmentData['comment'])),
            'tracks' => $shipmentData['tracks'] ?? [],
        ];

        if (!empty($shipmentData['comment'])) {
            $payload['comment'] = ['comment' => $shipmentData['comment'], 'is_visible_on_front' => 0];
        }

        $response = MagentoService::forVendor($vendor)->post("order/{$order->magento_order_id}/ship", $payload);
        foreach ($payload['tracks'] as $track) {
            $this->upsertLocalTracking($order, $track);
        }
        $this->recordOrderHistory($order, 'shipped', $shipmentData['comment'] ?? 'Shipment created in Magento', auth()->id(), ['magento_response' => $response]);
        $refreshed = $this->refreshLocalOrderFromMagento($vendor, $order, $store);

        return ['message' => 'Shipment created in Magento and order synchronized locally', 'magento_response' => $response, 'order' => $refreshed];
    }

    public function addTrackingInMagento(Vendor $vendor, Order $order, VendorStore $store, array $trackingData): array
    {
        $track = [
            'track_number' => $trackingData['track_number'],
            'title' => $trackingData['title'] ?? $trackingData['carrier_code'] ?? 'Tracking',
            'carrier_code' => $trackingData['carrier_code'] ?? 'custom',
        ];

        if (!empty($trackingData['shipment_id'])) {
            $response = MagentoService::forVendor($vendor)->post('shipment/track', [
                'entity' => array_merge($track, [
                    'parent_id' => (int) $trackingData['shipment_id'],
                    'order_id' => $order->magento_order_id,
                ]),
            ]);
        } else {
            $response = MagentoService::forVendor($vendor)->post("order/{$order->magento_order_id}/ship", [
                'notify' => false,
                'appendComment' => true,
                'comment' => ['comment' => 'Tracking added from admin', 'is_visible_on_front' => 0],
                'tracks' => [$track],
            ]);
        }

        $this->upsertLocalTracking($order, $track);
        $this->recordOrderHistory($order, 'tracking_added', 'Tracking added in Magento', auth()->id(), ['tracking' => $track, 'magento_response' => $response]);
        $refreshed = $this->refreshLocalOrderFromMagento($vendor, $order, $store);

        return ['message' => 'Tracking added in Magento and synchronized locally', 'magento_response' => $response, 'order' => $refreshed];
    }

    public function addOrderCommentInMagento(Vendor $vendor, Order $order, VendorStore $store, array $commentData, ?int $userId = null): array
    {
        $status = $commentData['status'] ?? $order->status;
        $response = MagentoService::forVendor($vendor)->post("orders/{$order->magento_order_id}/comments", [
            'statusHistory' => [
                'comment' => $commentData['comment'],
                'status' => $status,
                'is_customer_notified' => (int) ($commentData['is_customer_notified'] ?? 0),
                'is_visible_on_front' => (int) ($commentData['is_visible_on_front'] ?? 0),
            ],
        ]);

        $this->recordOrderHistory($order, $status, $commentData['comment'], $userId, ['magento_response' => $response]);
        $refreshed = $this->refreshLocalOrderFromMagento($vendor, $order, $store);

        return ['message' => 'Order comment added in Magento and synchronized locally', 'magento_response' => $response, 'order' => $refreshed];
    }

    public function refundOrderInMagento(Vendor $vendor, Order $order, VendorStore $store, array $refundData, ?int $userId = null): array
    {
        $response = MagentoService::forVendor($vendor)->post("order/{$order->magento_order_id}/refund", [
            'notify' => (bool) ($refundData['notify'] ?? false),
            'appendComment' => (bool) ($refundData['append_comment'] ?? true),
            'comment' => ['comment' => $refundData['reason'] ?? $refundData['notes'] ?? 'Refund created from admin', 'is_visible_on_front' => 0],
            'arguments' => [
                'shipping_amount' => 0,
                'adjustment_positive' => 0,
                'adjustment_negative' => 0,
                'extension_attributes' => ['return_to_stock_items' => []],
            ],
        ]);

        $this->recordOrderHistory($order, 'refunded', $refundData['reason'] ?? 'Refund created in Magento', $userId, ['amount' => $refundData['amount'], 'magento_response' => $response]);
        $refreshed = $this->refreshLocalOrderFromMagento($vendor, $order, $store);

        return ['message' => 'Refund created in Magento and order synchronized locally', 'magento_response' => $response, 'order' => $refreshed];
    }

    public function reorderInMagento(Vendor $vendor, Order $order, VendorStore $store): array
    {
        $response = MagentoService::forVendor($vendor)->post("orders/{$order->magento_order_id}/reorder");
        $this->recordOrderHistory($order, 'reordered', 'Reorder created in Magento', auth()->id(), ['magento_response' => $response]);
        $syncResult = $this->syncOrdersFromMagento($vendor, [
            'store_uuid' => $store->uuid,
            'magento_store_id' => $store->magento_store_id,
            'max_pages' => 1,
            'only_create_missing' => false,
        ]);

        return ['message' => 'Reorder submitted in Magento and local orders synchronized', 'magento_response' => $response, 'sync' => $syncResult];
    }

    public function createOrderInMagento(Vendor $vendor, array $orderData): array
    {
        return MagentoService::forVendor($vendor)->post('orders/create', $orderData);
    }

    public function updateOrderInMagento(Vendor $vendor, int $orderId, array $orderData): array
    {
        return MagentoService::forVendor($vendor)->post("orders/{$orderId}/comments", ['statusHistory' => $orderData]);
    }

    public function deleteOrderInMagento(Vendor $vendor, int $orderId): array
    {
        return MagentoService::forVendor($vendor)->post("orders/{$orderId}/cancel");
    }

    protected function refreshLocalOrderFromMagento(Vendor $vendor, Order $order, VendorStore $store): Order
    {
        $magentoOrder = MagentoService::forVendor($vendor)->get("orders/{$order->magento_order_id}");
        $this->syncSingleOrder($magentoOrder, $vendor, [
            'store_uuid' => $store->uuid,
            'magento_store_id' => $store->magento_store_id,
            'only_create_missing' => false,
        ]);

        return $order->fresh(['vendor', 'vendorStore', 'customer', 'items', 'tracking', 'statusHistory']);
    }

    protected function recordOrderHistory(Order $order, string $status, ?string $notes = null, ?int $userId = null, array $metadata = []): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => $status,
            'notes' => $notes,
            'changed_by' => $userId,
            'metadata' => $metadata,
        ]);
    }

    protected function upsertLocalTracking(Order $order, array $track): void
    {
        $trackingNumber = $track['track_number'] ?? $track['tracking_number'] ?? null;

        if (!$trackingNumber) {
            return;
        }

        OrderTracking::updateOrCreate(
            ['order_id' => $order->id, 'tracking_number' => $trackingNumber],
            [
                'status' => 'created',
                'last_update' => now(),
                'tracking_events' => [[
                    'status' => 'created',
                    'description' => 'Tracking added in Magento',
                    'timestamp' => now()->toIso8601String(),
                ]],
            ]
        );

        $order->update(['tracking_number' => $trackingNumber]);
    }

    protected function formatMagentoAddress(array $address, string $email): array
    {
        $street = $address['street'] ?? '';

        return [
            'email' => $email,
            'prefix' => $address['prefix'] ?? null,
            'firstname' => $address['firstname'],
            'middlename' => $address['middlename'] ?? null,
            'lastname' => $address['lastname'],
            'suffix' => $address['suffix'] ?? null,
            'company' => $address['company'] ?? null,
            'street' => is_array($street) ? $street : preg_split('/\r\n|\r|\n/', (string) $street),
            'city' => $address['city'],
            'region' => $address['region'],
            'country_id' => $address['country_id'],
            'postcode' => $address['postcode'],
            'telephone' => $address['telephone'] ?? '0000000000',
            'fax' => $address['fax'] ?? null,
            'vat_id' => $address['vat_id'] ?? null,
            'save_in_address_book' => 0,
        ];
    }

    protected function findLatestMagentoOrderForCustomer(MagentoService $magentoService, int $customerId, int $magentoStoreId): ?array
    {
        $response = $magentoService->get('orders', [
            'searchCriteria[filter_groups][0][filters][0][field]' => 'customer_id',
            'searchCriteria[filter_groups][0][filters][0][value]' => $customerId,
            'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'eq',
            'searchCriteria[filter_groups][1][filters][0][field]' => 'store_id',
            'searchCriteria[filter_groups][1][filters][0][value]' => $magentoStoreId,
            'searchCriteria[filter_groups][1][filters][0][condition_type]' => 'eq',
            'searchCriteria[sortOrders][0][field]' => 'created_at',
            'searchCriteria[sortOrders][0][direction]' => 'DESC',
            'searchCriteria[currentPage]' => 1,
            'searchCriteria[pageSize]' => 1,
        ]);

        return $response['items'][0] ?? null;
    }
}
