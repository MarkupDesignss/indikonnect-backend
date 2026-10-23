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

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch pending placements',
                'code'    => $response['code'],
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];

        // 2) Slot availability from memberDetails
        $member = $this->fetchMemberDetails($mainBaId);

        $slots = [
            'far_left_ba_id'  => $member['far_left_ba_id']  ?? null,
            'far_right_ba_id' => $member['far_right_ba_id'] ?? null,
            'far_left_id'     => $member['far_left_id']     ?? null,
            'far_right_id'    => $member['far_right_id']    ?? null,
            'left_available'  => empty($member['far_left_ba_id']),
            'right_available' => empty($member['far_right_ba_id']),
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
     * Per Ignite guide: to place at the end of a leg, send
     * far_left_id (side 1) or far_right_id (side 2) as
     * `placement_member_id`.
     *
     * The sponsor BA ID is used only to fetch its far_left_id/far_right_id
     * from memberDetails(); we then send that slot's member id.
     *
     * Body:
     *   - ba_id   : the downline BA ID being placed (e.g. AIA...-001)
     *   - side    : 1 (left) or 2 (right)
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

        // 1) Read sponsor's member details to get the free slot
        $sponsor = $this->fetchMemberDetails($mainBaId);

        $slotKey = $side === 1 ? 'far_left_id' : 'far_right_id';
        $slotBa  = $side === 1 ? 'far_left_ba_id' : 'far_right_ba_id';
        $slotId  = $sponsor[$slotKey] ?? null;

        if (empty($slotId)) {
            return response()->json([
                'success' => false,
                'message' => 'That side is not available for placement (already occupied or belongs to a member outside IDK).',
                'slot'    => [
                    'side'     => $side,
                    'ba_id'    => $sponsor[$slotBa] ?? null,
                    'member_id'=> $slotId,
                ],
            ], 422);
        }

        // 2) Call Ignite setPlacement with placement_member_id
        //    NOTE: adjust named args if SDK signature differs.
        $response = $this->ignite->setPlacement(
            baId:              $request->ba_id,
            placementMemberId: (int) $slotId,
            side:              $side,
        );

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Placement failed',
                'code'    => $response['code'],
            ], 422);
        }

        // 3) Invalidate caches so tree/memberDetails reflect placement
        Cache::forget("ignite_member_{$mainBaId}");
        Cache::forget("ignite_member_{$request->ba_id}");
        Cache::forget("ignite_binary_tree_{$mainBaId}");

        // 4) Re-dispatch any parked orders for this newly-placed member
        $placedUser = User::where('distributor_id', $request->ba_id)->first();
        if ($placedUser) {
            \App\Models\Order::where('user_id', $placedUser->id)
                ->where('ignite_sync_status', 'pending_placement')
                ->get()
                ->each(function ($order) {
                    \App\Jobs\PostOrderToIgniteJob::dispatch($order->id);
                    Log::info('Re-dispatched parked Ignite job after placement', [
                        'order' => $order->order_reference,
                    ]);
                });
        }

        return response()->json([
            'success' => true,
            'message' => 'Placement successful.',
            'data'    => $response['data'] ?? null,
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
}