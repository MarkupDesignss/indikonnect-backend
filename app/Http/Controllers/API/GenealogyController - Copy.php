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

class Genealogy1Controller extends Controller
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
     *
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
                // Explicit nulls make the guide's "outside IDK" case visible
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

        // Unilevel max depth = 3 per Ignite
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
            'data'    => $rows,
            'total'   => count($rows),
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

        // 1) Pending placements
        $response = $this->ignite->pendingPlacements($mainBaId);

        if (($response['code'] ?? null) !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch pending placements',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];

        // 2) Slot availability from binaryTree (far_left_ba_id / far_right_ba_id live here)
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
     * Body:
     *   - ba_id : the downline BA ID being placed (e.g. AIA...-001)
     *   - side  : 1 (left) or 2 (right)
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

        // Read memberDetails instead of binaryTree for slot discovery
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
                placementParent: (int) $slotId,   // int → placement_member_id
                side:            $side,
            );
        } catch (\Throwable $e) {
            Log::error('Ignite setPlacement threw', [
                'ba_id' => $request->ba_id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Ignite unreachable'], 503);
        }

        // Specific explicit error code handling
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

        // Cache invalidation
        Cache::forget("ignite_member_{$mainBaId}");
        Cache::forget("ignite_member_{$request->ba_id}");
        Cache::forget("ignite_binary_tree_{$mainBaId}");

        // Re-dispatch parked orders
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
     *
     * Uses memberDetails().far_left_id / far_right_id (integers) instead of
     * binaryTree().far_left_ba_id / far_right_ba_id, because the latter are
     * null for outsiders (members belonging to another partner or the
     * Ignite world plan) while the member_id form is always populated.
     *
     * Body:
     *   - ba_id : the main account (-001) whose TC Bank to draw from
     *   - side  : 1 (left) or 2 (right) — the slot on the sponsor's tree
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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

        // 1) Read sponsor's memberDetails to get the free slot
        $member = $this->fetchMemberDetails($mainBaId);

        $slotKey = $side === 1 ? 'far_left_id' : 'far_right_id';
        $slotId  = $member[$slotKey] ?? null;

        if (empty($slotId)) {
            return response()->json([
                'success' => false,
                'message' => 'That side is not available for placement.',
            ], 422);
        }

        // 2) Call Ignite placeBankedTc with the slot as member_id
        try {
            $response = $this->ignite->placeBankedTc(
                baId:            $request->ba_id,
                placementParent: (int) $slotId,   // int → placement_member_id
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

        // 3) Handle specific refusals before the generic fallback
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

        // 4) Invalidate caches so tree/memberDetails reflect the new TC
        Cache::forget("ignite_member_{$mainBaId}");
        Cache::forget("ignite_member_{$request->ba_id}");
        Cache::forget("ignite_binary_tree_{$mainBaId}");

        // 5) Success — data contains the newly created node
        return response()->json([
            'success' => true,
            'message' => 'TC placed successfully.',
            'data'    => $response['data'] ?? null,
        ]);
    }

    /**
     * Pairing commission results for one settled sales week.
     *
     * GET /distributor/pairings?year=2026&week_no=42
     *
     * Query params:
     *   - year      (required, int)   sales year
     *   - week_no   (required, int)   week number within that sales year
     *   - ba_id     (optional, string) one node only; omit for all of this partner's nodes
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

    // ==================== PRIVATE HELPERS ====================

    /**
     * Normalize a BA ID to its main account (-001).
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
     * Fetch rank names from planRanks (cached 1 hour).
     * Returns array: [rank_no => name].
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

        /**
     * Get the authenticated distributor's direct referrals (level 1).
     *
     * Fetches the unilevel tree at depth 1 from Ignite (which includes the
     * distributor themselves at level 0) and filters out the root.
     * Enriches each referral with their local full name and joined date
     * from the users table, since Ignite does not return these fields.
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

            // ✅ Cached memberDetails
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
     * Get all of the user's accounts.
     *
     * Returns the base accounts (-001, -002, -003) and any extra TCs
     * (-004 onward) that were placed under this user's network.
     * Rank names are mapped from the plan ladder.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
        /**
     * Get all of the user's accounts.
     *
     * Returns the base accounts (-001, -002, -003) and any extra TCs
     * (-004 onward) that were placed under this user's network.
     * Rank names are mapped from the plan ladder.
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

        // Fetch rank ladder to map rank_no => name
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

        // 1. Base Accounts (-001, -002, -003)
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

        // 2. Extra TCs (-004, -005, ...)
        if ($myMemberId) {
            // Try to get from binary tree first
            $treeResp = $this->ignite->binaryTree($mainBaId, 5);
            $rows     = $treeResp['data']['rows'] ?? [];

            foreach ($rows as $row) {
                // Skip the root node (self)
                if (($row['level'] ?? 0) === 0) {
                    continue;
                }

                // Only keep nodes directly sponsored by this user
                if (($row['unilevel_parent_id'] ?? null) !== $myMemberId) {
                    continue;
                }

                $baId = $row['ba_id'] ?? null;
                if (!$baId) {
                    continue;
                }

                // ✅ FIX: Only keep nodes that share this user's base ID (e.g., AIA1675197)
                // This filters out referrals' base accounts (e.g., AIA2325349-001)
                if (!str_starts_with($baId, $baseId . '-')) {
                    continue;
                }

                // Skip duplicates already added
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

            // 3. ✅ FALLBACK: Safety net for extra TCs (-004 to -006) 
            // Just in case they are not returned in the binary tree rows (e.g., deeper than level 5)
            foreach (['-004', '-005', '-006'] as $suffix) {
                $baId = $baseId . $suffix;

                // Check if already added from tree traversal
                if (collect($accounts)->where('account_id', $baId)->isNotEmpty()) {
                    continue;
                }

                $acc = $this->fetchMemberDetails($baId);
                
                // If data exists, this extra TC belongs to the user
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

        // Sort by account_id (001, 002, 003, 004...)
        usort($accounts, fn($a, $b) => strcmp($a['account_id'], $b['account_id']));

        return response()->json([
            'success' => true,
            'data'    => $accounts,
            'total'   => count($accounts),
        ]);
    }

    /**
 * CV Counter — kis week, kis TC account ka CV dikhana hai.
 * GET /distributor/genealogy/cv-counter?ba_id=...&year=...&week_no=...
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

    // 1) Live CV from memberDetails
    $member = $this->fetchMemberDetails($baId);

    // 2) Week's pairing result
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