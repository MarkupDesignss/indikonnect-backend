<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayoutRun;
use App\Models\PayoutEntry;
use App\Services\Commission\CommissionServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class PayoutController extends Controller
{
    protected CommissionServiceInterface $commissionService;

    public function __construct(CommissionServiceInterface $commissionService)
    {
        $this->commissionService = $commissionService;
    }

    // List all payout runs
    public function index()
    {
        $runs = PayoutRun::with('creator')->orderBy('created_at', 'desc')->get();
        return response()->json(['success' => true, 'data' => $runs]);
    }

    // Create a new payout run
    public function store(Request $request)
    {
        $request->validate(['period' => 'required|string|max:7']);

        $data = $this->commissionService->getPayoutRunData($request->period);

        $run = PayoutRun::create([
            'period' => $data['period'],
            'status' => 'pending',
            'total_gross' => $data['total_gross'],
            'total_tds' => $data['total_tds'],
            'total_net' => $data['total_net'],
            'created_by' => auth()->id(),
        ]);

        foreach ($data['entries'] as $entry) {
            PayoutEntry::create([
                'payout_run_id' => $run->id,
                'distributor_id' => $entry['distributor_id'],
                'gross_commission' => $entry['gross_commission'],
                'tds' => $entry['tds'],
                'net_payable' => $entry['net_payable'],
                'status' => 'pending',
            ]);
        }

        return response()->json(['success' => true, 'data' => $run->load('entries')]);
    }

    // View a specific run
    public function show($id)
    {
        $run = PayoutRun::with(['entries.distributor', 'creator'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $run]);
    }

    // Release a payout run (only pending entries)
    public function release($id)
    {
        $run = PayoutRun::with('entries')->findOrFail($id);

        if ($run->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Run already released'], 400);
        }

        // Update only pending entries (skip held)
        PayoutEntry::where('payout_run_id', $run->id)
            ->where('status', 'pending')
            ->update(['status' => 'released']);

        // Update run status only if all entries are released (no pending/held)
        $remaining = PayoutEntry::where('payout_run_id', $run->id)
            ->whereIn('status', ['pending', 'held'])
            ->count();

        if ($remaining === 0) {
            $run->update([
                'status' => 'released',
                'released_at' => now(),
            ]);
        } else {
            // If some entries are still pending/held, keep run as pending
            // Admin can release again after resolving held entries
            $run->update(['status' => 'pending']);
        }

        return response()->json(['success' => true, 'data' => $run]);
    }

    // Hold/Unhold a specific payout entry
    public function holdEntry(Request $request, $entryId)
    {
        $entry = PayoutEntry::with('payoutRun')->findOrFail($entryId);

        // Cannot hold if run is already released
        if ($entry->payoutRun->status === 'released') {
            return response()->json(['success' => false, 'message' => 'Run already released'], 400);
        }

        $request->validate(['reason' => 'nullable|string|max:255']);

        if ($entry->status === 'pending') {
            $entry->update([
                'status' => 'held',
                'held_reason' => $request->reason ?? 'No reason provided',
            ]);
            $message = 'Entry held successfully';
        } elseif ($entry->status === 'held') {
            $entry->update([
                'status' => 'pending',
                'held_reason' => null,
            ]);
            $message = 'Hold removed, entry back to pending';
        } else {
            return response()->json(['success' => false, 'message' => 'Entry cannot be held (already released)'], 400);
        }

        return response()->json(['success' => true, 'message' => $message, 'data' => $entry]);
    }

    // Export payout run as CSV
    public function export($id)
    {
        $run = PayoutRun::with(['entries.distributor'])->findOrFail($id);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=payout-run-{$run->period}.csv",
        ];

        $callback = function () use ($run) {
            $handle = fopen('php://output', 'w');

            // Add CSV headers
            fputcsv($handle, [
                'Distributor ID',
                'Name',
                'Gross Commission',
                'TDS (2%)',
                'Net Payable',
                'Status',
                'Hold Reason'
            ]);

            // Add rows
            foreach ($run->entries as $entry) {
                fputcsv($handle, [
                    $entry->distributor_id,
                    $entry->distributor->name ?? 'N/A',
                    number_format($entry->gross_commission, 2),
                    number_format($entry->tds, 2),
                    number_format($entry->net_payable, 2),
                    $entry->status,
                    $entry->held_reason ?? '',
                ]);
            }

            // Add totals row
            fputcsv($handle, []); // empty line
            fputcsv($handle, [
                'TOTALS',
                '',
                number_format($run->total_gross, 2),
                number_format($run->total_tds, 2),
                number_format($run->total_net, 2),
                '',
                ''
            ]);

            fclose($handle);
        };

        return Response::stream($callback, 200, $headers);
    }
}