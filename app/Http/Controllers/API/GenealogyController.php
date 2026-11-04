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
     *
     * Retrieves the binary tree from Ignite using the distributor's BA ID.
     * Supports an optional `depth` query parameter (default 3, max 5).
     * Volumes (left/right) are fetched from memberDetails() because
     * binaryTree() rows do NOT include CV volumes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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

        // Normalize -001 (in case user.distributor_id is -002/-003)
        $mainBaId = $this->normalizeMainBaId($user->distributor_id);

        $depth = min(max((int) $request->input('depth', 3), 1), 5);

        // 1) Fetch binary tree from Ignite
        $response = $this->ignite->binaryTree($mainBaId, $depth);

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch binary tree',
                'code'    => $response['code'],
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];

        // Root node (level 0)
        $root = collect($rows)->firstWhere('level', 0);

        // 2) Volumes come from memberDetails, NOT binaryTree rows
        $member     = $this->fetchMemberDetails($mainBaId);
        $leftVolume  = (float) ($member['cv_left']  ?? 0) + (float) ($member['floating_cv_left']  ?? 0);
        $rightVolume = (float) ($member['cv_right'] ?? 0) + (float) ($member['floating_cv_right'] ?? 0);

        return response()->json([
            'success' => true,
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
     *
     * Lazy-load friendly: accepts `ba_id` directly (preferred) OR `userId`
     * (fallback, looks up local User.distributor_id).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|null  $userId
     * @return \Illuminate\Http\JsonResponse
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

        // Preferred: ba_id from query/body
        $baId = $request->input('ba_id');

        // Fallback: local userId → distributor_id
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

        // Fetch depth-1 binary tree from Ignite
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
     *
     * Scoped to the unilevel tree (max 3 levels per Ignite).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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
     * Enriches Ignite data with local user details (name, status, rank).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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

        if (($response['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch downline',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];

        // Fetch Ignite Pending Placements (to get real names for missing local users)
        $igniteNames = [];
        try {
            $pendingResp = $this->ignite->pendingPlacements($mainBaId);
            if (($pendingResp['code'] ?? null) === ResultCode::OK->value) {
                $pendingRows = $pendingResp['data']['rows'] ?? [];
                foreach ($pendingRows as $pRow) {
                    if (!empty($pRow['ba_id']) && !empty($pRow['fullname'])) {
                        $igniteNames[$pRow['ba_id']] = $pRow['fullname'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore if pendingPlacements fails
        }

        $downlines = collect($rows)->map(function ($row) use ($igniteNames) {
            if (($row['level'] ?? 0) === 0) {
                return null; // Skip root
            }

            $baId = $row['ba_id'] ?? null;
            $localUser = $baId ? User::where('distributor_id', $baId)->first() : null;
            $memberDetails = $baId ? $this->fetchMemberDetails($baId) : [];

            // Try Local DB Name -> Ignite Pending Name -> Fallback
            $name = $localUser?->full_name 
                 ?? ($igniteNames[$baId] ?? null) 
                 ?? 'Ignite Member';

            return [
                'member_id'       => $row['member_id'] ?? null,
                'ba_id'           => $baId,
                'full_name'       => $name,
                'email'           => $localUser?->email ?? null,
                'phone'           => $localUser?->phone ?? null,
                'joined_at'       => $localUser?->created_at?->format('d/m/Y') ?? null,
                'rank_no'         => $memberDetails['rank_no'] ?? 0,
                'status'          => ucfirst($memberDetails['membership_status'] ?? 'Inactive'),
                'level'           => $row['level'] ?? null,
                'binary_side'     => $row['binary_side'] ?? null,
                'cv_left'         => $row['cv_left'] ?? '0.0000',
                'cv_right'        => $row['cv_right'] ?? '0.0000',
                'floating_cv_left'=> $row['floating_cv_left'] ?? '0.0000',
                'floating_cv_right'=> $row['floating_cv_right'] ?? '0.0000',
            ];
        })->filter()->values();

        return response()->json([
            'success' => true,
            'data'    => $downlines,
            'total'   => $downlines->count(),
        ]);
    }

    /**
     * Get pending placements for the authenticated sponsor.
     *
     * Includes slot availability info (far_left/far_right free or not)
     * so the frontend can show "Left slot free / Right occupied".
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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
     *
     * Uses memberDetails().far_left_id / far_right_id (integers) instead of
     * binaryTree().far_left_ba_id / far_right_ba_id, because the latter are
     * null for outsiders (members belonging to another partner or the
     * Ignite world plan) while the member_id form is always populated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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

        $member = $this->fetchMemberDetails($mainBaId);
        
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
     * Seat one extra TC from a main account's TC Bank at a specific position in the binary tree.
     * 
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function placeBankedTc(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ba_id'               => 'required|string',
            'side'                => 'required|in:1,2',
            'placement_ba_id'     => 'nullable|string',  
            'placement_member_id' => 'nullable|integer', 
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

        $requestedBaId = $request->ba_id;
        $baseId = preg_replace('/-(001|002|003)$/', '', $mainBaId);
        $allowedBaIds = [$baseId . '-001', $baseId . '-002', $baseId . '-003'];

        if (!in_array($requestedBaId, $allowedBaIds)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only place TCs from your own account.',
            ], 403);
        }

        $placementParent = null;

        if ($request->filled('placement_ba_id')) {
            $placementParent = $request->placement_ba_id;
        } 
        elseif ($request->filled('placement_member_id')) {
            $placementParent = (int) $request->placement_member_id;
        } 
        else {
            $member  = $this->fetchMemberDetails($mainBaId);
            $slotKey = $side === 1 ? 'far_left_id' : 'far_right_id';
            $slotId  = $member[$slotKey] ?? null;

            if (empty($slotId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'That side is not available for placement.',
                ], 422);
            }
            $placementParent = (int) $slotId;
        }

        try {
            $response = $this->ignite->placeBankedTc(
                baId:            $requestedBaId,
                placementParent: $placementParent, 
                side:            $side,
            );
        } catch (\Throwable $e) {
            Log::error('Ignite placeBankedTc threw', [
                'ba_id' => $requestedBaId,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ignite unreachable. Please try again later.',
            ], 503);
        }

        $code = $response['code'] ?? null;

        if ($code === ResultCode::TC_BANK_EMPTY->value) {
            return response()->json([
                'success' => false,
                'message' => 'TC Bank is empty. You have no extra TCs to place.',
                'code'    => ResultCode::TC_BANK_EMPTY->value,
            ], 422);
        }

        if ($code === ResultCode::PLACEMENT_DEADLINE_PASSED->value) {
            return response()->json([
                'success' => false,
                'message' => 'Placement deadline has passed. Please contact Ignite operations.',
                'code'    => ResultCode::PLACEMENT_DEADLINE_PASSED->value,
            ], 422);
        }

        if ($code === ResultCode::PLACEMENT_REFUSED->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'That position is not available. It might be taken or held.',
                'code'    => ResultCode::PLACEMENT_REFUSED->value,
            ], 422);
        }

        if ($code === ResultCode::NETWORK_BUSY->value) {
            return response()->json([
                'success' => false,
                'message' => 'Network is busy. Another placement is in progress. Please try again.',
                'code'    => ResultCode::NETWORK_BUSY->value,
            ], 503);
        }

        if ($code !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to place banked TC.',
                'code'    => $code,
            ], 422);
        }

        Cache::forget("ignite_member_{$mainBaId}");
        Cache::forget("ignite_member_{$requestedBaId}");
        Cache::forget("ignite_binary_tree_{$mainBaId}");

        return response()->json([
            'success' => true,
            'message' => 'TC placed successfully at the selected position.',
            'data'    => $response['data'] ?? null,
        ]);
    }

    /**
     * Pairing commission results for one settled sales week.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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

        return response()->json([
            'success' => true,
            'data'    => $response['data']['rows'] ?? [],
            'total'   => count($response['data']['rows'] ?? []),
            'week'    => ['year' => $year, 'week_no' => $weekNo],
        ]);
    }

    /**
     * Get all of the user's accounts (Base and Extra TCs).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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
     * Get complete TC Bank dashboard data for the UI.
     * Includes balances, qualification status, accounts, available placements, and transactions.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function tcBankDashboard(Request $request)
    {
        $user = Auth::user();

        if (!$user->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        $mainBaId = $this->normalizeMainBaId($user->distributor_id);
        $baseId   = preg_replace('/-(001|002|003)$/', '', $mainBaId);

        // 1. Fetch Member Details (for balances and TC)
        $member = $this->fetchMemberDetails($mainBaId);
        
        $tcBankAvailable = (int) ($member['tc_bank_available'] ?? 0);

        // 2. Fetch Plan Ranks for Zone TC CV Price (600 CV)
        $planResp = Cache::remember('ignite_plan_ranks', 3600, function () {
            try {
                $resp = $this->ignite->planRanks();
                return ($resp['code'] ?? null) === ResultCode::OK->value ? ($resp['data']['plan'] ?? []) : [];
            } catch (\Throwable $e) {
                return [];
            }
        });
        
        $zoneTcCvPrice = (float) ($planResp['base_product_cv'] ?? 600);

        // 3. Fetch My Accounts (Base + Extra TCs)
        $accounts = [];
        foreach (['-001', '-002', '-003'] as $suffix) {
            $baId = $baseId . $suffix;
            $acc  = $this->fetchMemberDetails($baId);
            
            $accounts[] = [
                'account_id' => $baId,
                'member_id'  => $acc['member_id'] ?? null,
                'rank_no'    => $acc['rank_no'] ?? 0,
                'status'     => ucfirst($acc['membership_status'] ?? 'Inactive'),
                'qualified'  => !empty($acc['qualified_at']),
                'type'       => 'base',
            ];
        }

        // 4. Fetch Available Placements for TC Allocation Dropdown
        try {
            $treeResp = $this->ignite->binaryTree($mainBaId, 5);
            $treeRows = $treeResp['data']['rows'] ?? [];
            $availableSlots = [];
            
            foreach ($treeRows as $row) {
                $nodeBaId = $row['ba_id'] ?? null;
                if (empty($nodeBaId) || str_ends_with($nodeBaId, '-001')) continue;

                $isLeftFree = empty($row['far_left_id']) && empty($row['far_left_ba_id']);
                $isRightFree = empty($row['far_right_id']) && empty($row['far_right_ba_id']);

                if ($isLeftFree || $isRightFree) {
                    $availableSlots[] = [
                        'ba_id' => $nodeBaId,
                        'member_id' => $row['member_id'] ?? null,
                        'display_label' => $nodeBaId,
                        'left_available' => $isLeftFree,
                        'right_available' => $isRightFree,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $availableSlots = [];
        }

        // 5. Fetch TC Bank Transaction History
        $transactions = [];
        try {
            $txResp = $this->ignite->pointTxs($mainBaId, 0, 100);
            $txRows = $txResp['data']['rows'] ?? [];
            
            foreach ($txRows as $tx) {
                if (($tx['point_type'] ?? '') === 'tc_bank') {
                    $transactions[] = [
                        'date'          => \Carbon\Carbon::parse($tx['created_at'])->format('d/m/Y'),
                        'category'      => ucfirst($tx['tx_type'] ?? 'Other'),
                        'description'   => $tx['description'] ?? '-',
                        'tc_bank_change'=> (int) $tx['amount'],
                        'order_id'      => $tx['order_no'] ?? '-',
                        'related_member'=> $tx['rel_ba_id'] ?? '-',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        // Qualified status (How many accounts are qualified out of 3)
        $qualifiedCount = collect($accounts)->where('qualified', true)->count();
        $qualifiedStatus = "{$qualifiedCount} / 3";

        return response()->json([
            'success' => true,
            'data' => [
                'balances' => [
                    'available_balance' => $tcBankAvailable,
                    'qualified_status'  => $qualifiedStatus,
                    'zone_tc_cv_price'  => $zoneTcCvPrice,
                ],
                'my_accounts'         => $accounts,
                'available_slots'     => $availableSlots,
                'tc_transactions'     => $transactions,
            ]
        ]);
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Normalize a BA ID to its main account (-001).
     *
     * @param  string  $baId
     * @return string
     */
    private function normalizeMainBaId(string $baId): string
    {
        if (preg_match('/-(002|003)$/', $baId)) {
            return preg_replace('/-(002|003)$/', '-001', $baId);
        }
        return $baId;
    }

    /**
     * Fetch memberDetails() with a short cache. Returns [] on error.
     *
     * @param  string  $baId
     * @return array
     */
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

    /**
     * Determine the weaker leg based on left and right volumes.
     *
     * @param  float  $left
     * @param  float  $right
     * @return string  'left', 'right', or 'equal'
     */
    private function getWeakerLeg(float $left, float $right): string
    {
        if ($left == 0 && $right == 0) return 'equal';
        if ($left < $right) return 'left';
        if ($right < $left) return 'right';
        return 'equal';
    }

    /**
     * Get the authenticated distributor's direct referrals (level 1).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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

        // Pagination
        $perPage = min((int) $request->input('per_page', 20), 50);
        $page    = max((int) $request->input('page', 1), 1);
        $total   = $referrals->count();
        $paginated = $referrals->slice(($page - 1) * $perPage, $perPage)->values();

        // Enrich only paginated rows
        $baIds = $paginated->pluck('ba_id')->filter()->values()->all();
        $localUsers = User::whereIn('distributor_id', $baIds)
            ->get(['distributor_id', 'full_name', 'email', 'created_at'])
            ->keyBy('distributor_id');

        $rankNames = $this->getRankNames();

        // Referrals count map (from full tree)
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
     * Fetch rank names from planRanks (cached 1 hour).
     * Returns array: [rank_no => name].
     *
     * @return array
     */
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
}