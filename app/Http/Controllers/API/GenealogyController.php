<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
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

        $depth = min((int) $request->input('depth', 3), 5);

        // Fetch binary tree from Ignite
        $response = $this->ignite->binaryTree($user->distributor_id, $depth);

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

        // Compute leg volumes from the root node
        $leftVolume  = (float) ($root['total_cv_left']  ?? 0);
        $rightVolume = (float) ($root['total_cv_right'] ?? 0);

        return response()->json([
            'success' => true,
            'data' => [
                'root'         => $root,
                'rows'         => $rows,
                'left_volume'  => number_format($leftVolume, 4, '.', ''),
                'right_volume' => number_format($rightVolume, 4, '.', ''),
                'weaker_leg'   => $this->getWeakerLeg($leftVolume, $rightVolume),
                'depth'        => $depth,
                'total_nodes'  => count($rows),
            ],
        ]);
    }

    /**
     * Get the direct children (left and right) of a specific node.
     *
     * Used for lazy loading of the binary tree. Fetches a depth-1 binary tree
     * from Ignite for the given user and returns the left/right children.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function children(Request $request, int $userId)
    {
        $authUser = Auth::user();

        if (!$authUser->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered on Ignite yet.',
            ], 422);
        }

        // Find the target user
        $targetUser = User::find($userId);

        if (!$targetUser || !$targetUser->distributor_id) {
            return response()->json([
                'success' => false,
                'message' => 'Node not found or not registered on Ignite.',
            ], 404);
        }

        // Fetch depth-1 binary tree from Ignite
        $response = $this->ignite->binaryTree($targetUser->distributor_id, 1);

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch children',
                'code'    => $response['code'],
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];

        // Level 1 rows = direct children
        $children = collect($rows)->where('level', 1)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'left'   => $children->firstWhere('binary_side', 1),
                'right'  => $children->firstWhere('binary_side', 2),
                'userId' => $userId,
            ],
        ]);
    }

    /**
     * Search for a distributor by BA ID within the authenticated user's downline.
     *
     * Fetches the unilevel tree from Ignite and filters results locally
     * by BA ID. Limited to 20 results.
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

        $query = $request->input('query');

        if (empty($query) || strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Query must be at least 2 characters.',
            ], 422);
        }

        // Fetch unilevel tree from Ignite
        $response = $this->ignite->unilevelTree($user->distributor_id, 3);

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Search failed',
                'code'    => $response['code'],
            ], 422);
        }

        $rows = $response['data']['rows'] ?? [];

        // Filter locally by BA ID
        $results = collect($rows)
            ->filter(function ($row) use ($query) {
                return stripos($row['ba_id'] ?? '', $query) !== false;
            })
            ->values()
            ->take(20);

        return response()->json([
            'success' => true,
            'data'    => $results,
            'total'   => $results->count(),
        ]);
    }

    /**
     * Get a flat list of the authenticated user's unilevel downline.
     *
     * Fetches the unilevel tree from Ignite. Supports an optional `depth`
     * query parameter (default 3, max 3 as per Ignite limits).
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

        $depth = min((int) $request->input('depth', 3), 3);

        // Fetch unilevel tree from Ignite
        $response = $this->ignite->unilevelTree($user->distributor_id, $depth);

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
     * Get the list of pending placements for the authenticated sponsor.
     *
     * Returns downlines that are registered but not yet placed in the
     * binary tree. Fetched from Ignite using `pendingPlacements()`.
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

        // Fetch pending placements from Ignite
        $response = $this->ignite->pendingPlacements($user->distributor_id);

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to fetch pending placements',
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
     * Set the binary placement for a downline.
     *
     * Places a downline under the authenticated sponsor at the specified
     * side (1 = left, 2 = right). Uses Ignite's `setPlacement()`.
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

        // Set placement on Ignite
        $response = $this->ignite->setPlacement(
            baId:            $request->ba_id,
            placementParent: $user->distributor_id,
            side:            (int) $request->side,
        );

        if ($response['code'] !== ResultCode::OK->value) {
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Placement failed',
                'code'    => $response['code'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Placement successful.',
            'data'    => $response['data'],
        ]);
    }

    // ==================== PRIVATE HELPERS ====================

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