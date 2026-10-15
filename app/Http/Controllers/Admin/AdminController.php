<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Contact;
use App\Models\DistributorProfile;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function index()
    {
        $admins = Admin::with('roles')->get();
        return response()->json($admins);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:admins,email',
            'password' => 'required|string|min:8',
            'roles' => 'array',
            'roles.*' => 'exists:admin_roles,id'
        ]);

        $admin = Admin::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password)
        ]);

        if ($request->has('roles')) {
            $admin->roles()->sync($request->roles);
        }

        return response()->json($admin->load('roles'), 201);
    }

    public function update(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        $request->validate([
            'name' => 'nullable|string',
            'email' => 'nullable|email',
            'password' => 'nullable|string|min:8',
            'roles' => 'array',
            'roles.*' => 'exists:admin_roles,id'
        ]);
        $data = [
            'name' => $request->name,
            'email' => $request->email ?? $admin->email
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $admin->update($data);

        if ($request->has('roles')) {
            $admin->roles()->sync($request->roles);
        }

        return response()->json($admin->load('roles'));
    }

    public function destroy($id)
    {
        if (auth()->id() == $id) {
            return response()->json([
                'message' => 'Cannot delete your own account.'
            ], 400);
        }

        $admin = Admin::findOrFail($id);
        $admin->delete();

        return response()->json(['message' => 'Admin deleted successfully']);
    }

    /**
     * Main dashboard - returns role-wise data
     */
    public function dashboard(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'admin' => $this->getAdminDashboard(),
                'warehouse' => $this->getWarehouseDashboard(),
                'finance' => $this->getFinanceDashboard(),
                'support' => $this->getSupportDashboard(),
            ]
        ]);
    }

    /**
     * ============================================
     * ADMIN ROLE DASHBOARD
     * ============================================
     */
    private function getAdminDashboard()
    {
        $totalRevenue = Order::where('status', '!=', 'pending')
            ->where('status', '!=', 'cancelled')
            ->sum('total_payable');

        $totalOrders = Order::where('status', '!=', 'pending')
            ->where('status', '!=', 'cancelled')
            ->count();

        $totalCustomers = User::where('account_type', 'customer')
            ->where('is_registered', true)
            ->count();

        $totalDistributors = User::where('account_type', 'distributor')
            ->where('is_registered', true)
            ->count();

        $totalProducts = Product::count();

        $salesAnalysis = $this->getSalesAnalysis();
        $topCategories = $this->getTopCategories();
        $pendingKycReviews = $this->getPendingKycReviews();
        $stockStatus = $this->getStockStatus();
        $topContacts = $this->getTopContacts();

        return [
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'total_customers' => $totalCustomers,
            'total_distributors' => $totalDistributors,
            'total_products' => $totalProducts,
            'sales_analysis' => $salesAnalysis,
            'top_categories' => $topCategories,
            'pending_kyc_reviews' => $pendingKycReviews,
            'stock_status' => $stockStatus,
            'top_contacts' => $topContacts,
        ];
    }

    /**
     * ============================================
     * WAREHOUSE ROLE DASHBOARD
     * (Uses order_lines for line-level delivery/shipping stats)
     * ============================================
     */
    private function getWarehouseDashboard()
    {
        // Stock status (low stock, out of stock) - from products table
        $stockStatus = $this->getStockStatus();

        // ---------- ORDER LINE based counters ----------

        // Lines not yet dispatched (pending in warehouse)
        $pendingDispatchLines = OrderLine::whereNull('dispatched_at')
            ->whereNotIn('delivery_status', ['cancelled', 'delivered'])
            ->count();

        // Lines currently in 'processing' delivery status
        $processingLines = OrderLine::where('delivery_status', 'processing')->count();

        // Lines ready to ship (confirmed / not yet dispatched)
        $readyToShipLines = OrderLine::where('delivery_status', 'confirmed')
            ->whereNull('dispatched_at')
            ->count();

        // Lines already dispatched but not delivered
        $inTransitLines = OrderLine::whereNotNull('dispatched_at')
            ->whereNull('delivered_at')
            ->whereNotIn('delivery_status', ['cancelled', 'returned'])
            ->count();

        // Lines delivered
        $deliveredLines = OrderLine::whereNotNull('delivered_at')->count();

        // Recent order lines needing warehouse attention
        $recentLines = OrderLine::whereNotIn('delivery_status', ['cancelled', 'delivered', 'returned'])
            ->with(['order:id,order_reference,user_id', 'product:id,name,product_code'])
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->get()
            ->map(function ($line) {
                return [
                    'id' => $line->id,
                    'item_reference_id' => $line->item_reference_id,
                    'order_id' => $line->order_id,
                    'order_reference' => $line->order?->order_reference,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product?->name,
                    'product_code' => $line->product?->product_code,
                    'quantity' => (int) $line->quantity,
                    'delivery_status' => $line->delivery_status,
                    'dispatched_at' => $line->dispatched_at?->toISOString(),
                    'delivered_at' => $line->delivered_at?->toISOString(),
                    'created_at' => $line->created_at?->toISOString(),
                ];
            });

        // Products needing restock
        $productsNeedingRestock = Product::whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->select('id', 'name', 'product_code', 'stock_quantity', 'low_stock_threshold')
            ->orderBy('stock_quantity', 'ASC')
            ->limit(10)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'product_code' => $product->product_code,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'low_stock_threshold' => (int) $product->low_stock_threshold,
                    'needs_restock' => $product->stock_quantity <= $product->low_stock_threshold,
                ];
            });

        return [
            'stock_status' => $stockStatus,
            'order_lines' => [
                'pending_dispatch_lines' => $pendingDispatchLines,
                'processing_lines' => $processingLines,
                'ready_to_ship_lines' => $readyToShipLines,
                'in_transit_lines' => $inTransitLines,
                'delivered_lines' => $deliveredLines,
                'recent_lines' => $recentLines,
            ],
            'products' => [
                'total_products' => Product::count(),
                'needs_restock' => $productsNeedingRestock,
            ],
        ];
    }

    /**
     * ============================================
     * FINANCE ROLE DASHBOARD
     * (Line-level amounts from order_lines)
     * ============================================
     */
    private function getFinanceDashboard()
    {
        // Total revenue from order_lines (line_total) for non-cancelled lines
        $totalLineRevenue = OrderLine::whereNotIn('delivery_status', ['cancelled'])
            ->sum('line_total');

        // Total GST collected from lines
        $totalGst = OrderLine::whereNotIn('delivery_status', ['cancelled'])
            ->sum('gst_amount');

        $totalCgst = OrderLine::whereNotIn('delivery_status', ['cancelled'])
            ->sum('cgst_amount');

        $totalSgst = OrderLine::whereNotIn('delivery_status', ['cancelled'])
            ->sum('sgst_amount');

        $totalIgst = OrderLine::whereNotIn('delivery_status', ['cancelled'])
            ->sum('igst_amount');

        // This month vs last month line revenue
        $thisMonthLineRevenue = OrderLine::whereNotIn('delivery_status', ['cancelled'])
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('line_total');

        $lastMonthLineRevenue = OrderLine::whereNotIn('delivery_status', ['cancelled'])
            ->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])
            ->sum('line_total');

        $revenueChange = $this->calculatePercentageChange($lastMonthLineRevenue, $thisMonthLineRevenue);

        // ---------- Pending payments (from orders table using amount_paid vs total_payable) ----------
        $pendingPayments = Order::whereColumn('amount_paid', '<', 'total_payable')
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $pendingPaymentAmount = Order::whereColumn('amount_paid', '<', 'total_payable')
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('COALESCE(SUM(total_payable - amount_paid), 0) as due')
            ->value('due');

        // ---------- Pending refunds (using refund_status column) ----------
        $pendingRefunds = Order::where('refund_status', 'pending')->count();
        $pendingRefundAmount = Order::where('refund_status', 'pending')->sum('total_payable');

        // Refunded orders
        $refundedOrders = Order::where('refund_status', 'completed')->count();
        $refundedAmount = Order::where('refund_status', 'completed')->sum('total_payable');

        // Cancelled order lines
        $cancelledLineAmount = OrderLine::where('delivery_status', 'cancelled')->sum('line_total');
        $cancelledLineCount = OrderLine::where('delivery_status', 'cancelled')->count();

        // Return completed lines
        $refundedLineAmount = OrderLine::whereNotNull('return_completed_at')->sum('line_total');
        $refundedLineCount = OrderLine::whereNotNull('return_completed_at')->count();

        // Buyback refunded
        $buybackRefundedAmount = OrderLine::whereNotNull('buyback_refunded_at')->sum('line_total');
        $buybackRefundedCount = OrderLine::whereNotNull('buyback_refunded_at')->count();

        $salesAnalysis = $this->getSalesAnalysis();
        $topCategories = $this->getTopCategories();

        return [
            'revenue' => [
                'total_line_revenue' => $totalLineRevenue,
                'this_month_line_revenue' => $thisMonthLineRevenue,
                'last_month_line_revenue' => $lastMonthLineRevenue,
                'revenue_change_percentage' => $revenueChange,
            ],
            'tax' => [
                'total_gst' => $totalGst,
                'total_cgst' => $totalCgst,
                'total_sgst' => $totalSgst,
                'total_igst' => $totalIgst,
            ],
            'payments' => [
                'pending_payments_count' => $pendingPayments,
                'pending_payments_amount' => $pendingPaymentAmount,
            ],
            'refunds' => [
                'pending_refunds_count' => $pendingRefunds,
                'pending_refunds_amount' => $pendingRefundAmount,
                'refunded_orders_count' => $refundedOrders,
                'refunded_orders_amount' => $refundedAmount,
                'refunded_lines_count' => $refundedLineCount,
                'refunded_lines_amount' => $refundedLineAmount,
                'buyback_refunded_count' => $buybackRefundedCount,
                'buyback_refunded_amount' => $buybackRefundedAmount,
            ],
            'cancellations' => [
                'cancelled_lines_count' => $cancelledLineCount,
                'cancelled_lines_amount' => $cancelledLineAmount,
            ],
            'sales_analysis' => $salesAnalysis,
            'top_categories' => $topCategories,
        ];
    }

    /**
     * ============================================
     * SUPPORT ROLE DASHBOARD
     * (Order lines with pending statuses + contacts)
     * ============================================
     */
    private function getSupportDashboard()
    {
        // ---------- Contacts ----------
        $unreadContactsCount = Contact::where('is_read', 0)->count();
        $totalContactsCount = Contact::count();
        $topContacts = $this->getTopContacts();

        // ---------- Order Lines (support pending) ----------
        $cancelPendingLines = OrderLine::where('delivery_status', 'cancel_pending')->count();
        $returnPendingLines = OrderLine::where('delivery_status', 'return_pending')->count();
        $buybackPendingLines = OrderLine::where('delivery_status', 'buyback_pending')->count();

        $totalPendingSupportLines = $cancelPendingLines + $returnPendingLines + $buybackPendingLines;

        // Recent support-related order lines
        $recentSupportLines = OrderLine::whereIn('delivery_status', [
            'cancel_pending',
            'return_pending',
            'buyback_pending'
        ])
            ->with(['order:id,order_reference,user_id', 'product:id,name,product_code'])
            ->orderBy('updated_at', 'DESC')
            ->limit(10)
            ->get()
            ->map(function ($line) {
                return [
                    'id' => $line->id,
                    'item_reference_id' => $line->item_reference_id,
                    'order_id' => $line->order_id,
                    'order_reference' => $line->order?->order_reference,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product?->name,
                    'product_code' => $line->product?->product_code,
                    'quantity' => (int) $line->quantity,
                    'delivery_status' => $line->delivery_status,
                    'cancellation_requested_at' => $line->cancellation_requested_at?->toISOString(),
                    'return_requested_at' => $line->return_requested_at?->toISOString(),
                    'buyback_requested_at' => $line->buyback_requested_at?->toISOString(),
                    'cancellation_reason' => $line->cancellation_reason,
                    'return_reason' => $line->return_reason,
                    'updated_at' => $line->updated_at?->toISOString(),
                ];
            });

        // Pending KYC reviews
        $pendingKycReviews = $this->getPendingKycReviews();

        return [
            'contacts' => [
                'unread_count' => $unreadContactsCount,
                'total_count' => $totalContactsCount,
                'top_contacts' => $topContacts,
            ],
            'order_lines' => [
                'cancel_pending_count' => $cancelPendingLines,
                'return_pending_count' => $returnPendingLines,
                'buyback_pending_count' => $buybackPendingLines,
                'total_pending_support_lines' => $totalPendingSupportLines,
                'recent_support_lines' => $recentSupportLines,
            ],
            // 'pending_kyc_reviews' => $pendingKycReviews,
        ];
    }

    /**
     * ============================================
     * SHARED HELPER METHODS
     * ============================================
     */

    private function getSalesAnalysis()
    {
        $now = now();

        $startOfWeek = $now->copy()->startOfWeek();
        $endOfWeek = $now->copy()->endOfWeek();

        $startOfLastWeek = $now->copy()->subWeek()->startOfWeek();
        $endOfLastWeek = $now->copy()->subWeek()->endOfWeek();

        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        return [
            'this_week' => [
                'summary' => [
                    'revenue' => Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                        ->sum('total_payable'),
                    'orders' => Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                        ->count(),
                    'start_date' => $startOfWeek->toDateString(),
                    'end_date' => $endOfWeek->toDateString(),
                ],
                'daily_breakdown' => $this->getDailyBreakdown($startOfWeek, $endOfWeek),
            ],
            'last_week' => [
                'summary' => [
                    'revenue' => Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfLastWeek, $endOfLastWeek])
                        ->sum('total_payable'),
                    'orders' => Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfLastWeek, $endOfLastWeek])
                        ->count(),
                    'start_date' => $startOfLastWeek->toDateString(),
                    'end_date' => $endOfLastWeek->toDateString(),
                ],
                'daily_breakdown' => $this->getDailyBreakdown($startOfLastWeek, $endOfLastWeek),
            ],
            'this_month' => [
                'summary' => [
                    'revenue' => Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                        ->sum('total_payable'),
                    'orders' => Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                        ->count(),
                    'start_date' => $startOfMonth->toDateString(),
                    'end_date' => $endOfMonth->toDateString(),
                ],
                'weekly_breakdown' => $this->getWeeklyBreakdown($startOfMonth, $endOfMonth),
            ],
            'percentage_change' => [
                'week_over_week' => $this->calculatePercentageChange(
                    Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfLastWeek, $endOfLastWeek])
                        ->sum('total_payable'),
                    Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                        ->sum('total_payable')
                ),
                'month_over_month' => $this->calculatePercentageChange(
                    Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
                        ->sum('total_payable'),
                    Order::where('status', '!=', 'pending')
                        ->where('status', '!=', 'cancelled')
                        ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                        ->sum('total_payable')
                ),
            ]
        ];
    }

    private function getDailyBreakdown($startDate, $endDate)
    {
        $dailyData = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $dayStart = $currentDate->copy()->startOfDay();
            $dayEnd = $currentDate->copy()->endOfDay();

            $revenue = Order::where('status', '!=', 'pending')
                ->where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->sum('total_payable');

            $orders = Order::where('status', '!=', 'pending')
                ->where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->count();

            $dailyData[] = [
                'date' => $currentDate->toDateString(),
                'day' => $currentDate->format('l'),
                'revenue' => $revenue,
                'orders' => $orders,
            ];

            $currentDate->addDay();
        }

        return $dailyData;
    }

    private function getWeeklyBreakdown($startDate, $endDate)
    {
        $weeklyData = [];
        $currentDate = $startDate->copy();
        $weekNumber = 1;

        while ($currentDate <= $endDate) {
            $weekStart = $currentDate->copy()->startOfWeek();
            $weekEnd = $currentDate->copy()->endOfWeek();

            if ($weekEnd > $endDate) {
                $weekEnd = $endDate->copy();
            }

            if ($weekStart < $startDate) {
                $weekStart = $startDate->copy();
            }

            $revenue = Order::where('status', '!=', 'pending')
                ->where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$weekStart->copy()->startOfDay(), $weekEnd->copy()->endOfDay()])
                ->sum('total_payable');

            $orders = Order::where('status', '!=', 'pending')
                ->where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$weekStart->copy()->startOfDay(), $weekEnd->copy()->endOfDay()])
                ->count();

            $weeklyData[] = [
                'week_number' => $weekNumber,
                'start_date' => $weekStart->toDateString(),
                'end_date' => $weekEnd->toDateString(),
                'revenue' => $revenue,
                'orders' => $orders,
            ];

            $currentDate->addWeek();
            $weekNumber++;
        }

        return $weeklyData;
    }

    private function calculatePercentageChange($previous, $current)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        $change = (($current - $previous) / $previous) * 100;
        return round($change, 2);
    }

    private function getTopCategories()
    {
        return DB::table('categories')
            ->leftJoin('products', 'categories.id', '=', 'products.category_id')
            ->select(
                'categories.id',
                'categories.title as name',
                'categories.slug',
                DB::raw('COUNT(products.id) as product_count'),
                DB::raw('MAX(products.retail_price) as max_price')
            )
            ->whereNull('products.deleted_at')
            ->groupBy('categories.id', 'categories.title', 'categories.slug')
            ->orderBy('product_count', 'DESC')
            ->limit(5)
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'product_count' => (int) $category->product_count,
                    'max_price' => $category->max_price ? (float) $category->max_price : 0,
                ];
            });
    }

    private function getPendingKycReviews()
    {
        return DistributorProfile::where('kyc_status', 'pending')
            ->whereHas('user', function ($query) {
                $query->where('is_registered', true);
            })
            ->with(['user' => function ($query) {
                $query->select(
                    'id',
                    'full_name',
                    'email',
                    'phone',
                    'account_type',
                    'is_registered'
                );
            }])
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->get()
            ->map(function ($profile) {
                return [
                    'id' => $profile->id,
                    'user_id' => $profile->user_id,
                    'user_name' => $profile->user?->full_name,
                    'user_email' => $profile->user?->email,
                    'user_phone' => $profile->user?->phone,
                    'account_type' => $profile->user?->account_type,
                    'title' => $profile->title,
                    'type_of_entity' => $profile->type_of_entity,
                    'bank_name' => $profile->bank_name,
                    'kyc_status' => $profile->kyc_status,
                    'submitted_at' => $profile->submitted_at,
                    'created_at' => $profile->created_at
                ];
            });
    }

    private function getStockStatus()
    {
        $lowStockProducts = Product::where('stock_quantity', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();

        $outOfStockProducts = Product::where('stock_quantity', '=', 0)
            ->count();

        $lowStockList = Product::where('stock_quantity', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->select('id', 'name', 'product_code', 'stock_quantity', 'low_stock_threshold')
            ->orderBy('stock_quantity', 'ASC')
            ->limit(10)
            ->get();

        $outOfStockList = Product::where('stock_quantity', '=', 0)
            ->select('id', 'name', 'product_code', 'stock_quantity', 'low_stock_threshold')
            ->orderBy('updated_at', 'DESC')
            ->limit(10)
            ->get();

        return [
            'summary' => [
                'low_stock_count' => $lowStockProducts,
                'out_of_stock_count' => $outOfStockProducts,
                'in_stock_count' => Product::whereColumn('stock_quantity', '>', 'low_stock_threshold')->count(),
                'total_products' => Product::count(),
            ],
            'low_stock_products' => $lowStockList->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'product_code' => $product->product_code,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'low_stock_threshold' => (int) $product->low_stock_threshold,
                ];
            }),
            'out_of_stock_products' => $outOfStockList->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'product_code' => $product->product_code,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'low_stock_threshold' => (int) $product->low_stock_threshold,
                ];
            }),
        ];
    }

    private function getTopContacts()
    {
        $contacts = Contact::orderBy('is_read', 'ASC')
            ->orderBy('created_at', 'DESC')
            ->limit(3)
            ->get();

        return $contacts->map(function ($contact) {
            return [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'message' => $contact->message,
                'is_read' => (bool) $contact->is_read,
                'read_at' => $contact->read_at?->toISOString(),
                'created_at' => $contact->created_at?->toISOString(),
            ];
        });
    }
}
