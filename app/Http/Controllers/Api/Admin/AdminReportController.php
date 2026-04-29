<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Financial\Settlement;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Financial\Refund;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Product\VendorProduct;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(protected ReportService $reportService) {}

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function resolveDateRange(Request $request): array
    {
        $period   = $request->get('period', 'month');
        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');

        if (! $dateFrom) {
            $dateFrom = match ($period) {
                'day'     => now()->startOfDay(),
                'week'    => now()->startOfWeek(),
                'quarter' => now()->firstOfQuarter(),
                'year'    => now()->startOfYear(),
                default   => now()->startOfMonth(),
            };
        }

        if (! $dateTo) {
            $dateTo = match ($period) {
                'day'     => now()->endOfDay(),
                'week'    => now()->endOfWeek(),
                'quarter' => now()->lastOfQuarter(),
                'year'    => now()->endOfYear(),
                default   => now()->endOfMonth(),
            };
        }

        return [$dateFrom, $dateTo, $period];
    }

    private function dateValidationRules(): array
    {
        return [
            'period'    => 'sometimes|in:day,week,month,quarter,year',
            'date_from' => 'required_without:period|date',
            'date_to'   => 'required_without:period|date|after_or_equal:date_from',
        ];
    }

    private function percentageChange(float $previous, float $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function previousPeriod($dateFrom, $dateTo): array
    {
        $from = \Carbon\Carbon::parse($dateFrom);
        $to   = \Carbon\Carbon::parse($dateTo);
        $diff = $from->diffInDays($to) + 1;

        return [$from->copy()->subDays($diff), $to->copy()->subDays($diff)];
    }

    /**
     * Reusable base scope: non-cancelled orders within a date range,
     * optionally scoped to a single vendor.
     */
    private function ordersInPeriod($dateFrom, $dateTo, ?string $vendorId = null)
    {
        return Order::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', '!=', 'cancelled')
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId));
    }

    // -------------------------------------------------------------------------
    // Platform
    // -------------------------------------------------------------------------

    public function platform(Request $request): JsonResponse
    {
        $request->validate($this->dateValidationRules());

        [$dateFrom, $dateTo, $period] = $this->resolveDateRange($request);
        [$prevFrom, $prevTo]          = $this->previousPeriod($dateFrom, $dateTo);

        $stats = Cache::remember(
            "report.platform.{$dateFrom}.{$dateTo}",
            self::CACHE_TTL,
            fn () => $this->reportService->getPlatformPerformance($dateFrom, $dateTo)
        );

        $prevStats = $this->reportService->getPlatformPerformance($prevFrom, $prevTo);

        $trends = collect(['total_orders', 'total_revenue', 'new_customers', 'active_vendors'])
            ->mapWithKeys(fn ($key) => [$key => [
                'current'    => $curr = data_get($stats, $key, 0),
                'previous'   => $prev = data_get($prevStats, $key, 0),
                'change_pct' => $this->percentageChange($prev, $curr),
            ]])->all();

        // Status breakdown via Order model
        $ordersByStatus = Order::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('status, COUNT(*) as count, SUM(grand_total) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Daily revenue chart via Order model
        $dailyRevenue = $this->ordersInPeriod($dateFrom, $dateTo)
            ->selectRaw('DATE(created_at) as date, SUM(grand_total) as revenue, COUNT(*) as orders')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => array_merge((array) $stats, [
                'trends'           => $trends,
                'orders_by_status' => $ordersByStatus,
                'daily_revenue'    => $dailyRevenue,
            ]),
            'meta' => [
                'period'            => $period,
                'date_from'         => $dateFrom,
                'date_to'           => $dateTo,
                'comparison_period' => ['date_from' => $prevFrom, 'date_to' => $prevTo],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Financial
    // -------------------------------------------------------------------------

    public function financial(Request $request): JsonResponse
    {
        $request->validate($this->dateValidationRules());

        [$dateFrom, $dateTo] = $this->resolveDateRange($request);
        [$prevFrom, $prevTo] = $this->previousPeriod($dateFrom, $dateTo);

        $baseOrders = $this->ordersInPeriod($dateFrom, $dateTo);

        // Revenue via Order model
        $revenue = [
            'total'              => (clone $baseOrders)->sum('grand_total'),
            'subtotal'           => (clone $baseOrders)->sum('subtotal'),
            'tax_collected'      => (clone $baseOrders)->sum('tax_amount'),
            'shipping_collected' => (clone $baseOrders)->sum('shipping_amount'),
            'discount_given'     => (clone $baseOrders)->sum('discount_amount'),
            'by_payment_method'  => (clone $baseOrders)
                ->selectRaw('payment_method, SUM(grand_total) as total, COUNT(*) as count')
                ->groupBy('payment_method')
                ->get(),
            'by_currency'        => (clone $baseOrders)
                ->selectRaw('currency_code, SUM(grand_total) as total')
                ->groupBy('currency_code')
                ->get(),
        ];

        // Commission via Order model
        $commission = [
            'total'     => (clone $baseOrders)->sum('commission_amount'),
            'by_vendor' => (clone $baseOrders)
                ->selectRaw('vendor_id, SUM(commission_amount) as total, COUNT(*) as order_count')
                ->with('vendor:id,company_name')
                ->groupBy('vendor_id')
                ->orderByDesc('total')
                ->limit(20)
                ->get(),
        ];

        // Settlements via Settlement model
        $settlements = [
            'total_paid'     => Settlement::whereBetween('paid_at', [$dateFrom, $dateTo])->where('status', 'paid')->sum('net_payout'),
            'total_pending'  => Settlement::where('status', 'pending')->sum('net_payout'),
            'total_approved' => Settlement::where('status', 'approved')->sum('net_payout'),
            'count_pending'  => Settlement::where('status', 'pending')->count(),
            'count_approved' => Settlement::where('status', 'approved')->count(),
            'by_vendor'      => Settlement::whereBetween('created_at', [$dateFrom, $dateTo])
                ->selectRaw('vendor_id, status, SUM(net_payout) as total, COUNT(*) as count')
                ->with('vendor:id,company_name')
                ->groupBy('vendor_id', 'status')
                ->orderByDesc('total')
                ->limit(20)
                ->get(),
        ];

        // Gateway fees via Order model
        $gatewayFees = [
            'total'      => (clone $baseOrders)->sum('payment_fee'),
            'by_gateway' => (clone $baseOrders)
                ->selectRaw('payment_method, SUM(payment_fee) as total, COUNT(*) as transactions')
                ->groupBy('payment_method')
                ->get(),
        ];

        // Refunds via Refund model
        $refundBase  = Refund::whereBetween('created_at', [$dateFrom, $dateTo])->where('status', 'processed');
        $refundTotal = (clone $refundBase)->sum('refund_amount');
        $refundCount = (clone $refundBase)->count();
        $orderCount  = (clone $baseOrders)->count();

        $refunds = [
            'total'       => $refundTotal,
            'count'       => $refundCount,
            'refund_rate' => $orderCount > 0 ? round(($refundCount / $orderCount) * 100, 2) : 0,
            'by_reason'   => (clone $refundBase)
                ->selectRaw('reason, COUNT(*) as count, SUM(refund_amount) as total')
                ->groupBy('reason')
                ->get(),
        ];

        $netProfit        = $revenue['total'] - $gatewayFees['total'] - $refundTotal;
        $platformEarnings = $commission['total'] - $gatewayFees['total'];

        $prevRevenue    = $this->ordersInPeriod($prevFrom, $prevTo)->sum('grand_total');
        $prevCommission = $this->ordersInPeriod($prevFrom, $prevTo)->sum('commission_amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'period'            => compact('dateFrom', 'dateTo'),
                'revenue'           => $revenue,
                'commission'        => $commission,
                'settlements'       => $settlements,
                'gateway_fees'      => $gatewayFees,
                'refunds'           => $refunds,
                'net_profit'        => round($netProfit, 2),
                'platform_earnings' => round($platformEarnings, 2),
                'comparison'        => [
                    'revenue_change_pct'    => $this->percentageChange($prevRevenue, $revenue['total']),
                    'commission_change_pct' => $this->percentageChange($prevCommission, $commission['total']),
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Vendor Performance
    // -------------------------------------------------------------------------

    public function vendorPerformance(Request $request, $vendorId = null): JsonResponse
    {
        $request->validate(array_merge($this->dateValidationRules(), [
            'sort_by' => 'sometimes|in:revenue,order_count,commission,average_order_value',
        ]));

        [$dateFrom, $dateTo] = $this->resolveDateRange($request);
        $sortBy              = $request->get('sort_by', 'revenue');

        $vendors = Vendor::with('user:id,email')
            ->when($vendorId, fn ($q) => $q->whereKey($vendorId))
            ->get();

        if ($vendors->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Vendor not found.'], 404);
        }

        $performance = $vendors->map(function (Vendor $vendor) use ($dateFrom, $dateTo) {
            $vid        = $vendor->getKey();
            $baseOrders = $this->ordersInPeriod($dateFrom, $dateTo, $vid);

            $orderCount = (clone $baseOrders)->count();
            $revenue    = (clone $baseOrders)->sum('grand_total');
            $commission = (clone $baseOrders)->sum('commission_amount');

            // Status breakdown via Order model
            $statusBreakdown = Order::where('vendor_id', $vid)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            // Top products via OrderItem → vendorProduct relationship
            $topProducts = OrderItem::whereHas('order', fn ($q) =>
                    $q->where('vendor_id', $vid)
                      ->whereBetween('created_at', [$dateFrom, $dateTo])
                      ->where('status', '!=', 'cancelled')
                )
                ->with('vendorProduct:id,name,sku')
                ->selectRaw('vendor_product_id, SUM(qty_ordered) as quantity_sold, SUM(row_total) as revenue')
                ->groupBy('vendor_product_id')
                ->orderByDesc('revenue')
                ->limit(5)
                ->get()
                ->map(fn ($item) => [
                    'id'            => $item->vendor_product_id,
                    'name'          => $item->vendorProduct?->name,
                    'sku'           => $item->vendorProduct?->sku,
                    'quantity_sold' => (int) $item->quantity_sold,
                    'revenue'       => round($item->revenue, 2),
                ]);

            // Refunds via Refund model scoped through the order relationship
            $refundAmount = Refund::where('status', 'processed')
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->whereHas('order', fn ($q) => $q->where('vendor_id', $vid))
                ->sum('refund_amount');

            $settlementPaid = Settlement::where('vendor_id', $vid)
                ->whereBetween('paid_at', [$dateFrom, $dateTo])
                ->where('status', 'paid')
                ->sum('net_payout');

            $settlementPending = Settlement::where('vendor_id', $vid)
                ->where('status', 'pending')
                ->sum('net_payout');

            // Products sold count via OrderItem model
            $productsSold = OrderItem::whereHas('order', fn ($q) =>
                    $q->where('vendor_id', $vid)
                      ->whereBetween('created_at', [$dateFrom, $dateTo])
                      ->where('status', '!=', 'cancelled')
                )->sum('qty_ordered');

            return [
                'vendor' => [
                    'id'           => $vid,
                    'company_name' => $vendor->company_name,
                    'email'        => $vendor->user?->email,
                ],
                'revenue'             => round($revenue, 2),
                'order_count'         => $orderCount,
                'average_order_value' => $orderCount > 0 ? round($revenue / $orderCount, 2) : 0,
                'commission'          => round($commission, 2),
                'commission_rate'     => $revenue > 0 ? round(($commission / $revenue) * 100, 2) : 0,
                'products_sold'       => (int) $productsSold,
                'refund_amount'       => round($refundAmount, 2),
                'refund_rate'         => $revenue > 0 ? round(($refundAmount / $revenue) * 100, 2) : 0,
                'settlement_paid'     => round($settlementPaid, 2),
                'settlement_pending'  => round($settlementPending, 2),
                'status_breakdown'    => $statusBreakdown,
                'top_products'        => $topProducts,
            ];
        });

        $sorted = $performance->sortByDesc($sortBy)->values();

        return response()->json([
            'success' => true,
            'data'    => $sorted,
            'meta'    => [
                'total_vendors'    => $sorted->count(),
                'total_revenue'    => round($sorted->sum('revenue'), 2),
                'total_commission' => round($sorted->sum('commission'), 2),
                'total_refunds'    => round($sorted->sum('refund_amount'), 2),
                'sort_by'          => $sortBy,
                'date_from'        => $dateFrom,
                'date_to'          => $dateTo,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Product Performance
    // -------------------------------------------------------------------------

    public function productPerformance(Request $request): JsonResponse
    {
        $request->validate(array_merge($this->dateValidationRules(), [
            'vendor_id' => 'nullable|exists:vendors,uuid',
            'limit'     => 'nullable|integer|min:1|max:100',
            'sort_by'   => 'sometimes|in:revenue,quantity_sold,order_count',
        ]));

        [$dateFrom, $dateTo] = $this->resolveDateRange($request);
        $limit               = $request->get('limit', 50);
        $sortBy              = $request->get('sort_by', 'revenue');

        // Eloquent withSum/withCount does not support dot-notation (e.g. orderItems.refunds)
        // because that would require chaining through two relationships in one aggregate call.
        // Strategy:
        //   1. Aggregate order-item metrics on VendorProduct via withSum/withAvg/withCount.
        //   2. Collect the matched product IDs, then fetch refund totals in a single
        //      Refund query grouped by order_item's vendor_product_id and merge the results.

        $orderScope = fn ($q) =>
            $q->whereHas('order', fn ($o) =>
                $o->whereBetween('created_at', [$dateFrom, $dateTo])
                  ->where('status', '!=', 'cancelled')
                  ->when($request->vendor_id, fn ($v) => $v->where('vendor_id', $request->vendor_id))
            );

        $products = VendorProduct::with('vendor:id,company_name')
            ->withSum(['orderItems as quantity_sold' => $orderScope], 'qty_ordered')
            ->withSum(['orderItems as revenue'        => $orderScope], 'row_total')
            ->withSum(['orderItems as tax_collected'  => $orderScope], 'tax_amount')
            ->withSum(['orderItems as total_discount' => $orderScope], 'discount_amount')
            ->withAvg(['orderItems as average_price'  => $orderScope], 'price')
            ->withCount(['orderItems as order_count'  => $orderScope])
            ->having('quantity_sold', '>', 0)
            ->orderByDesc($sortBy)
            ->limit($limit)
            ->get();

        // Fetch refund totals for these products in one query via Refund → OrderItem
        $productIds   = $products->modelKeys();
        $refundTotals = Refund::where('status', 'processed')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereHas('orderItem', fn ($q) => $q->whereIn('vendor_product_id', $productIds))
            ->with('orderItem:id,vendor_product_id')
            ->get()
            ->groupBy(fn ($r) => $r->orderItem?->vendor_product_id)
            ->map(fn ($group) => [
                'refund_amount' => round($group->sum('refund_amount'), 2),
                'refund_count'  => $group->count(),
            ]);

        $products = $products->map(fn (VendorProduct $p) => [
            'id'             => $p->getKey(),
            'name'           => $p->name,
            'sku'            => $p->sku,
            'vendor_name'    => $p->vendor?->company_name,
            'quantity_sold'  => (int) ($p->quantity_sold ?? 0),
            'revenue'        => round($p->revenue ?? 0, 2),
            'average_price'  => round($p->average_price ?? 0, 2),
            'order_count'    => (int) ($p->order_count ?? 0),
            'tax_collected'  => round($p->tax_collected ?? 0, 2),
            'total_discount' => round($p->total_discount ?? 0, 2),
            'refund_amount'  => $refundTotals->get($p->getKey())['refund_amount'] ?? 0,
            'refund_count'   => $refundTotals->get($p->getKey())['refund_count'] ?? 0,
        ]);

        $summary = [
            'total_products'      => $products->count(),
            'total_quantity_sold' => $products->sum('quantity_sold'),
            'total_revenue'       => round($products->sum('revenue'), 2),
            'average_price'       => round($products->avg('average_price'), 2),
            'top_product'         => $products->first(),
        ];

        return response()->json([
            'success' => true,
            'data'    => $products,
            'summary' => $summary,
            'meta'    => [
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
                'limit'     => $limit,
                'sort_by'   => $sortBy,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Customer Report
    // -------------------------------------------------------------------------

    public function customerReport(Request $request): JsonResponse
    {
        $request->validate(array_merge($this->dateValidationRules(), [
            'limit' => 'nullable|integer|min:1|max:100',
        ]));

        [$dateFrom, $dateTo] = $this->resolveDateRange($request);
        [$prevFrom, $prevTo] = $this->previousPeriod($dateFrom, $dateTo);
        $limit               = $request->get('limit', 50);

        $activeOrderScope = fn ($q) =>
            $q->whereBetween('created_at', [$dateFrom, $dateTo])->where('status', '!=', 'cancelled');

        // Acquisition via User model
        $newCustomers = User::where('user_type', 'customer')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->count();

        // Top customers via User → orders relationship
        $topCustomers = User::where('user_type', 'customer')
            ->withSum(['orders as total_spent'         => $activeOrderScope], 'grand_total')
            ->withCount(['orders as order_count'        => $activeOrderScope])
            ->withAvg(['orders as average_order_value'  => $activeOrderScope], 'grand_total')
            ->withMax(['orders as last_order_at'        => $activeOrderScope], 'created_at')
            ->having('order_count', '>', 0)
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get()
            ->map(fn (User $u) => [
                'customer_id'         => $u->getKey(),
                'customer_name'       => $u->full_name,
                'customer_email'      => $u->email,
                'customer_since'      => $u->created_at?->toDateString(),
                'order_count'         => (int) $u->order_count,
                'total_spent'         => round($u->total_spent ?? 0, 2),
                'average_order_value' => round($u->average_order_value ?? 0, 2),
                'last_order_at'       => $u->last_order_at,
            ]);

        // Retention — whereHas counts via User model
        $totalCustomers  = User::where('user_type', 'customer')->whereHas('orders', $activeOrderScope)->count();
        $repeatCustomers = User::where('user_type', 'customer')->whereHas('orders', $activeOrderScope, '>=', 2)->count();
        $repeatRate      = $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 2) : 0;

        // Lifetime value via User → orders
        $ltv = User::where('user_type', 'customer')
            ->withSum(['orders as lifetime_value' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'grand_total')
            ->having('lifetime_value', '>', 0)
            ->avg('lifetime_value');

        // Churn: customers in previous period but not current
        $prevScope    = fn ($q) => $q->whereBetween('created_at', [$prevFrom, $prevTo])->where('status', '!=', 'cancelled');
        $prevIds      = User::where('user_type', 'customer')->whereHas('orders', $prevScope)->pluck('id');
        $currentIds   = User::where('user_type', 'customer')->whereHas('orders', $activeOrderScope)->pluck('id');
        $churnedCount = $prevIds->diff($currentIds)->count();
        $churnRate    = $prevIds->count() > 0 ? round(($churnedCount / $prevIds->count()) * 100, 2) : 0;

        // Cohort by user registration month via User model
        $cohortData = User::where('user_type', 'customer')
            ->whereHas('orders', $activeOrderScope)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as cohort_month, COUNT(*) as customers")
            ->groupBy('cohort_month')
            ->orderBy('cohort_month')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'new_customers'  => $newCustomers,
                'top_customers'  => $topCustomers,
                'retention'      => [
                    'repeat_customers' => $repeatCustomers,
                    'total_customers'  => $totalCustomers,
                    'repeat_rate'      => $repeatRate,
                ],
                'churn'          => ['churned_customers' => $churnedCount, 'churn_rate' => $churnRate],
                'lifetime_value' => round($ltv ?? 0, 2),
                'cohort_data'    => $cohortData,
                'period'         => compact('dateFrom', 'dateTo'),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Sales
    // -------------------------------------------------------------------------

    public function sales(Request $request): JsonResponse
    {
        $request->validate(array_merge($this->dateValidationRules(), [
            'vendor_id'   => 'nullable|exists:vendors,uuid',
            'granularity' => 'sometimes|in:hour,day,week,month',
        ]));

        [$dateFrom, $dateTo, $period] = $this->resolveDateRange($request);
        $granularity                  = $request->get('granularity', 'day');

        $groupFormat = match ($granularity) {
            'hour'  => '%Y-%m-%d %H:00:00',
            'week'  => '%x-W%v',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $baseOrders = $this->ordersInPeriod($dateFrom, $dateTo, $request->vendor_id);

        // Time series via Order model
        $timeSeries = (clone $baseOrders)
            ->selectRaw("
                DATE_FORMAT(created_at, '{$groupFormat}') as period,
                COUNT(*) as order_count,
                ROUND(SUM(grand_total), 2) as revenue,
                ROUND(SUM(subtotal), 2) as subtotal,
                ROUND(SUM(tax_amount), 2) as tax,
                ROUND(SUM(shipping_amount), 2) as shipping,
                ROUND(SUM(discount_amount), 2) as discount,
                ROUND(SUM(commission_amount), 2) as commission,
                ROUND(AVG(grand_total), 2) as aov
            ")
            ->groupByRaw("DATE_FORMAT(created_at, '{$groupFormat}')")
            ->orderBy('period')
            ->get();

        $orderCount = (clone $baseOrders)->count();
        $totals = [
            'order_count' => $orderCount,
            'revenue'     => round((clone $baseOrders)->sum('grand_total'), 2),
            'subtotal'    => round((clone $baseOrders)->sum('subtotal'), 2),
            'tax'         => round((clone $baseOrders)->sum('tax_amount'), 2),
            'shipping'    => round((clone $baseOrders)->sum('shipping_amount'), 2),
            'discount'    => round((clone $baseOrders)->sum('discount_amount'), 2),
            'commission'  => round((clone $baseOrders)->sum('commission_amount'), 2),
            'aov'         => $orderCount > 0 ? round((clone $baseOrders)->avg('grand_total'), 2) : 0,
        ];

        // Sales by product via OrderItem → vendorProduct relationship
        // Category is intentionally omitted — owned by Magento via service layer
        $salesByProduct = OrderItem::whereHas('order', fn ($q) =>
                $q->whereBetween('created_at', [$dateFrom, $dateTo])
                  ->where('status', '!=', 'cancelled')
                  ->when($request->vendor_id, fn ($v) => $v->where('vendor_id', $request->vendor_id))
            )
            ->with('vendorProduct:id,name,sku')
            ->selectRaw('vendor_product_id, SUM(qty_ordered) as quantity_sold, SUM(row_total) as revenue, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('vendor_product_id')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get()
            ->map(fn ($item) => [
                'vendor_product_id' => $item->vendor_product_id,
                'name'              => $item->vendorProduct?->name,
                'sku'               => $item->vendorProduct?->sku,
                'quantity_sold'     => (int) $item->quantity_sold,
                'revenue'           => round($item->revenue, 2),
                'order_count'       => (int) $item->order_count,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'totals'           => $totals,
                'time_series'      => $timeSeries,
                'sales_by_product' => $salesByProduct,
            ],
            'meta' => [
                'period'      => $period,
                'granularity' => $granularity,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'vendor_id'   => $request->vendor_id,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    public function export(Request $request): JsonResponse
    {
        $request->validate([
            'report_type' => 'required|in:platform,financial,vendor_performance,product_performance,customer_report,sales',
            'format'      => 'sometimes|in:csv,excel',
            'date_from'   => 'required|date',
            'date_to'     => 'required|date|after_or_equal:date_from',
            'vendor_id'   => 'nullable|exists:vendors,uuid',
        ]);

        $dateFrom   = $request->date_from;
        $dateTo     = $request->date_to;
        $reportType = $request->report_type;
        $format     = $request->get('format', 'csv');

        $data = match ($reportType) {
            'platform'            => (array) $this->reportService->getPlatformPerformance($dateFrom, $dateTo),
            'financial'           => $this->getFinancialReportData($dateFrom, $dateTo),
            'vendor_performance'  => $this->getVendorPerformanceData($dateFrom, $dateTo),
            'product_performance' => $this->getProductPerformanceData($dateFrom, $dateTo),
            'customer_report'     => $this->getCustomerReportData($dateFrom, $dateTo),
            'sales'               => $this->getSalesReportData($dateFrom, $dateTo),
            default               => [],
        };

        $filename = "{$reportType}_report_" . date('Y-m-d_His') . ".{$format}";
        $path     = "exports/{$filename}";
        $content  = $this->arrayToCsv($data);

        Storage::put($path, $content);

        $jobId = uniqid('export_', true);
        Cache::put("export_job_{$jobId}", $path, now()->addHours(2));

        return response()->json([
            'success' => true,
            'data'    => [
                'job_id'       => $jobId,
                'filename'     => $filename,
                'download_url' => route('admin.reports.download', ['jobId' => $jobId]),
                'expires_at'   => now()->addHours(2)->toDateTimeString(),
                'content'      => base64_encode($content),
                'mime_type'    => 'text/csv',
            ],
        ]);
    }

    public function downloadExport($jobId): StreamedResponse|JsonResponse
    {
        $path = Cache::get("export_job_{$jobId}");

        if (! $path || ! Storage::exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Export not found or has expired.',
                'job_id'  => $jobId,
            ], 404);
        }

        $filename = basename($path);
        $mimeType = str_ends_with($filename, '.csv') ? 'text/csv' : 'application/octet-stream';

        return Storage::download($path, $filename, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // -------------------------------------------------------------------------
    // Private export data helpers
    // -------------------------------------------------------------------------

    private function getFinancialReportData($dateFrom, $dateTo): array
    {
        return $this->ordersInPeriod($dateFrom, $dateTo)
            ->selectRaw("
                DATE(created_at) as date,
                payment_method,
                currency_code,
                COUNT(*) as order_count,
                ROUND(SUM(grand_total), 2) as revenue,
                ROUND(SUM(commission_amount), 2) as commission,
                ROUND(SUM(payment_fee), 2) as gateway_fee,
                ROUND(SUM(tax_amount), 2) as tax,
                ROUND(SUM(discount_amount), 2) as discount,
                ROUND(SUM(shipping_amount), 2) as shipping
            ")
            ->groupByRaw('DATE(created_at), payment_method, currency_code')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    private function getVendorPerformanceData($dateFrom, $dateTo): array
    {
        return $this->ordersInPeriod($dateFrom, $dateTo)
            ->with('vendor:id,company_name')
            ->selectRaw("
                vendor_id,
                COUNT(*) as order_count,
                ROUND(SUM(grand_total), 2) as revenue,
                ROUND(SUM(commission_amount), 2) as commission,
                ROUND(AVG(grand_total), 2) as aov
            ")
            ->groupBy('vendor_id')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'vendor_id'   => $r->vendor_id,
                'vendor_name' => $r->vendor?->company_name,
                'order_count' => $r->order_count,
                'revenue'     => $r->revenue,
                'commission'  => $r->commission,
                'aov'         => $r->aov,
            ])
            ->toArray();
    }

    private function getProductPerformanceData($dateFrom, $dateTo): array
    {
        return OrderItem::whereHas('order', fn ($o) =>
                $o->whereBetween('created_at', [$dateFrom, $dateTo])->where('status', '!=', 'cancelled')
            )
            ->with('vendorProduct:id,name,sku')
            ->selectRaw('vendor_product_id, SUM(qty_ordered) as quantity_sold, ROUND(SUM(row_total), 2) as revenue, ROUND(AVG(price), 2) as average_price, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('vendor_product_id')
            ->orderByDesc('revenue')
            ->limit(500)
            ->get()
            ->map(fn ($item) => [
                'sku'           => $item->vendorProduct?->sku,
                'product_name'  => $item->vendorProduct?->name,
                'quantity_sold' => $item->quantity_sold,
                'revenue'       => $item->revenue,
                'average_price' => $item->average_price,
                'order_count'   => $item->order_count,
            ])
            ->toArray();
    }

    private function getCustomerReportData($dateFrom, $dateTo): array
    {
        $activeOrderScope = fn ($q) =>
            $q->whereBetween('created_at', [$dateFrom, $dateTo])->where('status', '!=', 'cancelled');

        return User::where('user_type', 'customer')
            ->withSum(['orders as total_spent'  => $activeOrderScope], 'grand_total')
            ->withCount(['orders as order_count' => $activeOrderScope])
            ->having('order_count', '>', 0)
            ->orderByDesc('total_spent')
            ->limit(500)
            ->get()
            ->map(fn (User $u) => [
                'customer_id'    => $u->getKey(),
                'customer_name'  => $u->full_name,
                'email'          => $u->email,
                'order_count'    => $u->order_count,
                'total_spent'    => round($u->total_spent ?? 0, 2),
                'customer_since' => $u->created_at?->toDateString(),
            ])
            ->toArray();
    }

    private function getSalesReportData($dateFrom, $dateTo): array
    {
        return $this->ordersInPeriod($dateFrom, $dateTo)
            ->selectRaw("
                DATE(created_at) as date,
                COUNT(*) as order_count,
                ROUND(SUM(grand_total), 2) as revenue,
                ROUND(SUM(tax_amount), 2) as tax,
                ROUND(SUM(shipping_amount), 2) as shipping,
                ROUND(SUM(discount_amount), 2) as discount,
                ROUND(SUM(commission_amount), 2) as commission,
                ROUND(AVG(grand_total), 2) as aov
            ")
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    private function arrayToCsv(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $handle = fopen('php://temp', 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel compatibility

        fputcsv($handle, array_keys((array) $data[0]));
        foreach ($data as $row) {
            fputcsv($handle, array_values((array) $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
