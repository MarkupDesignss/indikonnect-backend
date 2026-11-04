<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Wishlist;
use App\Models\Cart;
use App\Models\Product;
use App\Models\OrderLine;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\DistributorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;

class UserDashboardController extends Controller
{
    protected DistributorService $distributorService;
    protected IgniteClient $ignite;

    public function __construct(
        DistributorService $distributorService,
        IgniteClient $ignite
    ) {
        $this->distributorService = $distributorService;
        $this->ignite = $ignite;
    }

    /**
     * Display the user dashboard.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        $stats = $this->getUserStats($userId);
        $latestOrder = $this->getLatestOrder($userId);
        $recentActivity = $this->getRecentActivity($userId);
        $wishlistItems = $this->getWishlistItems($userId);
        $cartItems = $this->getCartItems($userId);

        $igniteData = null;
        $salesCalendar = null;

        if ($user->isDistributor() && $user->distributor_id) {
            $igniteData = $this->getIgniteMemberDetails($user->distributor_id);
            $salesCalendar = $this->getSalesCalendar((int) now()->year);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'account_type' => $user->account_type,
                    'member_since' => $user->created_at->format('Y'),
                ],
                'currency' => [
                    'base'    => 'USD',
                    'display' => 'INR',
                ],
                'stats' => $stats,
                'latest_order' => $latestOrder,
                'recent_activity' => $recentActivity,
                'wishlist' => $wishlistItems,
                'cart' => $cartItems,
                'ignite' => $igniteData,
                'sales_calendar' => $salesCalendar,
            ]
        ]);
    }

    /**
     * Get Ignite member details (rank, CV, balances) for a distributor.
     * All USD money fields now carry an INR equivalent.
     */
    private function getIgniteMemberDetails(string $baId): ?array
    {
        try {
            $response = $this->ignite->memberDetails($baId);

            if ($response['code'] !== ResultCode::OK->value) {
                return null;
            }

            $data = $response['data'];

            $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
                ->where('key', 'usd_to_inr')
                ->value('value') ?: 0;

            $planResp = Cache::remember('ignite_plan_ranks', 3600, function () {
                try {
                    $resp = $this->ignite->planRanks();
                    return ($resp['code'] ?? null) === ResultCode::OK->value
                        ? ($resp['data']['plan'] ?? [])
                        : [];
                } catch (\Throwable $e) {
                    return [];
                }
            });

            $yellowSmallRate = (float) ($planResp['yellow_usd_small'] ?? 30);
            $yellowLargeRate = (float) ($planResp['yellow_usd_large'] ?? 40);
            $blueRate        = (float) ($planResp['blue_usd']         ?? 12.5);
            $greenRate       = (float) ($planResp['green_redeem_usd'] ?? 125);

            $yellowBal = (float) ($data['yellow_bal'] ?? 0);
            $blueBal   = (float) ($data['blue_bal']   ?? 0);
            $greenBal  = (float) ($data['green_bal']  ?? 0);
            $cashBal   = (float) ($data['cash_bal']   ?? 0);

            $yellowValueUsd = $yellowBal * ($yellowBal >= 3 ? $yellowLargeRate : $yellowSmallRate);
            $blueValueUsd   = $blueBal >= 4 ? $blueBal * $blueRate : 0;
            $greenValueUsd  = floor($greenBal / 6) * $greenRate;

            $cvLeft  = (float) ($data['total_cv_left'] ?? 0);
            $cvRight = (float) ($data['total_cv_right'] ?? 0);

            $strongLeg = 'Equal';
            if ($cvLeft > $cvRight) $strongLeg = 'Left';
            if ($cvRight > $cvLeft) $strongLeg = 'Right';

            $history = $this->getCommissionHistory($baId, 0, 100);

            $retailProfitUsd   = 0;
            $weeklyPairingUsd  = 0;
            $unilevelGreenCoin = 0;

            foreach ($history as $tx) {
                if ($tx['description'] === 'comm_retail' && $tx['point_type'] === 'cash_bal') {
                    $retailProfitUsd += (float) $tx['amount_usd'];
                }
                if ($tx['description'] === 'comm_pairing' && $tx['point_type'] === 'cash_bal') {
                    $weeklyPairingUsd += (float) $tx['amount_usd'];
                }
                if ($tx['point_type'] === 'green_bal') {
                    $unilevelGreenCoin += (float) $tx['amount_usd'];
                }
            }

            $unilevelGreenValueUsd = floor($unilevelGreenCoin / 6) * $greenRate;

            return [
                'member_id'         => $data['member_id'] ?? null,
                'ba_id'             => $data['ba_id'] ?? null,
                'rank_no'           => $data['rank_no'] ?? 0,
                'base_rank_no'      => $data['base_rank_no'] ?? 0,
                'eligible_rank_no'  => $data['eligible_rank_no'] ?? 0,

                // CV Volumes (counts, not money)
                'cv_left'           => $data['cv_left'] ?? '0.0000',
                'cv_right'          => $data['cv_right'] ?? '0.0000',
                'floating_cv_left'  => $data['floating_cv_left'] ?? '0.0000',
                'floating_cv_right' => $data['floating_cv_right'] ?? '0.0000',
                'total_cv_left'     => $data['total_cv_left'] ?? '0.0000',
                'total_cv_right'    => $data['total_cv_right'] ?? '0.0000',

                // Dashboard Widgets Data — now with INR everywhere
                'strong_leg'              => $strongLeg,

                'retail_profit_usd'       => round($retailProfitUsd, 2),
                'retail_profit_inr'       => round($retailProfitUsd * $usdRate, 2),

                'weekly_pairing_usd'      => round($weeklyPairingUsd, 2),
                'weekly_pairing_inr'      => round($weeklyPairingUsd * $usdRate, 2),

                'unilevel_green_coin'     => round($unilevelGreenCoin, 2),
                'unilevel_green_value_usd'=> round($unilevelGreenValueUsd, 2),
                'unilevel_green_value_inr'=> round($unilevelGreenValueUsd * $usdRate, 2),

                // Cash balance
                'cash_bal'          => $cashBal,
                'cash_bal_usd'      => round($cashBal, 4),
                'cash_bal_inr'      => round($cashBal * $usdRate, 2),

                // Coins (counts + USD value + INR value)
                'green_bal'         => $greenBal,
                'green_value_usd'   => round($greenValueUsd, 2),
                'green_value_inr'   => round($greenValueUsd * $usdRate, 2),
                'green_note' => $greenBal >= 6
                            ? floor($greenBal / 6) . " redemption(s) ready = \$" . number_format(floor($greenBal / 6) * $greenRate, 2)
                            : ($greenBal > 0
                                ? "Need " . (6 - (int) $greenBal) . " more Green Coins to redeem \$" . number_format($greenRate, 2)
                                : "Every 6 Green Coins = \$" . number_format($greenRate, 2)),
                'yellow_bal'        => $yellowBal,
                'yellow_value_usd'  => round($yellowValueUsd, 2),
                'yellow_value_inr'  => round($yellowValueUsd * $usdRate, 2),

                'blue_bal'          => $blueBal,
                'blue_value_usd'    => round($blueValueUsd, 2),
                'blue_value_inr'    => round($blueValueUsd * $usdRate, 2),

                'exchange_rate'     => $usdRate,
                'base_currency'     => 'USD',
                'display_currency'  => 'INR',

                'membership_status' => $data['membership_status'] ?? null,
                'total_steps'       => $data['total_steps'] ?? 0,
                'qualified_at'      => $data['qualified_at'] ?? null,
                'is_placed'         => $data['is_placed'] ?? !empty($data['binary_parent_id']),
                'placement_leg'     => $data['binary_side'] ?? null,
                'tc_bank_available' => (int) ($data['tc_bank_available'] ?? 0),
                'far_left_id'       => $data['far_left_id']       ?? null,
                'far_left_ba_id'    => $data['far_left_ba_id']    ?? null,
                'far_right_id'      => $data['far_right_id']      ?? null,
                'far_right_ba_id'   => $data['far_right_ba_id']   ?? null,

                'commission_breakdown' => [
                    'cash_count'   => collect($history)->where('point_type', 'cash_bal')->count(),
                    'yellow_count' => collect($history)->where('point_type', 'yellow_bal')->count(),
                    'green_count'  => collect($history)->where('point_type', 'green_bal')->count(),
                    'blue_count'   => collect($history)->where('point_type', 'blue_bal')->count(),
                    'other_count'  => collect($history)->whereNotIn('point_type', ['cash_bal', 'yellow_bal', 'green_bal', 'blue_bal'])->count(),
                ],

                'commissions_history' => $history,
            ];
        } catch (\Exception $e) {
            \Log::warning('Ignite memberDetails failed', [
                'ba_id' => $baId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch the member's commission history (Ledger) from Ignite.
     * Every row now carries USD + INR + a display string.
     */
    private function getCommissionHistory(string $baId, int $afterId = 0, int $limit = 50): array
    {
        try {
            $response = $this->ignite->pointTxs($baId, $afterId, $limit);

            if (($response['code'] ?? null) !== ResultCode::OK->value) {
                return [];
            }

            $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
                ->where('key', 'usd_to_inr')
                ->value('value') ?: 0;

            $rows = $response['data']['rows'] ?? [];

            return array_map(function ($row) use ($usdRate) {
                $category = match ($row['tx_type']) {
                    'comm'  => 'Commission',
                    'cr'    => 'RedeemCoin',
                    'wd'    => 'Withdrawal',
                    'adj'   => 'Adjustment',
                    'pv'    => 'CV',
                    default => ucfirst($row['tx_type'] ?? 'Other'),
                };

                $amount      = (float) ($row['amount'] ?? 0);
                $pointType   = $row['point_type'] ?? '';
                $isCashRow   = $pointType === 'cash_bal';
                $isCoinRow   = in_array($pointType, ['green_bal', 'yellow_bal', 'blue_bal'], true);
                $isCvRow     = in_array($pointType, ['pv1_bal', 'pv2_bal'], true);

                // INR only for cash (money). Coin rows are counts, CV rows are counts.
                $amountInr = $isCashRow ? round($amount * $usdRate, 2) : null;

                // Display string — currency-correct
                if ($isCashRow) {
                   $sign = $amount < 0 ? '-' : ($amount > 0 ? '+' : '');
                   $amountDisplay = $sign . '₹' . number_format(abs($amount * $usdRate), 2);
                } elseif ($isCoinRow) {
                    $unit = match ($pointType) {
                        'green_bal'  => 'GC',
                        'yellow_bal' => 'YC',
                        'blue_bal'   => 'BC',
                        default      => '',
                    };
                    $sign = $amount < 0 ? '-' : ($amount > 0 ? '+' : '');
                    $amountDisplay = $sign . number_format(abs($amount), 2) . ' ' . $unit;
                } elseif ($isCvRow) {
                    $sign = $amount < 0 ? '-' : ($amount > 0 ? '+' : '');
                    $amountDisplay = $sign . number_format(abs($amount), 2) . ' CV';
                } else {
                    $sign = $amount < 0 ? '-' : ($amount > 0 ? '+' : '');
                    $amountDisplay = $sign . number_format(abs($amount), 2);
                }

                $dateCreated = $row['created_at'] ? \Carbon\Carbon::parse($row['created_at'])->format('d/m/Y') : '-';

                return [
                    'id'            => $row['id'],
                    'category'      => $category,
                    'point_type'    => $pointType,
                    'date_created'  => $dateCreated,
                    'date_release'  => $dateCreated,
                    'description'   => $row['description'] ?? '-',

                    // Raw amounts (both currencies — null where not applicable)
                    'amount_usd'    => $amount,
                    'amount_inr'    => $amountInr,

                    // Ready-to-render string
                    'amount'        => $amountDisplay,

                    'related_orderid'=> $row['order_no'] ?: 'N/A',
                    'related_member' => $row['rel_ba_id'] ?? $row['rel_member_id'] ?? 'N/A',
                ];
            }, $rows);

        } catch (\Exception $e) {
            \Log::warning('Ignite pointTxs failed', ['ba_id' => $baId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * API Endpoint for infinite scroll / pagination on the Commissions History table.
     */
    public function commissionHistory(Request $request)
    {
        $user = $request->user();

        if (!$user || empty($user->distributor_id)) {
            return response()->json(['success' => false, 'message' => 'Not registered on Ignite'], 422);
        }

        $afterId = (int) $request->input('after_id', 0);
        $limit   = min(max((int) $request->input('limit', 50), 1), 500);

        $history = $this->getCommissionHistory($user->distributor_id, $afterId, $limit);

        try {
            $rawResponse = $this->ignite->pointTxs($user->distributor_id, $afterId, $limit);
            $hasMore = $rawResponse['data']['has_more'] ?? false;
        } catch (\Throwable $e) {
            $hasMore = false;
        }

        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')
            ->value('value') ?: 0;

        return response()->json([
            'success'  => true,
            'currency' => ['base' => 'USD', 'display' => 'INR', 'exchange_rate' => $usdRate],
            'data'     => $history,
            'has_more' => $hasMore,
        ]);
    }

    /**
     * Get sales calendar for one year, cached for 6 hours.
     */
    private function getSalesCalendar(int $year): ?array
    {
        try {
            $resp = Cache::remember("ignite_sales_calendar_{$year}", 21600, function () use ($year) {
                return $this->ignite->salesCalendar($year);
            });

            if (($resp['code'] ?? null) !== ResultCode::OK->value) {
                return null;
            }

            $data = $resp['data'] ?? [];

            $utc8 = new \DateTimeZone('Asia/Singapore');
            $ist  = new \DateTimeZone('Asia/Kolkata');

            $toIst = function (?string $date, string $time = '00:00:00') use ($utc8, $ist): ?string {
                if (!$date) return null;
                $dt = new \DateTime("{$date} {$time}", $utc8);
                $dt->setTimezone($ist);
                return $dt->format('Y-m-d H:i:s');
            };

            $allMonths = [];

            foreach (($data['months'] ?? []) as $month) {
                $weeksData = [];
                foreach (($month['weeks'] ?? []) as $week) {
                    $endDateTime = $week['end_date'] ? (new \DateTime("{$week['end_date']} 00:00:00", $utc8))->modify('+1 day') : null;

                    $weeksData[] = [
                        'week_no'    => $week['week_no'] ?? null,
                        'start'      => $toIst($week['start_date'] ?? null, '00:00:00'),
                        'end'        => $toIst($week['end_date'] ?? null, '23:59:59'),
                        'payout_date' => $endDateTime ? $toIst($endDateTime->format('Y-m-d'), '00:00:00') : null,
                        'is_current' => (bool) ($week['is_current'] ?? false),
                        'is_settled' => (bool) ($week['is_settled'] ?? false),
                    ];
                }

                $allMonths[] = [
                    'month_no'   => $month['month_no'] ?? null,
                    'label'      => $month['label'] ?? null,
                    'is_current' => (bool) ($month['is_current'] ?? false),
                    'weeks'      => $weeksData,
                ];
            }

            return [
                'year'       => $data['year'] ?? $year,
                'all_months' => $allMonths,
            ];
        } catch (\Throwable $e) {
            Log::warning('Ignite salesCalendar failed', ['year' => $year, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Calculate general user statistics.
     */
    private function getUserStats($userId)
    {
        $totalOrders = Order::where('user_id', $userId)->count();
        $wishlistCount = Wishlist::where('user_id', $userId)->count();

        $totalPoints = Order::where('user_id', $userId)
            ->where('status', 'confirmed')
            ->sum('commissionable_volume') ?? 0;

        $reviewsCount  = ProductReview::where('user_id', $userId)->count();
        $averageRating = ProductReview::where('user_id', $userId)->avg('rating');

        $cart = Cart::where('user_id', $userId)->latest()->first();
        $cartItemsCount = 0;
        $cartTotal = 0;
        if ($cart) {
            $cartItems = DB::table('cart_items')->where('cart_id', $cart->id)->get();
            $cartItemsCount = $cartItems->count();
            foreach ($cartItems as $item) {
                $cartTotal += $item->unit_price * $item->quantity;
            }
        }

        return [
            'total_orders'   => $totalOrders,
            'wishlist'       => $wishlistCount,
            'points_earned'  => number_format((float) $totalPoints, 2, '.', ''),
            'points_unit'    => 'CV',   // CV, not USD
            'reviews'        => $reviewsCount,
            'average_rating' => $averageRating ? round($averageRating, 1) : 0,
            'cart_items'     => $cartItemsCount,
        ];
    }

    /**
     * Get the user's most recent order with product details.
     */
    private function getLatestOrder($userId)
    {
        $order = Order::with(['lines.product.images'])
            ->where('user_id', $userId)
            ->latest('created_at')
            ->first();

        if (!$order) {
            return null;
        }

        $orderItems = [];
        foreach ($order->lines as $line) {
            $product = $line->product;
            if ($product) {
                $images = $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => asset('storage/' . $image->image),
                        'is_primary' => $image->is_primary
                    ];
                });

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->name,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                    'images' => $images
                ];
            }
        }

        return [
            'order_reference' => $order->order_reference,
            'order_date' => $order->created_at->format('M d, Y'),
            'status' => $order->status,
            'currency' => 'INR',   // local order paid in INR
            'total_payable' => $order->total_payable,
            'courier_company' => $order->courier_company,
            'courier_tracking_number' => $order->courier_tracking_number,
            'courier_status' => $order->courier_status,
            'courier_delivery_date' => $order->courier_delivery_date,
            'expected_delivery' => $order->courier_delivery_date
                ? date('M d', strtotime($order->courier_delivery_date))
                : null,
            'items' => $orderItems,
            'sales_week' => $order->sales_week_year && $order->sales_week_no
                ? "{$order->sales_week_year}-W" . str_pad($order->sales_week_no, 2, '0', STR_PAD_LEFT)
                : null,
            'settlement_week' => $order->settlement_week_year && $order->settlement_week_no
                ? "{$order->settlement_week_year}-W" . str_pad($order->settlement_week_no, 2, '0', STR_PAD_LEFT)
                : null,
            'cv' => number_format((float) ($order->commissionable_volume ?? 0), 2, '.', ''),
            'cv_unit' => 'CV',
        ];
    }

    /**
     * Get recent activity for the user (orders, reviews, wishlist, cart).
     */
    private function getRecentActivity($userId)
    {
        $activities = [];

        $orders = Order::where('user_id', $userId)
            ->latest('created_at')
            ->limit(10)
            ->get();

        foreach ($orders as $order) {
            $orderLine = OrderLine::where('order_id', $order->id)->first();
            $productName = $orderLine
                ? (Product::find($orderLine->product_id)?->name ?? 'Product')
                : 'Product';

            $cv = (float) ($order->commissionable_volume ?? 0);

            $activities[] = [
                'type'              => 'order',
                'event'             => "Product Purchase: {$productName}",
                'created_at'        => $order->created_at->format('M d, Y H:i:s'),
                'updated_at'        => $order->updated_at->format('M d, Y H:i:s'),
                'created_timestamp' => $order->created_at->timestamp,
                'updated_timestamp' => $order->updated_at->timestamp,
                'points_earned'     => '+' . number_format($cv, 2, '.', '') . ' CV',
                'points_unit'       => 'CV',
                'status'            => 'Confirmed',
                'order_reference'   => $order->order_reference,
                'sales_week' => $order->sales_week_year && $order->sales_week_no
                    ? "{$order->sales_week_year}-W" . str_pad($order->sales_week_no, 2, '0', STR_PAD_LEFT)
                    : null,
                'settlement_week' => $order->settlement_week_year && $order->settlement_week_no
                    ? "{$order->settlement_week_year}-W" . str_pad($order->settlement_week_no, 2, '0', STR_PAD_LEFT)
                    : null,
            ];
        }

        $recentReviews = ProductReview::where('user_id', $userId)
            ->with('product')
            ->latest('created_at')
            ->limit(5)
            ->get();

        foreach ($recentReviews as $review) {
            $productName = $review->product?->name ?? 'Product';
            $activities[] = [
                'type' => 'review',
                'event' => "Product Review: {$productName}",
                'created_at' => $review->created_at->format('M d, Y H:i:s'),
                'updated_at' => $review->updated_at->format('M d, Y H:i:s'),
                'created_timestamp' => $review->created_at->timestamp,
                'updated_timestamp' => $review->updated_at->timestamp,
                'rating' => $review->rating,
                'review_text' => $review->review_text,
                'status' => $review->status ?? 'active',
                'product_id' => $review->product_id
            ];
        }

        $wishlistItems = Wishlist::where('user_id', $userId)
            ->with('product')
            ->latest('created_at')
            ->limit(10)
            ->get();

        foreach ($wishlistItems as $wishlist) {
            $productName = $wishlist->product?->name ?? 'Product';
            $activities[] = [
                'type' => 'wishlist',
                'event' => "Added to Wishlist: {$productName}",
                'created_at' => $wishlist->created_at->format('M d, Y H:i:s'),
                'updated_at' => $wishlist->updated_at->format('M d, Y H:i:s'),
                'created_timestamp' => $wishlist->created_at->timestamp,
                'updated_timestamp' => $wishlist->updated_at->timestamp,
                'product_id' => $wishlist->product_id,
                'wishlist_id' => $wishlist->id
            ];
        }

        $carts = Cart::where('user_id', $userId)
            ->with('items.product')
            ->latest('created_at')
            ->limit(10)
            ->get();

        foreach ($carts as $cart) {
            $cartItems = DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->join('products', 'cart_items.product_id', '=', 'products.id')
                ->select('products.name', 'cart_items.quantity', 'cart_items.created_at', 'cart_items.updated_at')
                ->get();

            foreach ($cartItems as $item) {
                $createdAt = $item->created_at
                    ? date('M d, Y H:i:s', strtotime($item->created_at))
                    : $cart->created_at->format('M d, Y H:i:s');
                $updatedAt = $item->updated_at
                    ? date('M d, Y H:i:s', strtotime($item->updated_at))
                    : $cart->updated_at->format('M d, Y H:i:s');
                $createdTimestamp = $item->created_at ? strtotime($item->created_at) : $cart->created_at->timestamp;
                $updatedTimestamp = $item->updated_at ? strtotime($item->updated_at) : $cart->updated_at->timestamp;

                $activities[] = [
                    'type' => 'cart',
                    'event' => "Added to Cart: {$item->name} (Qty: {$item->quantity})",
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                    'created_timestamp' => $createdTimestamp,
                    'updated_timestamp' => $updatedTimestamp,
                    'quantity' => $item->quantity,
                    'product_name' => $item->name
                ];
            }
        }

        usort($activities, function ($a, $b) {
            return $b['created_timestamp'] - $a['created_timestamp'];
        });

        return array_slice($activities, 0, 10);
    }

    /**
     * Get wishlist items with product details.
     */
    private function getWishlistItems($userId)
    {
        $wishlistItems = Wishlist::where('user_id', $userId)
            ->with(['product.images' => function ($query) {
                $query->orderBy('is_primary', 'desc')->orderBy('sort_order');
            }])
            ->latest()
            ->get();

        $items = [];
        foreach ($wishlistItems as $wishlist) {
            $product = $wishlist->product;
            if ($product) {
                $items[] = [
                    'id' => $wishlist->id,
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'retail_price' => $product->retail_price,
                    'distributor_price' => $product->distributor_price,
                    'currency' => 'INR',
                    'images' => $product->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => asset('storage/' . $image->image),
                            'is_primary' => $image->is_primary
                        ];
                    }),
                    'added_at' => $wishlist->created_at->format('M d, Y')
                ];
            }
        }
        return $items;
    }

    /**
     * Get cart items with product details.
     */
    private function getCartItems($userId)
    {
        $cart = Cart::where('user_id', $userId)->latest()->first();
        if (!$cart) {
            return [];
        }

        $cartItems = DB::table('cart_items')->where('cart_id', $cart->id)->get();
        $items = [];

        foreach ($cartItems as $cartItem) {
            $product = Product::with(['images' => function ($query) {
                $query->orderBy('is_primary', 'desc')->orderBy('sort_order');
            }])->find($cartItem->product_id);

            if ($product) {
                $items[] = [
                    'id' => $cartItem->id,
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => number_format(
                        (float) ($cartItem->unit_price > 0 ? $cartItem->unit_price : $product->distributor_price),
                        2, '.', ''
                    ),
                    'line_total' => number_format(
                        (float) ($cartItem->unit_price > 0 ? $cartItem->unit_price : $product->distributor_price) * $cartItem->quantity,
                        2, '.', ''
                    ),
                    'currency' => 'INR',
                    'images' => $product->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => asset('storage/' . $image->image),
                            'is_primary' => $image->is_primary
                        ];
                    })
                ];
            }
        }
        return $items;
    }

    /**
     * Get global marketplace statistics (public).
     */
    public function getStats()
    {
        try {
            $reviews = ProductReview::query()
                ->select([
                    'product_reviews.id',
                    'product_reviews.rating',
                    'product_reviews.review_text',
                    'product_reviews.created_at',
                    'users.id as user_id',
                    'users.full_name',
                    'users.profile_picture',
                    'addresses.state',
                ])
                ->join('users', 'users.id', '=', 'product_reviews.user_id')
                ->leftJoin('addresses', function ($join) {
                    $join->on('addresses.user_id', '=', 'users.id');
                })
                ->orderByDesc('product_reviews.created_at')
                ->get()
                ->map(function ($review) {
                    return [
                        'id' => $review->id,
                        'rating' => $review->rating,
                        'review_text' => $review->review_text,
                        'created_at' => $review->created_at
                            ? $review->created_at->format('Y-m-d H:i:s')
                            : null,
                        'user' => [
                            'id' => $review->user_id,
                            'full_name' => $review->full_name,
                            'profile_picture' => $review->profile_picture
                                ? asset('storage/' . $review->profile_picture)
                                : null,
                            'state' => $review->state ?? 'Not Provided',
                        ],
                    ];
                });

            $totalReviews = ProductReview::count();
            $averageRating = ProductReview::avg('rating');

            $totalBuyers = DB::table('orders')
                ->whereNull('deleted_at')
                ->whereNotNull('user_id')
                ->distinct()
                ->count('user_id');

            $repeatBuyers = DB::table('orders')
                ->whereNull('deleted_at')
                ->whereNotNull('user_id')
                ->select('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count();

            $repeatBuyerPercentage = $totalBuyers > 0
                ? round(($repeatBuyers / $totalBuyers) * 100)
                : 0;

            $totalCities = DB::table('addresses')
                ->whereNull('deleted_at')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->count('city');

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => [
                        'total_reviews' => $totalReviews,
                        'average_rating' => round((float) $averageRating, 1),
                        'repeat_buyers_percentage' => $repeatBuyerPercentage,
                        'total_cities' => $totalCities,
                    ],
                    'reviews' => $reviews,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get distributor-specific statistics including Ignite rank progress.
     */
        /**
     * Get distributor-specific statistics including Ignite rank progress.
     */
    public function getDistributorStats(Request $request)
    {
        try {
            $authUser = Auth::user();

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.',
                    'data' => []
                ], 401);
            }

            if ($authUser->account_type !== 'distributor') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Only distributors can access this data.',
                    'data' => []
                ], 403);
            }

            $userId = $authUser->id;

            $user = User::where('account_type', 'distributor')
                ->where('id', $userId)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distributor not found',
                    'data' => []
                ], 404);
            }

            // Local order statistics
            $stats = DB::table('orders as o')
                ->join('order_lines as ol', 'o.id', '=', 'ol.order_id')
                ->join('products as p', 'ol.product_id', '=', 'p.id')
                ->where('o.user_id', $userId)
                ->where('o.status', '!=', 'cancelled')
                ->select([
                    DB::raw('COUNT(DISTINCT o.id) as total_orders'),
                    DB::raw('COALESCE(SUM(p.distributor_mrp * ol.quantity), 0) as total_amount_mrp'),
                    DB::raw('COALESCE(SUM((p.distributor_mrp - p.distributor_price) * ol.quantity), 0) as total_savings'),
                    DB::raw('COALESCE(SUM(o.commissionable_volume), 0) as total_cv'),
                ])
                ->first();

            // Ignite rank progress (if registered)
            $rankProgress = null;
            $totalCoinsRedeemed = 0;

            if ($user->distributor_id) {
                try {
                    $response = $this->ignite->rankProgress($user->distributor_id);
                    if ($response['code'] === ResultCode::OK->value) {
                        $rankProgress = $response['data'];
                    }
                } catch (\Exception $e) {
                    // Ignore
                }

                // Tarika 2: Ignite Ledger se Total Coins Redeemed nikaalna
                try {
                    $txResp = $this->ignite->pointTxs($user->distributor_id, 0, 500); // Fetch up to 500 recent txs
                    $txRows = $txResp['data']['rows'] ?? [];
                    
                    foreach ($txRows as $tx) {
                        // 'cr_out' matlab coin deduct hua (redeem hua)
                        if (($tx['tx_type'] ?? null) === 'cr' && ($tx['description'] ?? '') === 'cr_out') {
                            $totalCoinsRedeemed += abs((float) $tx['amount']);
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            $result = [
                'user_id'              => $user->id,
                'full_name'            => $user->full_name,
                'email'                => $user->email,
                'phone'                => $user->phone,
                'distributor_id'       => $user->distributor_id,
                'total_orders'         => (int) $stats->total_orders,
                'total_amount_mrp'     => (float) $stats->total_amount_mrp,
                'total_amount_mrp_currency' => 'INR',
                'total_savings'        => (float) $stats->total_savings,
                'total_savings_currency'    => 'INR',
                'total_cv'             => number_format((float) $stats->total_cv, 2, '.', ''),
                'total_cv_unit'        => 'CV',
                
                // Yahan Ignite se aaya hua data set ho raha hai
                'total_coins_redeemed' => (int) $totalCoinsRedeemed, 
                
                'account_type'         => $user->account_type,
                'joined_at'            => $user->created_at->toDateTimeString(),
                'rank_progress'        => $rankProgress,
            ];

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Distributor stats retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve distributor stats',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function directRedeemCoin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'coins_to_redeem' => 'required|integer|min:1',
            'point_type'      => 'required|in:yellow_bal,blue_bal',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        if (!$user->isDistributor() || empty($user->distributor_id)) {
            return response()->json(['success' => false, 'message' => 'Not a distributor'], 422);
        }

        $coins = (int) $request->coins_to_redeem;
        $pointType = $request->point_type;

        $minBand = $pointType === 'yellow_bal' ? 1 : 4;
        if ($coins < $minBand) {
            return response()->json([
                'success' => false,
                'message' => "Minimum {$minBand} coins required for {$pointType}"
            ], 422);
        }

        $ignite = app(\App\Sdks\Partner\IgniteClient::class);

        $ref = 'DASH-' . $user->distributor_id . '-' . $pointType . '-' . $coins . '-' . time();

        try {
            $response = $ignite->redeemCoin(
                $user->distributor_id,
                $pointType,
                $coins,
                $ref
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Ignite unreachable'], 503);
        }

        $code = $response['code'] ?? null;

        if ($code === ResultCode::CONVERT_DISABLED->value) {
            return response()->json(['success' => false, 'message' => 'Coin redemption is currently disabled by Ignite.'], 422);
        }
        if ($code === ResultCode::REDEMPTION_REFUSED->value) {
            return response()->json(['success' => false, 'message' => $response['msg'] ?? 'Redemption refused (Insufficient balance or invalid band).'], 422);
        }
        if ($code === ResultCode::NETWORK_BUSY->value) {
            return response()->json(['success' => false, 'message' => 'Settlement in progress. Try after some time.'], 503);
        }
        if ($code !== ResultCode::OK->value) {
            return response()->json(['success' => false, 'message' => $response['msg'] ?? 'Redemption failed'], 422);
        }

        $cashAmountUsd = (float) ($response['data']['cash_amount'] ?? 0);
        $redemptionId  = $response['data']['redemption_id'] ?? null;

        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')->where('key', 'usd_to_inr')->value('value') ?: 0;
        $actualInr = round($cashAmountUsd * $usdRate, 2);

        $localRedemption = \App\Models\CoinRedemption::create([
            'user_id'              => $user->id,
            'point_type'           => $pointType,
            'coins_used'           => $coins,
            'amount_redeemed'      => $actualInr,
            'status'               => 'authorized',
            'api_authorization_id' => (string) $redemptionId,
            'authorized_at'        => now(),
        ]);

        Cache::forget("ignite_member_{$user->distributor_id}");

        return response()->json([
            'success'         => true,
            'message'         => 'Coins redeemed successfully!',
            'currency'        => ['base' => 'USD', 'display' => 'INR', 'exchange_rate' => $usdRate],
            'data' => [
                'coins_used'         => $coins,
                'amount_redeemed_usd'=> round($cashAmountUsd, 2),
                'amount_redeemed_inr'=> $actualInr,
                'amount_redeemed'    => $actualInr,   // backward-compat
                'redemption_id'      => $localRedemption->id,
            ]
        ]);
    }

    /**
     * Get full wallet data for the UI (Balances + Transactions + Withdrawals) with INR conversion.
     */
    public function getWalletDetails(Request $request)
    {
        $user = $request->user();

        if (!$user || empty($user->distributor_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Not registered on Ignite'
            ], 422);
        }

        $baId = $user->distributor_id;

        $memberResp = $this->ignite->memberDetails($baId);
        if (($memberResp['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch wallet balances from Ignite'
            ], 422);
        }
        $member = $memberResp['data'];

        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')->value('value') ?: 0;

        $planResp = Cache::remember('ignite_plan_ranks', 3600, function () {
            try {
                $resp = $this->ignite->planRanks();
                return ($resp['code'] ?? null) === ResultCode::OK->value
                    ? ($resp['data']['plan'] ?? [])
                    : [];
            } catch (\Throwable $e) {
                return [];
            }
        });

        $yellowSmallRate = (float) ($planResp['yellow_usd_small'] ?? 30);
        $yellowLargeRate = (float) ($planResp['yellow_usd_large'] ?? 40);
        $blueRate        = (float) ($planResp['blue_usd']         ?? 12.5);
        $greenRate       = (float) ($planResp['green_redeem_usd'] ?? 125);

        $txResp  = $this->ignite->pointTxs($baId, 0, 100);
        $rawTxs  = $txResp['data']['rows'] ?? [];
        $hasMore = $txResp['data']['has_more'] ?? false;

        $retailProfitUsd = 0;
        foreach ($rawTxs as $tx) {
            if (($tx['point_type'] ?? '') === 'cash_bal' && ($tx['description'] ?? '') === 'comm_retail') {
                $retailProfitUsd += (float) $tx['amount'];
            }
        }

        $transactions = [];
        $withdrawals  = [];

        foreach ($rawTxs as $tx) {
            $category = match ($tx['tx_type']) {
                'comm'  => 'Commission',
                'cr'    => 'RedeemCoin',
                'wd'    => 'Withdrawal',
                'adj'   => 'Adjustment',
                'pv'    => 'CV',
                default => ucfirst($tx['tx_type'] ?? 'Other'),
            };

            $amt = (float) $tx['amount'];

            $walletChangeUsd = ($tx['point_type'] ?? '') === 'cash_bal' ? $amt : 0;
            $walletChangeInr = round($walletChangeUsd * $usdRate, 2);

            $greenCoin  = ($tx['point_type'] ?? '') === 'green_bal'  ? $amt : 0;
            $yellowCoin = ($tx['point_type'] ?? '') === 'yellow_bal' ? $amt : 0;
            $blueCoin   = ($tx['point_type'] ?? '') === 'blue_bal'   ? $amt : 0;

            $greenAbs  = abs($greenCoin);
            $yellowAbs = abs($yellowCoin);
            $blueAbs   = abs($blueCoin);

            $greenValueInr  = round((floor($greenAbs / 6) * $greenRate) * $usdRate, 2);
            $yellowValueInr = round(($yellowAbs * ($yellowAbs >= 3 ? $yellowLargeRate : $yellowSmallRate)) * $usdRate, 2);
            $blueValueInr   = round(($blueAbs * $blueRate) * $usdRate, 2);

            if ($greenCoin < 0)  $greenValueInr  = -$greenValueInr;
            if ($yellowCoin < 0) $yellowValueInr = -$yellowValueInr;
            if ($blueCoin < 0)   $blueValueInr   = -$blueValueInr;

            $transactions[] = [
                'id'            => $tx['id'],
                'date'          => $tx['created_at'],
                'category'      => $category,
                'description'   => $tx['description'] ?? '-',
                'point_type'    => $tx['point_type'] ?? null,

                'wallet_change_usd' => $walletChangeUsd,
                'wallet_change_inr' => $walletChangeInr,

                'green_coin'    => $greenCoin,
                'yellow_coin'   => $yellowCoin,
                'blue_coin'     => $blueCoin,

                'green_value_inr' => $greenValueInr,
                'yellow_value_inr'=> $yellowValueInr,
                'blue_value_inr'  => $blueValueInr,

                'order_id'      => $tx['order_no'] ?? null,
                'related_member'=> $tx['rel_ba_id'] ?? $tx['rel_member_id'] ?? null,
            ];

            if (($tx['tx_type'] ?? '') === 'wd') {
                // Ignite sends withdrawal as negative; the paid-out amount is positive
                $amountUsdAbs = abs($amt);
                $withdrawals[] = [
                    'id'         => $tx['id'],
                    'date'       => $tx['created_at'],
                    'amount_usd' => round($amountUsdAbs, 4),
                    'amount_inr' => round($amountUsdAbs * $usdRate, 2),
                    'status'     => 'Paid',
                    'remark'     => $tx['description'] ?? 'Withdrawal',
                    'currency'   => 'INR',
                ];
            }
        }

        $cashUsd     = (float) ($member['cash_bal'] ?? 0);
        $greenQty    = (float) ($member['green_bal'] ?? 0);
        $yellowQty   = (float) ($member['yellow_bal'] ?? 0);
        $blueQty     = (float) ($member['blue_bal'] ?? 0);

        $greenValUsd  = floor($greenQty / 6) * $greenRate;
        $yellowValUsd = $yellowQty * ($yellowQty >= 3 ? $yellowLargeRate : $yellowSmallRate);
        $blueValUsd   = $blueQty >= 4 ? $blueQty * $blueRate : 0;

        return response()->json([
            'success' => true,
            'currency' => [
                'base'    => 'USD',
                'display' => 'INR',
                'exchange_rate' => $usdRate,
            ],
            'data' => [
                'balances' => [
                    'cash_bal_usd'      => round($cashUsd, 4),
                    'cash_bal_inr'      => round($cashUsd * $usdRate, 2),
                    'cash_bal_display_currency' => 'INR',

                    'retail_profit_usd' => round($retailProfitUsd, 2),
                    'retail_profit_inr' => round($retailProfitUsd * $usdRate, 2),

                    'green_bal'         => $greenQty,
                    'yellow_bal'        => $yellowQty,
                    'blue_bal'          => $blueQty,

                    'green_value_usd'   => round($greenValUsd, 2),
                    'yellow_value_usd'  => round($yellowValUsd, 2),
                    'blue_value_usd'    => round($blueValUsd, 2),

                    'green_value_inr'   => round($greenValUsd * $usdRate, 2),
                    'yellow_value_inr'  => round($yellowValUsd * $usdRate, 2),
                    'blue_value_inr'    => round($blueValUsd * $usdRate, 2),

                    'exchange_rate'     => $usdRate,
                ],
                'transactions'          => $transactions,
                'has_more_transactions' => $hasMore,
                'withdrawals'           => $withdrawals,
            ]
        ]);
    }
}