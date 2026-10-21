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
     * Balance movement history (pointTxs).
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

        return response()->json([
            'success'  => true,
            'data'     => $response['data']['rows'] ?? [],
            'total'    => count($response['data']['rows'] ?? []),
            'has_more' => (bool) ($response['data']['has_more'] ?? false),
            'after_id' => $afterId,
        ]);
    }

    /**
     * Pending (floating) CV rows.
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
            'data'     => $response['data']['rows'] ?? [],
            'total'    => count($response['data']['rows'] ?? []),
            'has_more' => (bool) ($response['data']['has_more'] ?? false),
            'after_id' => $afterId,
        ]);
    }
}