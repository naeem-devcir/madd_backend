<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Financial\Settlement;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\Exceptions\InvalidFormatException;

class AdminDashboardController extends Controller
{
    /**
     * Get dashboard statistics
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            // Validate request parameters if any
            $request->validate([
                'include_deleted' => 'sometimes|boolean',
            ]);

            $stats = [
                'users' => $this->getUserStatistics(),
                'vendors' => $this->getVendorStatistics(),
                'orders' => $this->getOrderStatistics(),
                'financial' => $this->getFinancialStatistics(),
            ];

            // Get recent orders with error handling
            $recentOrders = $this->getRecentOrders();
            
            // Get recent vendors with error handling
            $recentVendors = $this->getRecentVendors();

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => $stats,
                    'recent_orders' => $recentOrders,
                    'recent_vendors' => $recentVendors,
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Dashboard statistics error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id() ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching dashboard statistics',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get chart data for dashboard
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics(Request $request)
    {
        try {
            // Validate period parameter
            $validated = $request->validate([
                'period' => 'sometimes|string|in:7_days,30_days,90_days,year,custom',
                'start_date' => 'required_if:period,custom|nullable|date',
                'end_date' => 'required_if:period,custom|nullable|date|after_or_equal:start_date',
            ]);

            $days = $this->getDaysFromPeriod($request->input('period', '30_days'));
            $startDate = $this->getStartDate($request, $days);

            // Fetch all statistics data with error handling
            $statisticsData = $this->fetchStatisticsData($startDate);

            return response()->json([
                'success' => true,
                'data' => $statisticsData,
                'meta' => [
                    'period' => $request->input('period', '30_days'),
                    'start_date' => $startDate->toDateString(),
                    'end_date' => now()->toDateString(),
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (InvalidFormatException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format provided',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Dashboard statistics error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'period' => $request->input('period'),
                'user_id' => auth()->id() ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching chart statistics',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get user statistics with error handling
     *
     * @return array
     */
    private function getUserStatistics(): array
    {
        try {
            return [
                'total' => User::count(),
                'new_today' => User::whereDate('created_at', today())->count(),
                'active' => User::where('status', 'active')->count(),
                'pending_verification' => User::where('status', 'pending_verification')->count(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch user statistics: ' . $e->getMessage());
            return [
                'total' => 0,
                'new_today' => 0,
                'active' => 0,
                'pending_verification' => 0,
                'error' => 'Unable to fetch user statistics',
            ];
        }
    }

    /**
     * Get vendor statistics with error handling
     *
     * @return array
     */
    private function getVendorStatistics(): array
    {
        try {
            return [
                'total' => Vendor::count(),
                'pending' => Vendor::where('status', 'pending')->count(),
                'active' => Vendor::where('status', 'active')->count(),
                'suspended' => Vendor::where('status', 'suspended')->count(),
                'kyc_pending' => Vendor::where('kyc_status', 'pending')->count(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch vendor statistics: ' . $e->getMessage());
            return [
                'total' => 0,
                'pending' => 0,
                'active' => 0,
                'suspended' => 0,
                'kyc_pending' => 0,
                'error' => 'Unable to fetch vendor statistics',
            ];
        }
    }

    /**
     * Get order statistics with error handling
     *
     * @return array
     */
    private function getOrderStatistics(): array
    {
        try {
            return [
                'total' => Order::count(),
                'today' => Order::whereDate('created_at', today())->count(),
                'pending' => Order::where('status', 'pending')->count(),
                'processing' => Order::where('status', 'processing')->count(),
                'shipped' => Order::where('status', 'shipped')->count(),
                'delivered' => Order::where('status', 'delivered')->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch order statistics: ' . $e->getMessage());
            return [
                'total' => 0,
                'today' => 0,
                'pending' => 0,
                'processing' => 0,
                'shipped' => 0,
                'delivered' => 0,
                'cancelled' => 0,
                'error' => 'Unable to fetch order statistics',
            ];
        }
    }

    /**
     * Get financial statistics with error handling
     *
     * @return array
     */
    private function getFinancialStatistics(): array
    {
        try {
            return [
                'total_revenue' => Order::where('status', '!=', 'cancelled')->sum('grand_total'),
                'total_commission' => Order::where('status', '!=', 'cancelled')->sum('commission_amount'),
                'pending_settlements' => Settlement::where('status', 'pending')->sum('net_payout'),
                'total_paid' => Settlement::where('status', 'paid')->sum('net_payout'),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch financial statistics: ' . $e->getMessage());
            return [
                'total_revenue' => 0,
                'total_commission' => 0,
                'pending_settlements' => 0,
                'total_paid' => 0,
                'error' => 'Unable to fetch financial statistics',
            ];
        }
    }

    /**
     * Get recent orders with error handling
     *
     * @return \Illuminate\Database\Eloquent\Collection|array
     */
    private function getRecentOrders()
    {
        try {
            return Order::with(['vendor', 'customer'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch recent orders: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent vendors with error handling
     *
     * @return \Illuminate\Database\Eloquent\Collection|array
     */
    private function getRecentVendors()
    {
        try {
            return Vendor::with('user')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch recent vendors: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get days from period string
     *
     * @param string $period
     * @return int
     */
    private function getDaysFromPeriod(string $period): int
    {
        return match ($period) {
            '7_days' => 7,
            '30_days' => 30,
            '90_days' => 90,
            'year' => 365,
            default => 30,
        };
    }

    /**
     * Get start date based on period or custom dates
     *
     * @param Request $request
     * @param int $defaultDays
     * @return \Carbon\Carbon
     */
    private function getStartDate(Request $request, int $defaultDays)
    {
        if ($request->input('period') === 'custom') {
            return $request->input('start_date') 
                ? \Carbon\Carbon::parse($request->input('start_date'))
                : now()->subDays($defaultDays);
        }
        
        return now()->subDays($defaultDays);
    }

    /**
     * Fetch all statistics data
     *
     * @param \Carbon\Carbon $startDate
     * @return array
     */
    private function fetchStatisticsData($startDate): array
    {
        return [
            'daily_sales' => $this->getDailySales($startDate),
            'daily_orders' => $this->getDailyOrders($startDate),
            'new_users' => $this->getNewUsersByDay($startDate),
            'new_vendors' => $this->getNewVendorsByDay($startDate),
            'top_products' => $this->getTopProducts($startDate),
            'top_vendors' => $this->getTopVendors($startDate),
        ];
    }

    /**
     * Get daily sales data
     *
     * @param \Carbon\Carbon $startDate
     * @return \Illuminate\Support\Collection|array
     */
    private function getDailySales($startDate)
    {
        try {
            return Order::where('created_at', '>=', $startDate)
                ->where('status', '!=', 'cancelled')
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(grand_total) as total'))
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch daily sales: ' . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get daily orders data
     *
     * @param \Carbon\Carbon $startDate
     * @return \Illuminate\Support\Collection|array
     */
    private function getDailyOrders($startDate)
    {
        try {
            return Order::where('created_at', '>=', $startDate)
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch daily orders: ' . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get new users by day
     *
     * @param \Carbon\Carbon $startDate
     * @return \Illuminate\Support\Collection|array
     */
    private function getNewUsersByDay($startDate)
    {
        try {
            return User::where('created_at', '>=', $startDate)
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch new users: ' . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get new vendors by day
     *
     * @param \Carbon\Carbon $startDate
     * @return \Illuminate\Support\Collection|array
     */
    private function getNewVendorsByDay($startDate)
    {
        try {
            return Vendor::where('created_at', '>=', $startDate)
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch new vendors: ' . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get top products
     *
     * @param \Carbon\Carbon $startDate
     * @return \Illuminate\Support\Collection|array
     */
    private function getTopProducts($startDate)
    {
        try {
            return DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.created_at', '>=', $startDate)
                ->where('orders.status', '!=', 'cancelled')
                ->select('order_items.product_name', DB::raw('SUM(order_items.qty_ordered) as total_quantity'))
                ->groupBy('order_items.product_name')
                ->orderBy('total_quantity', 'desc')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch top products: ' . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get top vendors by revenue
     *
     * @param \Carbon\Carbon $startDate
     * @return \Illuminate\Support\Collection|array
     */
    private function getTopVendors($startDate)
    {
        try {
            return Order::where('created_at', '>=', $startDate)
                ->where('status', '!=', 'cancelled')
                ->select('vendor_id', DB::raw('SUM(grand_total) as total_revenue'))
                ->with('vendor')
                ->groupBy('vendor_id')
                ->orderBy('total_revenue', 'desc')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch top vendors: ' . $e->getMessage());
            return collect([]);
        }
    }
}