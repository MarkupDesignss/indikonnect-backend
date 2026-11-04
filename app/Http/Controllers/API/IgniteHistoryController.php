<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;

class IgniteHistoryController extends Controller
{
    protected IgniteClient $ignite;

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    /**
     * Balance movement history (pointTxs) with USD + INR per row.
     * Query: after_id (default 0), limit (default 50, max 500)
     */
    public function pointTxs(Request $request)
    {
        $user = Auth::user();
        if (!$user->distributor_id) {
            return response()->json(['success' => false, 'message' => 'Not registered on Ignite'], 422);
        }

        $afterId = (int) ($request->input('after_id') ?? 0);
        $limit   = min(max((int) $request->input('limit', 50), 1), 500);

        $response = $this->ignite->pointTxs($user->distributor_id, $afterId, $limit);

        if (($response['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch pointTxs',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')->value('value') ?: 0;

        $rows = collect($response['data']['rows'] ?? [])->map(function ($row) use ($usdRate) {
            $pointType = $row['point_type'] ?? '';
            $isCash    = $pointType === 'cash_bal';

            $amount   = (float) ($row['amount'] ?? 0);
            $balAfter = (float) ($row['bal_after'] ?? 0);

            $row['amount_usd']    = $amount;
            $row['amount_inr']    = $isCash ? round($amount * $usdRate, 2) : null;

            $row['bal_after_usd'] = $balAfter;
            $row['bal_after_inr'] = $isCash ? round($balAfter * $usdRate, 2) : null;

            $row['exchange_rate'] = $usdRate;
            return $row;
        })->all();

        return response()->json([
            'success'  => true,
            'currency' => ['base' => 'USD', 'display' => 'INR', 'exchange_rate' => $usdRate],
            'data'     => $rows,
            'total'    => count($rows),
            'has_more' => (bool) ($response['data']['has_more'] ?? false),
            'after_id' => $afterId,
        ]);
    }

    /**
     * Pending (floating) CV rows — CV counts, no USD/INR conversion needed.
     * Query: after_id (default 0), limit (default 50, max 500)
     */
    public function floatingCvTxs(Request $request)
    {
        $user = Auth::user();
        if (!$user->distributor_id) {
            return response()->json(['success' => false, 'message' => 'Not registered on Ignite'], 422);
        }

        $afterId = (int) ($request->input('after_id') ?? 0);
        $limit   = min(max((int) $request->input('limit', 50), 1), 500);

        $response = $this->ignite->floatingCvTxs($user->distributor_id, $afterId, $limit);

        if (($response['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch floatingCvTxs',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        return response()->json([
            'success'  => true,
            'unit'     => 'CV',
            'data'     => $response['data']['rows'] ?? [],
            'total'    => count($response['data']['rows'] ?? []),
            'has_more' => (bool) ($response['data']['has_more'] ?? false),
            'after_id' => $afterId,
        ]);
    }
}