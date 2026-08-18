<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\CommissionApiEvent;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->get('period', now()->format('Y-m'));

        // Orders posted successfully
        $postedOrders = CommissionApiEvent::where('event_type', 'order_post')
            ->where('status', 'sent')
            ->with('order')
            ->whereHas('order', function ($q) use ($period) {
                $q->where('confirmed_at', 'like', $period . '%');
            })
            ->count();

        // Orders not posted yet
        $unpostedOrders = Order::where('status', 'confirmed')
            ->where('confirmed_at', 'like', $period . '%')
            ->whereDoesntHave('commissionApiEvents', function ($q) {
                $q->where('event_type', 'order_post')->where('status', 'sent');
            })
            ->count();

        // Failed/pending events
        $pendingPostings = CommissionApiEvent::where('event_type', 'order_post')
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('order', function ($q) use ($period) {
                $q->where('confirmed_at', 'like', $period . '%');
            })
            ->count();

        // CV Discrepancies
        $events = CommissionApiEvent::where('event_type', 'order_post')
            ->where('status', 'sent')
            ->with('order')
            ->whereHas('order', function ($q) use ($period) {
                $q->where('confirmed_at', 'like', $period . '%');
            })
            ->get();

        $discrepancies = [];
        foreach ($events as $event) {
            $expectedCv = $event->order->total_payable * 0.10; // Mock expectation
            $actualCv = $event->response_data['cv'] ?? 0;
            if (abs($expectedCv - $actualCv) > 0.01) {
                $discrepancies[] = [
                    'order_reference' => $event->order->order_reference,
                    'expected_cv' => $expectedCv,
                    'actual_cv' => $actualCv,
                    'difference' => $expectedCv - $actualCv,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'posted_orders' => $postedOrders,
                'unposted_orders' => $unpostedOrders,
                'pending_postings' => $pendingPostings,
                'cv_discrepancies' => $discrepancies,
                'discrepancy_count' => count($discrepancies),
            ]
        ]);
    }

    public function export(Request $request)
    {
        // Similar CSV export for reconciliation data
        // Will be implemented if needed
    }
}