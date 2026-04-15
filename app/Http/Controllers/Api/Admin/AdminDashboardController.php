<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Financial\Settlement;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function index(Request $request)
    {
        $stats = [
            'users' => [
                'total' => User::count(),
                'new_today' => User::whereDate('created_at', today())->count(),
                'active' => User::where('status', 'active')->count(),
                'pending_verification' => User::where('status', 'pending_verification')->count(),
            ],
            'vendors' => [
                'total' => Vendor::count(),
                'pending' => Vendor::where('status', 'pending')->count(),
                'active' => Vendor::where('status', 'active')->count(),
                'suspended' => Vendor::where('status', 'suspended')->count(),
                'kyc_pending' => Vendor::where('kyc_status', 'pending')->count(),
            ],
            'orders' => [
                'total' => Order::count(),
                'today' => Order::whereDate('created_at', today())->count(),
                'pending' => Order::where('status', 'pending')->count(),
                'processing' => Order::where('status', 'processing')->count(),
                'shipped' => Order::where('status', 'shipped')->count(),
                'delivered' => Order::where('status', 'delivered')->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
            ],
            'financial' => [
                'total_revenue' => Order::where('status', '!=', 'cancelled')->sum('grand_total'),
                'total_commission' => Order::where('status', '!=', 'cancelled')->sum('commission_amount'),
                'pending_settlements' => Settlement::where('status', 'pending')->sum('net_payout'),
                'total_paid' => Settlement::where('status', 'paid')->sum('net_payout'),
            ],
        ];

        // Get recent orders
        $recentOrders = Order::with(['vendor', 'customer'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Get recent vendors
        $recentVendors = Vendor::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'statistics' => $stats,
                'recent_orders' => $recentOrders,
                'recent_vendors' => $recentVendors,
            ],
        ]);
    }

    /**
     * Get chart data for dashboard
     */
    public function statistics(Request $request)
    {
        $period = $request->get('period', '30_days');

        $days = match ($period) {
            '7_days' => 7,
            '30_days' => 30,
            '90_days' => 90,
            'year' => 365,
            default => 30,
        };

        $startDate = now()->subDays($days);

        // Daily sales
        $dailySales = Order::where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(grand_total) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Daily orders
        $dailyOrders = Order::where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // New users by day
        $newUsers = User::where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // New vendors by day
        $newVendors = Vendor::where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top products
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', $startDate)
            ->where('orders.status', '!=', 'cancelled')
            ->select('order_items.product_name', DB::raw('SUM(order_items.qty_ordered) as total_quantity'))
            ->groupBy('order_items.product_name')
            ->orderBy('total_quantity', 'desc')
            ->limit(10)
            ->get();

        // Top vendors by revenue
        $topVendors = Order::where('created_at', '>=', $startDate)
            ->where('status', '!=', 'cancelled')
            ->select('vendor_id', DB::raw('SUM(grand_total) as total_revenue'))
            ->with('vendor')
            ->groupBy('vendor_id')
            ->orderBy('total_revenue', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'daily_sales' => $dailySales,
                'daily_orders' => $dailyOrders,
                'new_users' => $newUsers,
                'new_vendors' => $newVendors,
                'top_products' => $topProducts,
                'top_vendors' => $topVendors,
            ],
        ]);
    }
}

