<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Product\VendorProduct;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorReportController extends Controller
{
    /**
     * Sales report
     */
    public function sales(Request $request)
    {
        $vendor = $request->user()->vendor;

        $request->validate([
            'period' => 'nullable|in:today,week,month,quarter,year,custom',
            'date_from' => 'required_if:period,custom|date',
            'date_to' => 'required_if:period,custom|date|after_or_equal:date_from',
            'group_by' => 'nullable|in:day,week,month',
        ]);

        // Set date range
        switch ($request->period) {
            case 'today':
                $startDate = now()->startOfDay();
                $endDate = now()->endOfDay();
                break;
            case 'week':
                $startDate = now()->startOfWeek();
                $endDate = now()->endOfWeek();
                break;
            case 'month':
                $startDate = now()->startOfMonth();
                $endDate = now()->endOfMonth();
                break;
            case 'quarter':
                $startDate = now()->startOfQuarter();
                $endDate = now()->endOfQuarter();
                break;
            case 'year':
                $startDate = now()->startOfYear();
                $endDate = now()->endOfYear();
                break;
            case 'custom':
                $startDate = $request->date_from;
                $endDate = $request->date_to;
                break;
            default:
                $startDate = now()->startOfMonth();
                $endDate = now()->endOfMonth();
        }

        // Base query
        $query = Order::where('vendor_id', $vendor->getKey())
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'cancelled');

        // Summary statistics
        $summary = [
            'total_revenue' => (clone $query)->sum('grand_total'),
            'total_orders' => (clone $query)->count(),
            'average_order_value' => (clone $query)->avg('grand_total') ?? 0,
            'total_commission' => (clone $query)->sum('commission_amount'),
            'total_tax' => (clone $query)->sum('tax_amount'),
            'total_shipping' => (clone $query)->sum('shipping_amount'),
            'total_discount' => (clone $query)->sum('discount_amount'),
        ];

        // Daily/Monthly breakdown
        $groupBy = $request->group_by ?? 'day';

        $breakdown = (clone $query)
            ->select(
                DB::raw($this->getDateGroupSql($groupBy, 'created_at').' as period'),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(grand_total) as revenue'),
                DB::raw('AVG(grand_total) as avg_order_value'),
                DB::raw('SUM(commission_amount) as commission')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        // Sales by store
        $salesByStore = (clone $query)
            ->select('vendor_store_id', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(grand_total) as revenue'))
            ->with('vendorStore')
            ->groupBy('vendor_store_id')
            ->get();

        // Sales by payment method
        $salesByPayment = (clone $query)
            ->select('payment_method', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(grand_total) as revenue'))
            ->groupBy('payment_method')
            ->get();

        // Sales by country
        $salesByCountry = (clone $query)
            ->select(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.country_id')) as country"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(grand_total) as revenue'))
            ->groupBy('country')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
                'summary' => $summary,
                'breakdown' => $breakdown,
                'by_store' => $salesByStore,
                'by_payment' => $salesByPayment,
                'by_country' => $salesByCountry,
            ],
        ]);
    }

    /**
     * Products report
     */
    public function products(Request $request)
    {
        $vendor = $request->user()->vendor;

        $request->validate([
            'period' => 'nullable|in:week,month,quarter,year',
            'sort_by' => 'nullable|in:revenue,quantity,orders',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $startDate = match ($request->period) {
            'week' => now()->subWeek(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };

        $limit = $request->limit ?? 20;

        // Top products by revenue
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
            ->where('orders.vendor_id', $vendor->getKey())
            ->where('orders.created_at', '>=', $startDate)
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'vendor_products.id',
                'vendor_products.name',
                'vendor_products.sku',
                DB::raw('SUM(order_items.qty_ordered) as total_quantity'),
                DB::raw('SUM(order_items.row_total) as total_revenue'),
                DB::raw('COUNT(DISTINCT orders.id) as order_count'),
                DB::raw('AVG(order_items.price) as avg_price')
            )
            ->groupBy('vendor_products.id', 'vendor_products.name', 'vendor_products.sku')
            ->orderBy($request->sort_by === 'quantity' ? 'total_quantity' : 'total_revenue', 'desc')
            ->limit($limit)
            ->get();

        // Product inventory summary
        $inventorySummary = [
            'total_products' => VendorProduct::where('vendor_id', $vendor->getKey())->count(),
            'active_products' => VendorProduct::where('vendor_id', $vendor->getKey())->where('status', 'active')->count(),
            'out_of_stock' => VendorProduct::where('vendor_id', $vendor->getKey())->where('quantity', 0)->count(),
            'low_stock' => VendorProduct::where('vendor_id', $vendor->getKey())->where('quantity', '>', 0)->where('quantity', '<=', 5)->count(),
        ];

        // Category performance
        $categoryPerformance = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
            ->where('orders.vendor_id', $vendor->getKey())
            ->where('orders.created_at', '>=', $startDate)
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(vendor_products.categories, "$[0]")) as category'),
                DB::raw('SUM(order_items.qty_ordered) as total_quantity'),
                DB::raw('SUM(order_items.row_total) as total_revenue')
            )
            ->groupBy('category')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate->toDateString(),
                    'end' => now()->toDateString(),
                ],
                'top_products' => $topProducts,
                'inventory_summary' => $inventorySummary,
                'category_performance' => $categoryPerformance,
            ],
        ]);
    }

    /**
     * Customers report
     */
    public function customers(Request $request)
    {
        $vendor = $request->user()->vendor;

        $request->validate([
            'period' => 'nullable|in:week,month,quarter,year',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $startDate = match ($request->period) {
            'week' => now()->subWeek(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };

        $limit = $request->limit ?? 20;

        // Top customers by spending
        $topCustomers = Order::where('vendor_id', $vendor->getKey())
            ->where('created_at', '>=', $startDate)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('customer_id')
            ->select(
                'customer_id',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(grand_total) as total_spent'),
                DB::raw('AVG(grand_total) as avg_order_value'),
                DB::raw('MAX(created_at) as last_order_date')
            )
            ->with('customer')
            ->groupBy('customer_id')
            ->orderBy('total_spent', 'desc')
            ->limit($limit)
            ->get();

        // Customer statistics
        $customerStats = [
            'total_customers' => Order::where('vendor_id', $vendor->getKey())
                ->whereNotNull('customer_id')
                ->distinct('customer_id')
                ->count('customer_id'),
            'new_customers' => Order::where('vendor_id', $vendor->getKey())
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('customer_id')
                ->distinct('customer_id')
                ->count('customer_id'),
            'returning_customers' => Order::where('vendor_id', $vendor->getKey())
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('customer_id')
                ->havingRaw('COUNT(*) > 1')
                ->count(),
        ];

        // New vs returning customers
        $customerTypeBreakdown = [
            'new' => Order::where('vendor_id', $vendor->getKey())
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('customer_id')
                ->select('customer_id', DB::raw('COUNT(*) as order_count'))
                ->groupBy('customer_id')
                ->having('order_count', '=', 1)
                ->count(),
            'returning' => Order::where('vendor_id', $vendor->getKey())
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('customer_id')
                ->select('customer_id', DB::raw('COUNT(*) as order_count'))
                ->groupBy('customer_id')
                ->having('order_count', '>', 1)
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate->toDateString(),
                    'end' => now()->toDateString(),
                ],
                'top_customers' => $topCustomers,
                'customer_statistics' => $customerStats,
                'customer_type_breakdown' => $customerTypeBreakdown,
            ],
        ]);
    }

    /**
     * Export report to CSV/Excel
     */
    public function export(Request $request)
    {
        $vendor = $request->user()->vendor;

        $request->validate([
            'report_type' => 'required|in:sales,products,customers',
            'format' => 'required|in:csv,xlsx',
            'period' => 'nullable|in:week,month,quarter,year,custom',
            'date_from' => 'required_if:period,custom|date',
            'date_to' => 'required_if:period,custom|date',
        ]);

        // Get report data based on type
        $data = match ($request->report_type) {
            'sales' => $this->getSalesExportData($vendor, $request),
            'products' => $this->getProductsExportData($vendor, $request),
            'customers' => $this->getCustomersExportData($vendor, $request),
        };

        // Generate export file
        $filename = $this->generateExportFile($data, $request->report_type, $request->format);

        return response()->json([
            'success' => true,
            'message' => 'Report generated successfully',
            'data' => [
                'download_url' => $filename,
                'expires_in' => 3600, // 1 hour
                'file_size' => $data['size'] ?? null,
            ],
        ]);
    }

    /**
     * Get sales export data
     */
    private function getSalesExportData($vendor, $request)
    {
        $orders = Order::where('vendor_id', $vendor->getKey())
            ->with(['items', 'customer'])
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'headers' => ['Order ID', 'Date', 'Customer', 'Email', 'Items', 'Subtotal', 'Tax', 'Shipping', 'Total', 'Status'],
            'rows' => $orders->map(function ($order) {
                return [
                    $order->order_number,
                    $order->created_at->format('Y-m-d H:i:s'),
                    $order->customer_firstname.' '.$order->customer_lastname,
                    $order->customer_email,
                    $order->items->sum('qty_ordered'),
                    $order->subtotal,
                    $order->tax_amount,
                    $order->shipping_amount,
                    $order->grand_total,
                    $order->status,
                ];
            })->toArray(),
        ];
    }

    /**
     * Get products export data
     */
    private function getProductsExportData($vendor, $request)
    {
        $products = VendorProduct::where('vendor_id', $vendor->getKey())
            ->with(['store'])
            ->get();

        return [
            'headers' => ['SKU', 'Name', 'Store', 'Price', 'Stock', 'Status', 'Total Sold', 'Total Revenue'],
            'rows' => $products->map(function ($product) {
                return [
                    $product->sku,
                    $product->name,
                    $product->store->store_name,
                    $product->price,
                    $product->quantity ?? 0,
                    $product->status,
                    $product->orderItems()->sum('qty_ordered'),
                    $product->orderItems()->sum('row_total'),
                ];
            })->toArray(),
        ];
    }

    /**
     * Get customers export data
     */
    private function getCustomersExportData($vendor, $request)
    {
        $customers = User::whereHas('orders', function ($q) use ($vendor) {
            $q->where('vendor_id', $vendor->getKey());
        })
            ->with(['orders' => function ($q) use ($vendor) {
                $q->where('vendor_id', $vendor->getKey());
            }])
            ->get();

        return [
            'headers' => ['Name', 'Email', 'Total Orders', 'Total Spent', 'Average Order', 'First Order', 'Last Order'],
            'rows' => $customers->map(function ($customer) {
                $orders = $customer->orders;

                return [
                    $customer->full_name,
                    $customer->email,
                    $orders->count(),
                    $orders->sum('grand_total'),
                    $orders->avg('grand_total'),
                    $orders->min('created_at')?->format('Y-m-d'),
                    $orders->max('created_at')?->format('Y-m-d'),
                ];
            })->toArray(),
        ];
    }

    /**
     * Generate export file
     */
    private function generateExportFile($data, $type, $format)
    {
        // In production, this would generate an actual file using Laravel Excel
        // For now, return a mock response
        return storage_path("app/exports/{$type}_report_".date('Ymd_His').".{$format}");
    }

    /**
     * Get date grouping SQL
     */
    private function getDateGroupSql($groupBy, $column)
    {
        return match ($groupBy) {
            'day' => "DATE({$column})",
            'week' => "CONCAT(YEAR({$column}), '-W', WEEK({$column}))",
            'month' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "DATE({$column})",
        };
    }

    /**
     * Placeholder until inventory reporting is implemented.
     */
    public function inventory(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Inventory report is not implemented yet.',
        ], 501);
    }
}

