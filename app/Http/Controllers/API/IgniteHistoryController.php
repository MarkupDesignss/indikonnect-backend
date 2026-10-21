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

    public function pointTxs(Request $request)
    {
        $user = Auth::user();
        if (!$user->distributor_id) {
            return response()->json(['success' => false, 'message' => 'Not registered on Ignite'], 422);
        }

        $afterId = $request->input('after_id');
        $limit   = min((int) $request->input('limit', 50), 200);

        $response = $this->ignite->pointTxs($user->distributor_id, $afterId, $limit);

        if (($response['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch pointTxs',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $response['data']['rows'] ?? [],
            'total'   => count($response['data']['rows'] ?? []),
        ]);
    }

    public function floatingCvTxs(Request $request)
    {
        $user = Auth::user();
        if (!$user->distributor_id) {
            return response()->json(['success' => false, 'message' => 'Not registered on Ignite'], 422);
        }

        $response = $this->ignite->floatingCvTxs($user->distributor_id);

        if (($response['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch floatingCvTxs',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $response['data']['rows'] ?? [],
            'total'   => count($response['data']['rows'] ?? []),
        ]);
    }
}