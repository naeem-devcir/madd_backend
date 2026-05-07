<?php

namespace App\Services\Vendor;

use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Models\Vendor\VendorPlan;
use App\Models\Config\SalesPolicy;
use App\Models\Config\Domain;
use App\Models\Order\Order;
use App\Services\Integration\MagentoService;
use App\Services\Store\AdminStoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class VendorService
{
    protected $magentoService;
    protected AdminStoreService $adminStoreService;

    public function __construct(MagentoService $magentoService, AdminStoreService $adminStoreService)
    {
        $this->magentoService = $magentoService;
        $this->adminStoreService = $adminStoreService;
    }

    /**
     * Approve vendor application
     */
    public function approveVendor(Vendor $vendor, User $approver): void
    {
        DB::beginTransaction();

        try {
            // Create Magento website and store
            $magentoData = $this->magentoService->createVendorStore($vendor);

            $vendor->update([
                'status' => 'active',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'magento_website_id' => $magentoData['website_id'],
            ]);

            // Create default store for vendor
            $this->createDefaultStore($vendor);

            DB::commit();

            // Send approval notification
            // \App\Jobs\Notification\SendVendorApprovalNotification::dispatch($vendor);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create default store for vendor
     */
    public function createDefaultStore(Vendor $vendor): VendorStore
    {
        $store = VendorStore::create([
            'vendor_id' => $vendor->id,  // ✅ FIXED - Integer ID
            'store_name' => $vendor->company_name,
            'store_slug' => $vendor->company_slug,
            'country_code' => $vendor->country_code,
            'currency_code' => $vendor->currency_code ?? 'EUR',
            'status' => 'active',
            'contact_email' => $vendor->user->email,
            'activated_at' => now(),
        ]);

        // Set default sales policy
        $defaultPolicy = SalesPolicy::where('country_code', $vendor->country_code)
            ->where('is_active', true)
            ->first();

        if ($defaultPolicy) {
            $store->sales_policy_id = $defaultPolicy->id;
            $store->save();
        }

        return $store;
    }

    /**
     * Create store for vendor
     */
    public function createStore(Vendor $vendor, array $data): VendorStore
    {
        if (!$vendor->canAddStore()) {
            throw new \Exception('Store limit reached for your plan');
        }

        return $this->adminStoreService->create(array_merge($data, [
            'vendor_id' => $vendor->id,
            'store_slug' => $data['store_slug'] ?? Str::slug($data['store_name']),
            'status' => $data['status'] ?? 'inactive',
        ]));
    }

    /**
     * Create subdomain for store
     */
    public function createSubdomain(VendorStore $store, string $subdomain): Domain
    {
        $fullDomain = $subdomain . '.' . config('app.main_domain', 'madd.eu');

        $domain = Domain::create([
            'vendor_store_id' => $store->id,  // ✅ FIXED - Integer ID (store ki id)
            'domain' => $fullDomain,
            'type' => 'madd_subdomain',
            'subdomain' => $subdomain,
            'is_primary' => true,
            'dns_verified' => true,
            'ssl_status' => 'active',
        ]);

        $store->forceFill(['domain_id' => $domain->id])->save();

        return $domain;
    }

    /**
     * Calculate vendor commission for order
     */
    public function calculateCommission(Vendor $vendor, float $amount): float
    {
        $rate = $vendor->effective_commission_rate;
        return round($amount * ($rate / 100), 2);
    }

    /**
     * Get vendor performance metrics
     */
    public function getPerformanceMetrics(Vendor $vendor, string $period = 'month'): array
    {
        $startDate = match($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };

        $orders = $vendor->orders()
            ->where('created_at', '>=', $startDate)
            ->where('status', '!=', 'cancelled')
            ->get();

        return [
            'total_revenue' => $orders->sum('grand_total'),
            'total_orders' => $orders->count(),
            'average_order_value' => $orders->avg('grand_total') ?? 0,
            'total_commission' => $orders->sum('commission_amount'),
            'total_products_sold' => $orders->sum(function($order) {
                return $order->items->sum('qty_ordered');
            }),
            'top_products' => $this->getTopProducts($vendor, $startDate),
            'daily_sales' => $this->getDailySales($vendor, $startDate),
        ];
    }

    /**
     * Get top products for vendor
     */
    private function getTopProducts(Vendor $vendor, $startDate)
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
            ->where('orders.vendor_id', $vendor->id)  // ✅ FIXED - Integer ID
            ->where('orders.created_at', '>=', $startDate)
            ->where('orders.status', '!=', 'cancelled')
            ->select('vendor_products.name', DB::raw('SUM(order_items.qty_ordered) as quantity'), DB::raw('SUM(order_items.row_total) as revenue'))
            ->groupBy('vendor_products.id', 'vendor_products.name')
            ->orderBy('revenue', 'desc')
            ->limit(10)
            ->get();
    }

    /**
     * Get daily sales for vendor
     */
    private function getDailySales(Vendor $vendor, $startDate)
    {
        return Order::where('vendor_id', $vendor->id)  // ✅ FIXED - Integer ID
            ->where('created_at', '>=', $startDate)
            ->where('status', '!=', 'cancelled')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(grand_total) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }
}
