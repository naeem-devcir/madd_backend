<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Product\VendorProduct;
use App\Models\Financial\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorDashboardController extends Controller
{
    /**
     * Get vendor dashboard data
     */
    public function index(Request $request)
    {
        $vendor = $request->user()->vendor;

        // Get current period (this month)
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        // Previous period (last month)
        $startOfLastMonth = now()->subMonth()->startOfMonth();
        $endOfLastMonth = now()->subMonth()->endOfMonth();

        // Current period stats
        $currentStats = [
            'revenue' => Order::where('vendor_id', $vendor->getKey())
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->where('status', '!=', 'cancelled')
                ->sum('grand_total'),
            'orders' => Order::where('vendor_id', $vendor->getKey())
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->count(),
            'products_sold' => DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.vendor_id', $vendor->getKey())
                ->whereBetween('orders.created_at', [$startOfMonth, $endOfMonth])
                ->where('orders.status', '!=', 'cancelled')
                ->sum('order_items.qty_ordered'),
            'commission' => Order::where('vendor_id', $vendor->getKey())
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->where('status', '!=', 'cancelled')
                ->sum('commission_amount'),
        ];

        // Previous period stats
        $previousStats = [
            'revenue' => Order::where('vendor_id', $vendor->getKey())
                ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
                ->where('status', '!=', 'cancelled')
                ->sum('grand_total'),
            'orders' => Order::where('vendor_id', $vendor->getKey())
                ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
                ->count(),
            'products_sold' => DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.vendor_id', $vendor->getKey())
                ->whereBetween('orders.created_at', [$startOfLastMonth, $endOfLastMonth])
                ->where('orders.status', '!=', 'cancelled')
                ->sum('order_items.qty_ordered'),
            'commission' => Order::where('vendor_id', $vendor->getKey())
                ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
                ->where('status', '!=', 'cancelled')
                ->sum('commission_amount'),
        ];

        // Calculate growth percentages
        $growth = [
            'revenue' => $this->calculateGrowth($currentStats['revenue'], $previousStats['revenue']),
            'orders' => $this->calculateGrowth($currentStats['orders'], $previousStats['orders']),
            'products_sold' => $this->calculateGrowth($currentStats['products_sold'], $previousStats['products_sold']),
        ];

        // Recent orders
        $recentOrders = Order::where('vendor_id', $vendor->getKey())
            ->with(['items', 'customer'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Top products
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
            ->where('orders.vendor_id', $vendor->getKey())
            ->where('orders.status', '!=', 'cancelled')
            ->select('vendor_products.name', DB::raw('SUM(order_items.qty_ordered) as total_sold'), DB::raw('SUM(order_items.row_total) as total_revenue'))
            ->groupBy('vendor_products.id', 'vendor_products.name')
            ->orderBy('total_sold', 'desc')
            ->limit(5)
            ->get();

        // Store performance
        $storePerformance = DB::table('orders')
            ->where('vendor_id', $vendor->getKey())
            ->select('vendor_store_id', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(grand_total) as total_revenue'))
            ->groupBy('vendor_store_id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'current_stats' => $currentStats,
                'previous_stats' => $previousStats,
                'growth' => $growth,
                'balance' => [
                    'available' => $vendor->current_balance,
                    'pending' => $vendor->pending_balance,
                    'total_earned' => $vendor->total_earned,
                ],
                'recent_orders' => $recentOrders,
                'top_products' => $topProducts,
                'store_performance' => $storePerformance,
            ]
        ]);
    }

    /**
     * Get statistics for charts
     */
    public function statistics(Request $request)
    {
        $vendor = $request->user()->vendor;

        $period = $request->get('period', '30_days');
        $days = match($period) {
            '7_days' => 7,
            '30_days' => 30,
            '90_days' => 90,
            'year' => 365,
            default => 30,
        };

        $startDate = now()->subDays($days);

        // Daily sales
        $dailySales = Order::where('vendor_id', $vendor->getKey())
            ->where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(grand_total) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Daily orders
        $dailyOrders = Order::where('vendor_id', $vendor->getKey())
            ->where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Sales by status
        $salesByStatus = Order::where('vendor_id', $vendor->getKey())
            ->where('created_at', '>=', $startDate)
            ->select('status', DB::raw('SUM(grand_total) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        // Sales by country
        $salesByCountry = Order::where('vendor_id', $vendor->getKey())
            ->where('created_at', '>=', $startDate)
            ->select('country_code', DB::raw('SUM(grand_total) as total'))
            ->groupBy('country_code')
            ->get();

        // Top products by revenue
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
            ->where('orders.vendor_id', $vendor->getKey())
            ->where('orders.created_at', '>=', $startDate)
            ->where('orders.status', '!=', 'cancelled')
            ->select('vendor_products.name', DB::raw('SUM(order_items.row_total) as revenue'), DB::raw('SUM(order_items.qty_ordered) as quantity'))
            ->groupBy('vendor_products.id', 'vendor_products.name')
            ->orderBy('revenue', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'daily_sales' => $dailySales,
                'daily_orders' => $dailyOrders,
                'sales_by_status' => $salesByStatus,
                'sales_by_country' => $salesByCountry,
                'top_products' => $topProducts,
            ]
        ]);
    }

    /**
     * Calculate growth percentage between current and previous values
     */
    private function calculateGrowth($current, $previous): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}
