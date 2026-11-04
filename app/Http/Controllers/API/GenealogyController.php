<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;

class GenealogyController extends Controller
{
    protected IgniteClient $ignite;

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    /**
     * Get the binary tree for the authenticated distributor.
     */
    public function tree(Request $request)
    {
        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $mainBaId = $this->normalizeMainBaId($user->distributor_id);
        $depth = min(max((int) $request->input('depth', 3), 1), 5);

        $response = $this->ignite->binaryTree($mainBaId, $depth);

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch binary tree',
                'code'    => $response['code'],
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];
        $root = collect($rows)->firstWhere('level', 0);

        $member      = $this->fetchMemberDetails($mainBaId);
        $leftVolume  = (float) ($member['cv_left']  ?? 0) + (float) ($member['floating_cv_left']  ?? 0);
        $rightVolume = (float) ($member['cv_right'] ?? 0) + (float) ($member['floating_cv_right'] ?? 0);

        return response()->json([
            'success' => true,
            'unit'    => 'CV',   // all volumes here are CV
            'data' => [
                'root'             => $root,
                'rows'             => $rows,
                'left_volume'      => number_format($leftVolume, 4, '.', ''),
                'right_volume'     => number_format($rightVolume, 4, '.', ''),
                'weaker_leg'       => $this->getWeakerLeg($leftVolume, $rightVolume),
                'depth'            => $depth,
                'total_nodes'      => count($rows),
                'cv_settled_left'  => $member['cv_left']           ?? '0.0000',
                'cv_settled_right' => $member['cv_right']          ?? '0.0000',
                'cv_floating_left' => $member['floating_cv_left']  ?? '0.0000',
                'cv_floating_right'=> $member['floating_cv_right'] ?? '0.0000',
            ],
        ]);
    }

    /**
     * Get the direct children (left and right) of a specific node.
     */
    public function children(Request $request, ?int $userId = null)
    {
        $authUser = Auth::user();

        if (!$authUser->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $baId = $request->input('ba_id');

        if (!$baId && $userId) {
            $targetUser = User::find($userId);
            $baId = $targetUser?->distributor_id;
        }

        if (!$baId) {
            return response()->json([
                'success' => false,
                'message' => 'Either ba_id or a valid userId with Ignite registration is required.',
            ], 422);
        }

        $baId = $this->normalizeMainBaId($baId);
        $response = $this->ignite->binaryTree($baId, 1);

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch children',
                'code'    => $response['code'],
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];
        $children = collect($rows)->where('level', 1)->values();

        $left  = $children->firstWhere('binary_side', 1);
        $right = $children->firstWhere('binary_side', 2);

        return response()->json([
            'success' => true,
            'unit'    => 'CV',
            'data' => [
                'ba_id'  => $baId,
                'left'   => $left,
                'right'  => $right,
                'left_available'  => $left  === null,
                'right_available' => $right === null,
                'userId' => $userId,
            ],
        ]);
    }

    /**
     * Search for a distributor by BA ID within the authenticated user's downline.
     */
    public function search(Request $request)
    {
        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $query = trim((string) $request->input('query'));

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Query must be at least 2 characters.',
            ], 422);
        }

        $mainBaId = $this->normalizeMainBaId($user->distributor_id);
        $response = $this->ignite->unilevelTree($mainBaId, 3);

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Search failed',
                'code'    => $response['code'],
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];

        $results = collect($rows)
            ->filter(function ($row) use ($query) {
                $haystack = ($row['ba_id'] ?? '') . ' ' . ($row['name'] ?? '');
                return stripos($haystack, $query) !== false;
            })
            ->values()
            ->take(20);

        return response()->json([
            'success'      => true,
            'data'         => $results,
            'total'        => $results->count(),
            'search_scope' => 'up to 3 levels deep (Ignite limit)',
        ]);
    }

    /**
     * Get a flat list of the authenticated user's unilevel downline.
     */
    public function downlineList(Request $request)
    {
        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $mainBaId = $this->normalizeMainBaId($user->distributor_id);
        $depth    = min(max((int) $request->input('depth', 3), 1), 3);

        $response = $this->ignite->unilevelTree($mainBaId, $depth);

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch downline',
                'code'    => $response['code'],
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];

        return response()->json([
            'success' => true,
            'unit'    => 'CV',
            'data'    => $rows,
            'total'   => count($rows),
        ]);
    }

    /**
     * Get pending placements for the authenticated sponsor.
     */
    public function pendingPlacements(Request $request)
    {
        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $mainBaId = $this->normalizeMainBaId($user->distributor_id);
        $response = $this->ignite->pendingPlacements($mainBaId);

        if (($response['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch pending placements',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];

        $treeResp = $this->ignite->binaryTree($mainBaId, 1);

        if (($treeResp['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $treeResp['msg'] ?? 'Failed to read binary tree for slot availability',
                'code'    => $treeResp['code'] ?? null,
            ], 422);
        }

        $root = collect($treeResp['data']['rows'] ?? [])->firstWhere('level', 0);

        $slots = [
            'far_left_ba_id'  => $root['far_left_ba_id']  ?? null,
            'far_right_ba_id' => $root['far_right_ba_id'] ?? null,
            'left_available'  => empty($root['far_left_ba_id']),
            'right_available' => empty($root['far_right_ba_id']),
        ];

        return response()->json([
            'success' => true,
            'data'    => $rows,
            'total'   => count($rows),
            'slots'   => $slots,
        ]);
    }

    /**
     * Set the binary placement for a downline.
     */
    public function setPlacement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ba_id' => 'required|string',
            'side'  => 'required|in:1,2',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        if (!$user->distributor_id) {
            return response()->json(['success' => false, 'message' => 'Not registered on Ignite'], 422);
        }

        $mainBaId = $this->normalizeMainBaId($user->distributor_id);
        $side     = (int) $request->side;

        $member  = $this->fetchMemberDetails($mainBaId);
        $slotKey = $side === 1 ? 'far_left_id' : 'far_right_id';
        $slotId  = $member[$slotKey] ?? null;

        if (empty($slotId)) {
            return response()->json([
                'success' => false,
                'message' => 'That side is not available for placement.',
            ], 422);
        }

        try {
            $response = $this->ignite->setPlacement(
                baId:            $request->ba_id,
                placementParent: (int) $slotId,
                side:            $side,
            );
        } catch (\Throwable $e) {
            Log::error('Ignite setPlacement threw', [
                'ba_id' => $request->ba_id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Ignite unreachable'], 503);
        }

        $code = $response['code'] ?? null;

        if ($code === ResultCode::PLACEMENT_DEADLINE_PASSED->value) {
            return response()->json([
                'success' => false,
                'message' => 'Placement deadline has passed. Ignite has auto-placed this member. Contact operations to move them.',
                'code'    => ResultCode::PLACEMENT_DEADLINE_PASSED->value,
            ], 422);
        }

        if ($code === ResultCode::PLACEMENT_REFUSED->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'That position is not available.',
                'code'    => ResultCode::PLACEMENT_REFUSED->value,
            ], 422);
        }

        if ($code !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Placement failed',
                'code'    => $code,
            ], 422);
        }

        Cache::forget("ignite_member_{$mainBaId}");
        Cache::forget("ignite_member_{$request->ba_id}");
        Cache::forget("ignite_binary_tree_{$mainBaId}");

        $placedUser = User::where('distributor_id', $request->ba_id)->first();
        if ($placedUser) {
            \App\Models\Order::where('user_id', $placedUser->id)
                ->where('ignite_sync_status', 'pending_placement')
                ->get()
                ->each(function ($order) {
                    \App\Jobs\PostOrderToIgniteJob::dispatch($order->id);
                });
        }

        return response()->json([
            'success' => true,
            'message' => 'Placement successful.',
            'data'    => $response['data'] ?? null,
        ]);
    }

    /**
     * Place one extra TC from the member's TC Bank at a free slot.
     */
    public function placeBankedTc(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ba_id' => 'required|string',
            'side'  => 'required|in:1,2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $mainBaId = $this->normalizeMainBaId($user->distributor_id);
        $side     = (int) $request->side;

        $member  = $this->fetchMemberDetails($mainBaId);
        $slotKey = $side === 1 ? 'far_left_id' : 'far_right_id';
        $slotId  = $member[$slotKey] ?? null;

        if (empty($slotId)) {
            return response()->json([
                'success' => false,
                'message' => 'That side is not available for placement.',
            ], 422);
        }

        try {
            $response = $this->ignite->placeBankedTc(
                baId:            $request->ba_id,
                placementParent: (int) $slotId,
                side:            $side,
            );
        } catch (\Throwable $e) {
            Log::error('Ignite placeBankedTc threw', [
                'ba_id' => $request->ba_id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ignite unreachable',
            ], 503);
        }

        $code = $response['code'] ?? null;

        if ($code === ResultCode::TC_BANK_EMPTY->value) {
            return response()->json([
                'success' => false,
                'message' => 'TC Bank is empty — no TCs to place.',
                'code'    => ResultCode::TC_BANK_EMPTY->value,
            ], 422);
        }

        if ($code === ResultCode::PLACEMENT_DEADLINE_PASSED->value) {
            return response()->json([
                'success' => false,
                'message' => 'Placement deadline has passed. Contact operations to move this TC.',
                'code'    => ResultCode::PLACEMENT_DEADLINE_PASSED->value,
            ], 422);
        }

        if ($code === ResultCode::PLACEMENT_REFUSED->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'That position is not available.',
                'code'    => ResultCode::PLACEMENT_REFUSED->value,
            ], 422);
        }

        if ($code !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to place banked TC',
                'code'    => $code,
            ], 422);
        }

        Cache::forget("ignite_member_{$mainBaId}");
        Cache::forget("ignite_member_{$request->ba_id}");
        Cache::forget("ignite_binary_tree_{$mainBaId}");

        return response()->json([
            'success' => true,
            'message' => 'TC placed successfully.',
            'data'    => $response['data'] ?? null,
        ]);
    }

    /**
     * Pairing commission results for one settled sales week.
     * Every USD money field now carries an INR equivalent.
     */
    public function pairings(Request $request)
    {
        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'year'    => 'required|integer|min:2020|max:2100',
            'week_no' => 'required|integer|min:1|max:53',
            'ba_id'   => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $year   = (int) $request->year;
        $weekNo = (int) $request->week_no;
        $baId   = $request->filled('ba_id') ? trim($request->ba_id) : null;

        try {
            $response = $this->ignite->pairings($year, $weekNo, $baId);
        } catch (\Throwable $e) {
            Log::error('Ignite pairings threw', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ignite unreachable',
            ], 503);
        }

        if (($response['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch pairings',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')->value('value') ?: 0;

        $rows = collect($response['data']['rows'] ?? [])->map(function ($r) use ($usdRate) {
            // USD money fields → add INR
            $r['step_comm_usd']   = (float) ($r['step_comm']   ?? 0);
            $r['step_comm_inr']   = round($r['step_comm_usd'] * $usdRate, 2);

            $r['green_taken_usd'] = (float) ($r['green_taken'] ?? 0);
            $r['green_taken_inr'] = round($r['green_taken_usd'] * $usdRate, 2);

            $r['cash_amount_usd'] = (float) ($r['cash_amount'] ?? 0);
            $r['cash_amount_inr'] = round($r['cash_amount_usd'] * $usdRate, 2);

            $r['exchange_rate']   = $usdRate;

            // CV figures — leave untouched, they are counts
            return $r;
        })->all();

        return response()->json([
            'success'  => true,
            'currency' => ['base' => 'USD', 'display' => 'INR', 'exchange_rate' => $usdRate],
            'data'     => $rows,
            'total'    => count($rows),
            'week'     => ['year' => $year, 'week_no' => $weekNo],
        ]);
    }

    // ==================== PRIVATE HELPERS ====================

    private function normalizeMainBaId(string $baId): string
    {
        if (preg_match('/-(002|003)$/', $baId)) {
            return preg_replace('/-(002|003)$/', '-001', $baId);
        }
        return $baId;
    }

    private function fetchMemberDetails(string $baId): array
    {
        return Cache::remember("ignite_member_{$baId}", 60, function () use ($baId) {
            try {
                $resp = $this->ignite->memberDetails($baId);
                if (($resp['code'] ?? null) === ResultCode::OK->value) {
                    return $resp['data'] ?? [];
                }
            } catch (\Throwable $e) {
                Log::warning('memberDetails failed', ['ba_id' => $baId, 'error' => $e->getMessage()]);
            }
            return [];
        });
    }

    private function getWeakerLeg(float $left, float $right): string
    {
        if ($left == 0 && $right == 0) return 'equal';
        if ($left < $right) return 'left';
        if ($right < $left) return 'right';
        return 'equal';
    }

    private function getRankNames(): array
    {
        return Cache::remember('ignite_rank_names', 3600, function () {
            try {
                $resp = $this->ignite->planRanks();
                if (($resp['code'] ?? null) !== ResultCode::OK->value) {
                    return [];
                }
                $ranks = $resp['data']['ranks'] ?? [];
                return collect($ranks)->pluck('name', 'rank_no')->all();
            } catch (\Throwable $e) {
                Log::warning('planRanks failed', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Get the authenticated distributor's direct referrals (level 1).
     */
    public function myReferrals(Request $request)
    {
        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $mainBaId = $this->normalizeMainBaId($user->distributor_id);
        $response = $this->ignite->unilevelTree($mainBaId, 3);

        if (($response['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch referrals',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];
        $referrals = collect($rows)->where('level', '>', 0)->values();

        $perPage = min((int) $request->input('per_page', 20), 50);
        $page    = max((int) $request->input('page', 1), 1);
        $total   = $referrals->count();
        $paginated = $referrals->slice(($page - 1) * $perPage, $perPage)->values();

        $baIds = $paginated->pluck('ba_id')->filter()->values()->all();
        $localUsers = User::whereIn('distributor_id', $baIds)
            ->get(['distributor_id', 'full_name', 'email', 'created_at'])
            ->keyBy('distributor_id');

        $rankNames = $this->getRankNames();

        $referralsByParent = collect($rows)
            ->groupBy('unilevel_parent_ba_id')
            ->map->count();

        $data = $paginated->map(function ($row) use ($localUsers, $rankNames, $referralsByParent) {
            $baId = $row['ba_id'] ?? null;
            $localUser = $baId ? ($localUsers[$baId] ?? null) : null;

            $member = $baId ? $this->fetchMemberDetails($baId) : [];

            $rankNo = $member['rank_no'] ?? 0;
            $cvLeft = (float) ($member['cv_left'] ?? $row['cv_left'] ?? 0);
            $cvRight = (float) ($member['cv_right'] ?? $row['cv_right'] ?? 0);

            return [
                'member_id'   => $row['member_id'] ?? null,
                'ba_id'       => $baId,
                'full_name'   => $localUser?->full_name,
                'email'       => $localUser?->email,
                'rank_no'     => $rankNo,
                'rank_name'   => $rankNames[$rankNo] ?? '—',
                'cv'          => number_format($cvLeft + $cvRight, 2, '.', ''),
                'cv_unit'     => 'CV',
                'referrals'   => $referralsByParent[$baId] ?? 0,
                'status'      => ucfirst($member['membership_status'] ?? '—'),
                'joined_at'   => $localUser?->created_at?->toDateTimeString(),
                'level'       => $row['level'] ?? null,
                'binary_side' => $row['binary_side'] ?? null,
            ];
        })->values()->all();

        return response()->json([
            'success'    => true,
            'data'       => $data,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Get all of the user's accounts.
     */
    public function myAccounts(Request $request)
    {
        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $mainBaId   = $this->normalizeMainBaId($user->distributor_id);
        $baseId     = preg_replace('/-(001|002|003)$/', '', $mainBaId);
        $member     = $this->fetchMemberDetails($mainBaId);
        $myMemberId = $member['member_id'] ?? null;

        $planRanks = Cache::remember('ignite_plan_ranks_full', 3600, function () {
            try {
                $resp = $this->ignite->planRanks();
                return ($resp['code'] ?? null) === ResultCode::OK->value
                    ? ($resp['data']['ranks'] ?? [])
                    : [];
            } catch (\Throwable $e) {
                return [];
            }
        });
        $rankMap = collect($planRanks)->pluck('name', 'rank_no')->toArray();

        $accounts = [];

        foreach (['-001', '-002', '-003'] as $suffix) {
            $baId = $baseId . $suffix;
            $acc  = $this->fetchMemberDetails($baId);

            $rankNo = (int) ($acc['rank_no'] ?? 0);
            $status = strtolower($acc['membership_status'] ?? 'inactive');

            $accounts[] = [
                'account_id' => $baId,
                'member_id'  => $acc['member_id'] ?? null,
                'rank'       => $rankMap[$rankNo] ?? 'Unranked',
                'status'     => ($status === 'active') ? 'Activated' : ucfirst($status),
                'type'       => 'base',
            ];
        }

        if ($myMemberId) {
            $treeResp = $this->ignite->binaryTree($mainBaId, 5);
            $rows     = $treeResp['data']['rows'] ?? [];

            foreach ($rows as $row) {
                if (($row['level'] ?? 0) === 0) {
                    continue;
                }

                if (($row['unilevel_parent_id'] ?? null) !== $myMemberId) {
                    continue;
                }

                $baId = $row['ba_id'] ?? null;
                if (!$baId) {
                    continue;
                }

                if (!str_starts_with($baId, $baseId . '-')) {
                    continue;
                }

                if (collect($accounts)->where('account_id', $baId)->isNotEmpty()) {
                    continue;
                }

                $acc    = $this->fetchMemberDetails($baId);
                $rankNo = (int) ($acc['rank_no'] ?? 0);
                $status = strtolower($acc['membership_status'] ?? 'inactive');

                $accounts[] = [
                    'account_id' => $baId,
                    'member_id'  => $row['member_id'] ?? null,
                    'rank'       => $rankMap[$rankNo] ?? 'Unranked',
                    'status'     => ($status === 'active') ? 'Activated' : ucfirst($status),
                    'type'       => 'extra_tc',
                ];
            }

            foreach (['-004', '-005', '-006'] as $suffix) {
                $baId = $baseId . $suffix;

                if (collect($accounts)->where('account_id', $baId)->isNotEmpty()) {
                    continue;
                }

                $acc = $this->fetchMemberDetails($baId);

                if (!empty($acc) && isset($acc['member_id'])) {
                    $rankNo = (int) ($acc['rank_no'] ?? 0);
                    $status = strtolower($acc['membership_status'] ?? 'inactive');

                    $accounts[] = [
                        'account_id' => $baId,
                        'member_id'  => $acc['member_id'],
                        'rank'       => $rankMap[$rankNo] ?? 'Unranked',
                        'status'     => ($status === 'active') ? 'Activated' : ucfirst($status),
                        'type'       => 'extra_tc',
                    ];
                }
            }
        }

        usort($accounts, fn($a, $b) => strcmp($a['account_id'], $b['account_id']));

        return response()->json([
            'success' => true,
            'data'    => $accounts,
            'total'   => count($accounts),
        ]);
    }

    /**
     * CV Counter — kis week, kis TC account ka CV dikhana hai.
     * CV-only endpoint — no USD/INR conversion needed.
     */
    public function cvCounter(Request $request)
    {
        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json(['success' => false, 'message' => 'Not registered on Ignite'], 422);
        }

        $validator = Validator::make($request->all(), [
            'ba_id'   => 'required|string|max:50',
            'year'    => 'required|integer|min:2020|max:2100',
            'week_no' => 'required|integer|min:1|max:53',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $baId   = trim($request->ba_id);
        $year   = (int) $request->year;
        $weekNo = (int) $request->week_no;

        $member = $this->fetchMemberDetails($baId);

        $pairingRow = null;
        try {
            $resp = $this->ignite->pairings($year, $weekNo, $baId);
            if (($resp['code'] ?? null) === ResultCode::OK->value) {
                $pairingRow = $resp['data']['rows'][0] ?? null;
            }
        } catch (\Throwable $e) {
            Log::warning('pairings failed', ['ba_id' => $baId, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'unit'    => 'CV',
            'data' => [
                'ba_id' => $baId,
                'week'  => ['year' => $year, 'week_no' => $weekNo],
                'current' => [
                    'cv_left'           => $member['cv_left']           ?? '0.0000',
                    'cv_right'          => $member['cv_right']          ?? '0.0000',
                    'floating_cv_left'  => $member['floating_cv_left']  ?? '0.0000',
                    'floating_cv_right' => $member['floating_cv_right'] ?? '0.0000',
                    'total_cv_left'     => $member['total_cv_left']     ?? '0.0000',
                    'total_cv_right'    => $member['total_cv_right']    ?? '0.0000',
                    'rank_no'           => $member['rank_no']           ?? 0,
                    'total_steps'       => $member['total_steps']       ?? 0,
                ],
                'week_result' => $pairingRow,
                'is_settled'  => $pairingRow !== null,
            ],
        ]);
    }
}