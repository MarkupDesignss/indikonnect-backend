<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CommissionLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LedgerController extends Controller
{
    /**
     * Get paginated ledger entries with filters.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = CommissionLedger::where('distributor_id', $user->id);

        // Filter by period (Y-m)
        if ($request->has('period')) {
            $query->where('period', $request->period);
        }

        // Filter by entry_type
        if ($request->has('type')) {
            $query->where('entry_type', $request->type);
        }

        // Sort by latest
        $query->orderBy('created_at', 'desc');

        $ledger = $query->paginate(15);

        if ($ledger->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No earnings records found.',
                'pagination' => [
                    'total' => 0,
                    'per_page' => 15,
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $ledger->items(),
            'pagination' => [
                'total' => $ledger->total(),
                'per_page' => $ledger->perPage(),
                'current_page' => $ledger->currentPage(),
                'last_page' => $ledger->lastPage(),
            ],
        ]);
    }

    /**
     * Get summary totals (Gross, TDS, Net).
     */
    public function summary(Request $request)
    {
        $user = Auth::user();

        $query = CommissionLedger::where('distributor_id', $user->id)
                    ->whereIn('status', ['pending', 'released']); // exclude reversed

        $totalGross = $query->sum('gross_amount');
        $totalTds = $query->sum('tds_amount');
        $totalNet = $query->sum('net_amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_gross' => round($totalGross, 2),
                'total_tds' => round($totalTds, 2),
                'total_net' => round($totalNet, 2),
                'currency' => 'INR',
            ],
        ]);
    }

    /**
     * Get tax summary grouped by period (for TDS filing).
     */
    public function taxSummary(Request $request)
    {
        $user = Auth::user();

        // Optional: Check PAN verification (if you have this field)
        if (isset($user->pan_verified) && !$user->pan_verified) {
            return response()->json([
                'success' => false,
                'message' => 'PAN is not verified. TDS summary cannot be generated.',
            ], 403);
        }

        $summary = CommissionLedger::where('distributor_id', $user->id)
                    ->whereIn('status', ['pending', 'released'])
                    ->selectRaw('period, SUM(gross_amount) as gross, SUM(tds_amount) as tds, SUM(net_amount) as net')
                    ->groupBy('period')
                    ->orderBy('period', 'desc')
                    ->get();

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }
}