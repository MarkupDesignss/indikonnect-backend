<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\CommissionApiEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ReconciliationController extends Controller
{
    /**
     * Get reconciliation report for a period.
     */
    public function index(Request $request)
    {
        $period = $request->get('period', now()->format('Y-m'));

        // Orders posted successfully to Commission API
        $postedOrders = CommissionApiEvent::where('event_type', 'order_post')
            ->where('status', 'sent')
            ->with('order')
            ->whereHas('order', function ($q) use ($period) {
                $q->where('confirmed_at', 'like', $period . '%');
            })
            ->get();

        $postedOrderIds = $postedOrders->pluck('order_id')->toArray();

        // Orders confirmed but NOT posted (missing)
        $unpostedOrders = Order::where('status', 'confirmed')
            ->where('confirmed_at', 'like', $period . '%')
            ->whereNotIn('id', $postedOrderIds)
            ->get();

        // Posted events that are still pending or failed
        $pendingPostings = CommissionApiEvent::where('event_type', 'order_post')
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('order', function ($q) use ($period) {
                $q->where('confirmed_at', 'like', $period . '%');
            })
            ->with('order')
            ->get();

        // CV Discrepancies (Expected vs Actual)
        $cvDiscrepancies = [];
        foreach ($postedOrders as $event) {
            // Expected CV = 10% of order total (as per mock logic)
            $expectedCv = $event->order ? round($event->order->total_payable * 0.10, 2) : 0;
            $actualCv = isset($event->response_data['cv']) ? round($event->response_data['cv'], 2) : 0;

            if (abs($expectedCv - $actualCv) > 0.01) {
                $cvDiscrepancies[] = [
                    'order_reference' => $event->order->order_reference ?? 'N/A',
                    'order_id' => $event->order_id,
                    'expected_cv' => $expectedCv,
                    'actual_cv' => $actualCv,
                    'difference' => round($expectedCv - $actualCv, 2),
                    'event_id' => $event->id,
                    'status' => $event->status,
                ];
            }
        }

        // Reversal events for this period
        $reversals = CommissionApiEvent::where('event_type', 'reversal')
            ->where('status', 'sent')
            ->whereHas('order', function ($q) use ($period) {
                $q->where('cancelled_at', 'like', $period . '%');
            })
            ->with('order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'summary' => [
                    'total_orders' => Order::where('confirmed_at', 'like', $period . '%')->count(),
                    'posted_successfully' => $postedOrders->count(),
                    'unposted' => $unpostedOrders->count(),
                    'pending_failed' => $pendingPostings->count(),
                    'reversals' => $reversals->count(),
                    'cv_discrepancies' => count($cvDiscrepancies),
                ],
                'unposted_orders' => $unpostedOrders->map(function ($order) {
                    return [
                        'order_reference' => $order->order_reference,
                        'order_id' => $order->id,
                        'confirmed_at' => $order->confirmed_at,
                        'total_payable' => $order->total_payable,
                    ];
                }),
                'pending_postings' => $pendingPostings->map(function ($event) {
                    return [
                        'event_id' => $event->id,
                        'order_reference' => $event->order->order_reference ?? 'N/A',
                        'status' => $event->status,
                        'retry_count' => $event->retry_count,
                        'error_message' => $event->error_message,
                        'created_at' => $event->created_at,
                    ];
                }),
                'cv_discrepancies' => $cvDiscrepancies,
                'reversals' => $reversals->map(function ($event) {
                    return [
                        'order_reference' => $event->order->order_reference ?? 'N/A',
                        'reversed_value' => $event->payload['reversedValue'] ?? 0,
                        'original_cv' => $event->payload['originalCv'] ?? 0,
                        'reason' => $event->payload['reason'] ?? 'N/A',
                        'created_at' => $event->created_at,
                    ];
                }),
            ]
        ]);
    }

    /**
     * Export reconciliation report as CSV.
     */
    public function export(Request $request)
    {
        $period = $request->get('period', now()->format('Y-m'));

        // Get all orders for this period
        $orders = Order::where('confirmed_at', 'like', $period . '%')
            ->where('status', 'confirmed')
            ->with(['commissionApiEvents' => function ($q) {
                $q->where('event_type', 'order_post');
            }])
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=reconciliation-{$period}.csv",
        ];

        $callback = function () use ($orders, $period) {
            $handle = fopen('php://output', 'w');

            // Headers
            fputcsv($handle, [
                'Order Reference',
                'Order ID',
                'Total Payable',
                'Confirmed At',
                'Commission Event Status',
                'Retry Count',
                'Expected CV (10%)',
                'Actual CV',
                'CV Difference',
                'Error Message',
            ]);

            foreach ($orders as $order) {
                $event = $order->commissionApiEvents->first();
                $status = $event ? $event->status : 'MISSING';
                $retryCount = $event ? $event->retry_count : 0;
                $actualCv = $event && isset($event->response_data['cv']) ? $event->response_data['cv'] : 0;
                $expectedCv = round($order->total_payable * 0.10, 2);
                $difference = round($expectedCv - $actualCv, 2);
                $error = $event ? $event->error_message : 'No event found';

                fputcsv($handle, [
                    $order->order_reference,
                    $order->id,
                    number_format($order->total_payable, 2),
                    $order->confirmed_at,
                    $status,
                    $retryCount,
                    number_format($expectedCv, 2),
                    number_format($actualCv, 2),
                    number_format($difference, 2),
                    $error,
                ]);
            }

            fclose($handle);
        };

        return Response::stream($callback, 200, $headers);
    }

    /**
     * Get summary stats for dashboard.
     */
    public function summary(Request $request)
    {
        $period = $request->get('period', now()->format('Y-m'));

        $postedOrders = CommissionApiEvent::where('event_type', 'order_post')
            ->where('status', 'sent')
            ->whereHas('order', function ($q) use ($period) {
                $q->where('confirmed_at', 'like', $period . '%');
            })
            ->count();

        $totalOrders = Order::where('confirmed_at', 'like', $period . '%')->count();

        $pendingPostings = CommissionApiEvent::where('event_type', 'order_post')
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('order', function ($q) use ($period) {
                $q->where('confirmed_at', 'like', $period . '%');
            })
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'total_orders' => $totalOrders,
                'posted_orders' => $postedOrders,
                'pending_orders' => $totalOrders - $postedOrders,
                'pending_postings' => $pendingPostings,
                'success_rate' => $totalOrders > 0 ? round(($postedOrders / $totalOrders) * 100, 2) : 0,
            ]
        ]);
    }

    /**
     * Resend a failed/pending event (Admin action).
     */
    public function replayEvent(Request $request, $eventId)
    {
        $event = CommissionApiEvent::findOrFail($eventId);

        if ($event->status === 'sent') {
            return response()->json([
                'success' => false,
                'message' => 'Event already sent successfully.'
            ], 400);
        }

        // Reset the event for retry
        $event->status = 'pending';
        $event->retry_count = 0;
        $event->last_attempt = null;
        $event->error_message = null;
        $event->save();

        return response()->json([
            'success' => true,
            'message' => 'Event reset for replay successfully.',
            'data' => $event
        ]);
    }
}