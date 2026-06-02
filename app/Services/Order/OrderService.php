<?php

namespace App\Services\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\OrderStatusHistory;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Models\Product\VendorProduct;
use App\Models\User;
use App\Services\Integration\MagentoService;
use App\Services\Vendor\CommissionService;
use App\Events\Order\OrderCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

class OrderService
{
    protected CommissionService $commissionService;

    public function __construct(CommissionService $commissionService)
    {
        $this->commissionService = $commissionService;
    }

    // ─────────────────────────────────────────────────────
    // DATABASE QUERY METHODS (READ OPERATIONS)
    // ─────────────────────────────────────────────────────

    /**
     * Get orders from local database with filters
     * 
     * @param string|null $vendorUuid - Vendor UUID
     * @param string|null $storeUuid - Vendor Store UUID
     * @param array $filters - Additional filters (status, date range, etc.)
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getOrdersFromDatabase(
        ?string $vendorUuid = null,
        ?string $storeUuid = null,
        array $filters = [],
        int $perPage = 15
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {

        $query = Order::with(['vendor', 'vendorStore', 'customer', 'items']);

        // Filter by vendor UUID
        if ($vendorUuid) {
            $query->whereHas('vendor', function (Builder $q) use ($vendorUuid) {
                $q->where('uuid', $vendorUuid);
            });
        }

        // Filter by store UUID
        if ($storeUuid) {
            $query->whereHas('vendorStore', function (Builder $q) use ($storeUuid) {
                $q->where('uuid', $storeUuid);
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
     * @param string $orderId - Order UUID or ID or increment ID
     * @param string|null $vendorUuid - Vendor UUID for authorization
     * @param string|null $storeUuid - Store UUID for authorization
     * @return Order|null
     */
    public function getOrderFromDatabase(
        string $orderId,
        ?string $vendorUuid = null,
        ?string $storeUuid = null
    ): ?Order {
        $query = Order::with([
            'vendor',
            'vendorStore',
            'customer',
            'items',
            'items.vendorProduct',
            'statusHistory',
        ]);

        // Apply authorization filters
        if ($vendorUuid) {
            $query->whereHas('vendor', function (Builder $q) use ($vendorUuid) {
                $q->where('uuid', $vendorUuid);
            });
        }

        if ($storeUuid) {
            $query->whereHas('vendorStore', function (Builder $q) use ($storeUuid) {
                $q->where('uuid', $storeUuid);
            });
        }

        return $query->where(function ($q) use ($orderId) {
            $q->where('uuid', $orderId)
                ->orWhere('id', $orderId)
                ->orWhere('magento_order_increment_id', $orderId);
        })
            ->first();
    }

    /**
     * Get order statistics from local database
     * 
     * @param string|null $vendorUuid
     * @param string|null $storeUuid
     * @param string $period
     * @return array
     */
    public function getOrderStatisticsFromDatabase(
        ?string $vendorUuid = null,
        ?string $storeUuid = null,
        string $period = '30_days'
    ): array {
        $query = Order::query();

        if ($vendorUuid) {
            $query->whereHas('vendor', function (Builder $q) use ($vendorUuid) {
                $q->where('uuid', $vendorUuid);
            });
        }

        if ($storeUuid) {
            $query->whereHas('vendorStore', function (Builder $q) use ($storeUuid) {
                $q->where('uuid', $storeUuid);
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

    // ─────────────────────────────────────────────────────
    // SYNC METHODS (FETCH FROM MAGENTO, UPDATE LOCAL DB)
    // ─────────────────────────────────────────────────────

    /**
     * Sync orders from Magento to local database
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
        $maxPages = $options['max_pages'] ?? 5;

        $syncedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $errors = [];

        try {
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
     * @param array $options
     * @return array
     */
    protected function syncSingleOrder(array $magentoOrder, Vendor $vendor, array $options = []): array
    {
        // Filter by store if specified
        if (!empty($options['magento_store_id']) && (int) ($magentoOrder['store_id'] ?? 0) !== (int) $options['magento_store_id']) {
            return ['action' => 'skipped', 'reason' => 'store_mismatch'];
        }

        // Check if order already exists
        $existingOrder = Order::where('magento_order_id', $magentoOrder['entity_id'])
            ->orWhere('magento_order_increment_id', $magentoOrder['increment_id'])
            ->first();

        if ($existingOrder) {
            return $this->updateExistingOrder($existingOrder, $magentoOrder);
        }

        return $this->createNewOrder($magentoOrder, $vendor, $options);
    }

    /**
     * Create new order from Magento data
     * 
     * @param array $magentoOrder
     * @param Vendor $vendor
     * @param array $options
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

            // Find customer if exists
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
            event(new \App\Events\Events\Order\OrderStatusChanged($order, $oldStatus, $newStatus));
        }

        Log::info('Order updated from Magento sync', [
            'order_id' => $order->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);

        return ['action' => 'updated', 'order' => $order];
    }

    // ─────────────────────────────────────────────────────
    // MANUAL ORDER CREATION METHODS
    // ─────────────────────────────────────────────────────

    /**
     * Create manual order in Magento and sync to local database
     * 
     * @param Vendor $vendor
     * @param VendorStore $store
     * @param array $orderData
     * @param int|null $userId
     * @return array
     * @throws \Exception
     */
    public function createManualOrderInMagento(Vendor $vendor, VendorStore $store, array $orderData, ?int $userId = null): array
    {
        $magentoService = MagentoService::forVendor($vendor);
        $customerId = (int) data_get($orderData, 'customer.id');

        if (empty($store->magento_store_id)) {
            throw new \Exception('Selected store is not linked to a Magento store');
        }

        DB::beginTransaction();

        try {
            // Step 1: Create cart for customer
            $cartIdResponse = $magentoService->post("customers/{$customerId}/carts");
            $cartId = $cartIdResponse['value'] ?? $cartIdResponse['id'] ?? $cartIdResponse['cart_id'] ?? null;

            if (!$cartId && is_numeric($cartIdResponse[0] ?? null)) {
                $cartId = $cartIdResponse[0];
            }

            if (!$cartId) {
                throw new \Exception('Magento did not return a cart ID for the selected customer');
            }

            // Step 2: Add items to cart
            foreach ($orderData['items'] as $item) {
                $magentoService->post("carts/{$cartId}/items", [
                    'cartItem' => [
                        'sku' => $item['sku'],
                        'qty' => (int) $item['qty'],
                        'quote_id' => (string) $cartId,
                    ],
                ]);
            }

            // Step 3: Apply coupon if provided
            if (!empty($orderData['coupon_code'])) {
                try {
                    $magentoService->put("carts/{$cartId}/coupons/" . rawurlencode($orderData['coupon_code']), []);
                } catch (\Exception $e) {
                    \Log::warning('Failed to apply coupon', [
                        'cart_id' => $cartId,
                        'coupon' => $orderData['coupon_code'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Step 4: Format addresses
            $shippingAddress = $this->formatMagentoAddress($orderData['shipping_address'], $orderData['customer']['email']);
            $billingAddress = $this->formatMagentoAddress($orderData['billing_address'], $orderData['customer']['email']);

            // Step 5: Set shipping information
            $shippingInfo = $magentoService->post("carts/{$cartId}/shipping-information", [
                'addressInformation' => [
                    'shipping_address' => $shippingAddress,
                    'billing_address' => $billingAddress,
                    'shipping_carrier_code' => $orderData['shipping_method']['carrier_code'],
                    'shipping_method_code' => $orderData['shipping_method']['method_code'],
                ],
            ]);

            // ✅ Step 6: Set payment method using the correct endpoint
            // Use PUT request to set selected payment method
            $paymentResponse = $magentoService->put("carts/{$cartId}/selected-payment-method", [
                'method' => $orderData['payment_method'],
            ]);

            // Step 7: Place the order (convert cart to order)
            $createdOrderResponse = $magentoService->put("carts/{$cartId}/order", []);

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

            // Sync the created order to local database
            $syncResult = $this->syncSingleOrder($magentoOrder, $vendor, [
                'store_uuid' => $store->uuid,
                'magento_store_id' => $store->magento_store_id,
            ]);

            $localOrder = $syncResult['order'] ?? Order::where('magento_order_id', $magentoOrder['entity_id'])->first();

            // Add history comment if provided
            if ($localOrder && !empty($orderData['history']['append_comment']) && !empty($orderData['history']['comment'])) {
                $this->recordOrderHistory($localOrder, $localOrder->status, $orderData['history']['comment'], $userId, [
                    'email_confirmation' => (bool) ($orderData['history']['email_confirmation'] ?? false),
                    'source' => 'manual_order_create',
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'cart_id' => $cartId,
                'magento_order_id' => $magentoOrder['entity_id'],
                'magento_order_increment_id' => $magentoOrder['increment_id'] ?? null,
                'shipping_information' => $shippingInfo,
                'payment_response' => $paymentResponse,
                'order_place_response' => $createdOrderResponse,
                'sync' => $syncResult,
                'order' => $localOrder,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────
    // HELPER METHODS
    // ─────────────────────────────────────────────────────

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

        // Filter by Magento store
        if (!empty($options['magento_store_id'])) {
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][field]"] = 'store_id';
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][value]"] = $options['magento_store_id'];
            $criteria["searchCriteria[filter_groups][{$filterIndex}][filters][0][condition_type]"] = 'eq';
            $filterIndex++;
        }

        // Filter by date range
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

        // Sort by created_at descending
        $criteria["searchCriteria[sortOrders][0][field]"] = 'created_at';
        $criteria["searchCriteria[sortOrders][0][direction]"] = 'DESC';

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
        $orderStatus = strtolower($magentoOrder['status'] ?? '');

        if (in_array($orderStatus, ['canceled', 'cancelled'])) {
            return 'refunded';
        }

        if ($orderStatus === 'closed') {
            return 'paid';
        }

        // Check payment information
        if (isset($magentoOrder['payment']['additional_information'])) {
            $paymentInfo = $magentoOrder['payment']['additional_information'];
            if (is_array($paymentInfo) && in_array('captured', $paymentInfo)) {
                return 'paid';
            }
        }

        if (in_array($orderStatus, ['pending', 'pending_payment'])) {
            return 'pending';
        }

        if (in_array($orderStatus, ['processing', 'complete', 'closed'])) {
            return 'paid';
        }

        return 'pending';
    }

    /**
     * Format address for Magento API
     * 
     * @param array $address
     * @param string $email
     * @return array
     */
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

    /**
     * Find the most recent Magento order for a customer
     * 
     * @param MagentoService $magentoService
     * @param int $customerId
     * @param int $magentoStoreId
     * @return array|null
     */
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

    /**
     * Record order status history
     * 
     * @param Order $order
     * @param string $status
     * @param string|null $notes
     * @param int|null $userId
     * @param array $metadata
     * @return void
     */
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
}
