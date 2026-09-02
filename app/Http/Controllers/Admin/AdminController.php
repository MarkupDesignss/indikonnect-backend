<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\BusinessProfile;
use App\Models\Contact;
use App\Models\Order;
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
        // Prevent deleting yourself
        if (auth()->id() == $id) {
            return response()->json([
                'message' => 'Cannot delete your own account.'
            ], 400);
        }

        $admin = Admin::findOrFail($id);
        $admin->delete();

        return response()->json(['message' => 'Admin deleted successfully']);
    }

    public function dashboard(Request $request)
    {
        // Total Revenue (from completed orders)
        $totalRevenue = Order::where('status', '!=', 'pending')
            ->where('status', '!=', 'cancelled')
            ->sum('total_payable');

        // Total Orders (excluding pending)
        $totalOrders = Order::where('status', '!=', 'pending')
            ->where('status', '!=', 'cancelled')
            ->count();

        // Total Customers (account_type = customer AND is_registered = true)
        $totalCustomers = User::where('account_type', 'customer')
            ->where('is_registered', true)
            ->count();

        // Total Distributors (account_type = distributor AND is_registered = true)
        $totalDistributors = User::where('account_type', 'distributor')
            ->where('is_registered', true)
            ->count();

        // Total Products
        $totalProducts = Product::count();

        // Sales Analysis
        $salesAnalysis = $this->getSalesAnalysis();

        // Top 3 Categories with most products
        $topCategories = $this->getTopCategories();

        // Pending KYC Reviews
        $pendingKycReviews = $this->getPendingKycReviews();

        // Low Stock and Out of Stock Products
        $stockStatus = $this->getStockStatus();

        // Top 3 Contacts (unread or recent)
        $topContacts = $this->getTopContacts();

        return response()->json([
            'success' => true,
            'data' => [
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
            ]
        ]);
    }

    private function getSalesAnalysis()
    {
        $now = now();

        // This Week (Monday to Sunday) - Daily Breakdown
        $startOfWeek = $now->copy()->startOfWeek();
        $endOfWeek = $now->copy()->endOfWeek();

        // Last Week - Daily Breakdown
        $startOfLastWeek = $now->copy()->subWeek()->startOfWeek();
        $endOfLastWeek = $now->copy()->subWeek()->endOfWeek();

        // This Month - Weekly Breakdown
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        // Previous Month for comparison
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
                'day' => $currentDate->format('l'), // Monday, Tuesday, etc.
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

            // If week end is beyond month end, adjust
            if ($weekEnd > $endDate) {
                $weekEnd = $endDate->copy();
            }

            // If week start is before month start, adjust
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

            // Move to next week
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
            ->limit(3)
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

    // private function getPendingKycReviews()
    // {
    //     return BusinessProfile::where('kyc_status', 'pending')
    //         ->with(['user' => function ($query) {
    //             $query->select('id', 'full_name', 'email', 'phone', 'account_type');
    //         }])
    //         ->orderBy('created_at', 'DESC')
    //         ->limit(10)
    //         ->get()
    //         ->map(function ($profile) {
    //             return [
    //                 'id' => $profile->id,
    //                 'user_id' => $profile->user_id,
    //                 'user_name' => $profile->user?->full_name,
    //                 'user_email' => $profile->user?->email,
    //                 'user_phone' => $profile->user?->phone,
    //                 'account_type' => $profile->user?->account_type,
    //                 'title' => $profile->title,
    //                 'type_of_entity' => $profile->type_of_entity,
    //                 'bank_name' => $profile->bank_name,
    //                 'kyc_status' => $profile->kyc_status,
    //                 'submitted_at' => $profile->submitted_at?->toISOString(),
    //                 'created_at' => $profile->created_at?->toISOString(),
    //             ];
    //         });
    // }

    private function getPendingKycReviews()
    {
        return BusinessProfile::where('kyc_status', 'pending')
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
        // Low Stock Products (stock_quantity > 0 AND stock_quantity <= low_stock_threshold)
        $lowStockProducts = Product::where('stock_quantity', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();

        // Out of Stock Products (stock_quantity = 0)
        $outOfStockProducts = Product::where('stock_quantity', '=', 0)
            ->count();

        // Get detailed list of low stock products
        $lowStockList = Product::where('stock_quantity', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->select('id', 'name', 'product_code', 'stock_quantity', 'low_stock_threshold')
            ->orderBy('stock_quantity', 'ASC')
            ->limit(10)
            ->get();

        // Get detailed list of out of stock products
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
        // Get top 3 most recent unread contacts, then recent contacts
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
