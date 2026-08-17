<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayoutRun;
use App\Models\PayoutEntry;
use App\Services\Commission\CommissionServiceInterface;
use Illuminate\Http\Request;

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

    // Release a payout run
    public function release($id)
    {
        $run = PayoutRun::with('entries')->findOrFail($id);

        if ($run->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Run already released'], 400);
        }

        // Update all entries to 'released'
        PayoutEntry::where('payout_run_id', $run->id)->update(['status' => 'released']);

        // Update run status
        $run->update([
            'status' => 'released',
            'released_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $run]);
    }
}