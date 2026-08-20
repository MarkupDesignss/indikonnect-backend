<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\GenealogyPlacement;
use App\Models\CommissionLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GenealogyController extends Controller
{
    /**
     * Get the binary tree for the authenticated distributor.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function tree(Request $request)
    {
        $user = Auth::user();
        $depth = (int) $request->input('depth', 3);
        $depth = min($depth, 10); // Safety limit

        // Get root placement
        $rootPlacement = GenealogyPlacement::where('user_id', $user->id)->first();

        if (!$rootPlacement) {
            return response()->json([
                'success' => false,
                'message' => 'Genealogy placement not found for this distributor. Please contact support.'
            ], 404);
        }

        // Build the tree recursively
        $tree = $this->buildTree($user->id, 0, $depth);

        // Compute leg volumes (left and right)
        $volumes = $this->computeLegVolumes($user->id);

        // Get counts
        $leftCount = GenealogyPlacement::where('sponsor_id', $user->id)
            ->where('position', 'left')
            ->where('status', 'active')
            ->count();

        $rightCount = GenealogyPlacement::where('sponsor_id', $user->id)
            ->where('position', 'right')
            ->where('status', 'active')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'root' => $tree,
                'left_volume' => $volumes['left'] ?? 0,
                'right_volume' => $volumes['right'] ?? 0,
                'left_count' => $leftCount,
                'right_count' => $rightCount,
                'weaker_leg' => $this->getWeakerLeg($volumes['left'] ?? 0, $volumes['right'] ?? 0),
                'depth' => $depth,
                'has_more' => $this->hasMoreLevels($user->id, $depth),
            ]
        ]);
    }

    /**
     * Get direct children (left and right) of a specific node.
     * Used for lazy loading when expanding a node.
     *
     * @param Request $request
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function children(Request $request, int $userId)
    {
        $authUser = Auth::user();

        // Verify that the requested node is within the authenticated user's downline
        if (!$this->isInDownline($authUser->id, $userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view this node\'s children.'
            ], 403);
        }

        $children = GenealogyPlacement::where('sponsor_id', $userId)
            ->where('status', 'active')
            ->with('user')
            ->get();

        $left = null;
        $right = null;

        foreach ($children as $child) {
            $formatted = $this->formatNode($child);
            if ($child->position === 'left') {
                $left = $formatted;
            } elseif ($child->position === 'right') {
                $right = $formatted;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'left' => $left,
                'right' => $right,
                'node_id' => $userId,
            ]
        ]);
    }

    /**
     * Search for a node by name or distributor_id within the downline.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $user = Auth::user();
        $query = $request->input('query');

        if (empty($query)) {
            return response()->json([
                'success' => false,
                'message' => 'Query parameter is required.'
            ], 422);
        }

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters.'
            ], 422);
        }

        // Find downline user IDs (excluding root)
        $downlineIds = $this->getDownlineIds($user->id);

        if (empty($downlineIds)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No downline members found.'
            ]);
        }

        // Search by name or distributor_id among downline
        $users = User::whereIn('id', $downlineIds)
            ->where(function ($q) use ($query) {
                $q->where('full_name', 'like', "%{$query}%")
                  ->orWhere('distributor_id', 'like', "%{$query}%");
            })
            ->limit(20)
            ->get();

        $results = $users->map(function ($user) {
            // Get position and level
            $placement = GenealogyPlacement::where('user_id', $user->id)->first();
            $path = $this->getPathToNode($user->id);

            return [
                'id' => $user->id,
                'name' => $user->full_name,
                'distributor_id' => $user->distributor_id,
                'level' => $placement->level ?? 0,
                'position' => $placement->position ?? null,
                'path' => $path,
                'joined_at' => $user->created_at->toDateString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $results,
            'total' => $results->count(),
        ]);
    }

    /**
     * Get downline list (flat view) with pagination.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function downlineList(Request $request)
    {
        $user = Auth::user();
        $perPage = (int) $request->input('per_page', 20);
        $level = $request->input('level');
        $position = $request->input('position');

        $query = GenealogyPlacement::where('sponsor_id', $user->id)
            ->where('status', 'active')
            ->with('user');

        if ($level !== null) {
            $query->where('level', (int) $level);
        }

        if ($position && in_array($position, ['left', 'right'])) {
            $query->where('position', $position);
        }

        $downline = $query->orderBy('level')
            ->orderBy('position')
            ->paginate($perPage);

        $data = $downline->map(function ($placement) {
            return $this->formatNode($placement);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $downline->total(),
                'per_page' => $downline->perPage(),
                'current_page' => $downline->currentPage(),
                'last_page' => $downline->lastPage(),
            ],
        ]);
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Recursively build the tree up to a certain depth.
     */
    private function buildTree($userId, int $currentDepth, int $maxDepth)
    {
        $placement = GenealogyPlacement::where('user_id', $userId)->first();

        if (!$placement || $placement->status !== 'active') {
            return null;
        }

        $node = $this->formatNode($placement);

        if ($currentDepth >= $maxDepth) {
            // Check if node has children (for expansion indicator)
            $node['has_children'] = GenealogyPlacement::where('sponsor_id', $userId)
                ->where('status', 'active')
                ->exists();
            $node['left'] = null;
            $node['right'] = null;
            return $node;
        }

        // Get children (left and right)
        $children = GenealogyPlacement::where('sponsor_id', $userId)
            ->where('status', 'active')
            ->get();

        $node['left'] = null;
        $node['right'] = null;

        foreach ($children as $child) {
            if ($child->position === 'left') {
                $node['left'] = $this->buildTree($child->user_id, $currentDepth + 1, $maxDepth);
            } elseif ($child->position === 'right') {
                $node['right'] = $this->buildTree($child->user_id, $currentDepth + 1, $maxDepth);
            }
        }

        $node['has_children'] = !empty($children);

        return $node;
    }

    /**
     * Format a genealogy placement node for JSON response.
     */
    private function formatNode(GenealogyPlacement $placement): array
    {
        $user = $placement->user;

        return [
            'id' => $placement->user_id,
            'name' => $user ? $user->full_name : 'Unknown',
            'distributor_id' => $user ? $user->distributor_id : null,
            'level' => $placement->level,
            'position' => $placement->position,
            'status' => $placement->status,
            'joined_at' => $user ? $user->created_at?->toDateString() : null,
            'volume' => $this->getUserVolume($placement->user_id),
            'has_children' => GenealogyPlacement::where('sponsor_id', $placement->user_id)
                ->where('status', 'active')
                ->exists(),
        ];
    }

    /**
     * Get total CV volume for a user (from commission_ledger).
     * Cached for performance.
     */
    private function getUserVolume(int $userId): float
    {
        $cacheKey = "genealogy_volume_{$userId}";
        return (float) Cache::remember($cacheKey, 300, function () use ($userId) {
            return CommissionLedger::where('distributor_id', $userId)
                ->whereIn('status', ['released', 'pending'])
                ->sum('gross_amount');
        });
    }

    /**
     * Compute left and right leg volumes for a given root user.
     */
    private function computeLegVolumes(int $rootUserId): array
    {
        $leftVolume = 0;
        $rightVolume = 0;

        // Get left child
        $leftChild = GenealogyPlacement::where('sponsor_id', $rootUserId)
            ->where('position', 'left')
            ->where('status', 'active')
            ->first();

        if ($leftChild) {
            $leftVolume = $this->sumTreeVolume($leftChild->user_id);
        }

        // Get right child
        $rightChild = GenealogyPlacement::where('sponsor_id', $rootUserId)
            ->where('position', 'right')
            ->where('status', 'active')
            ->first();

        if ($rightChild) {
            $rightVolume = $this->sumTreeVolume($rightChild->user_id);
        }

        return [
            'left' => $leftVolume,
            'right' => $rightVolume,
        ];
    }

    /**
     * Recursively sum the volumes of all users in a subtree.
     */
    private function sumTreeVolume(int $userId): float
    {
        $volume = $this->getUserVolume($userId);

        $children = GenealogyPlacement::where('sponsor_id', $userId)
            ->where('status', 'active')
            ->get();

        foreach ($children as $child) {
            $volume += $this->sumTreeVolume($child->user_id);
        }

        return $volume;
    }

    /**
     * Determine which leg is weaker.
     */
    private function getWeakerLeg(float $left, float $right): string
    {
        if ($left == 0 && $right == 0) return 'equal';
        if ($left < $right) return 'left';
        if ($right < $left) return 'right';
        return 'equal';
    }

    /**
     * Check if there are more levels beyond the current depth.
     */
    private function hasMoreLevels(int $userId, int $currentDepth): bool
    {
        // Check if any node at the current depth has children
        return GenealogyPlacement::where('sponsor_id', $userId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Check if a node is in the downline of a given root user (BFS).
     */
    private function isInDownline(int $rootId, int $targetId): bool
    {
        if ($rootId == $targetId) {
            return true;
        }

        $queue = [$rootId];
        $visited = [];

        while (!empty($queue)) {
            $current = array_shift($queue);

            if (in_array($current, $visited)) {
                continue;
            }
            $visited[] = $current;

            $children = GenealogyPlacement::where('sponsor_id', $current)
                ->where('status', 'active')
                ->pluck('user_id')
                ->toArray();

            if (in_array($targetId, $children)) {
                return true;
            }

            $queue = array_merge($queue, $children);
        }

        return false;
    }

    /**
     * Get all downline user IDs (including nested).
     */
    private function getDownlineIds(int $rootId): array
    {
        $downline = [];
        $queue = [$rootId];

        while (!empty($queue)) {
            $current = array_shift($queue);
            $children = GenealogyPlacement::where('sponsor_id', $current)
                ->where('status', 'active')
                ->pluck('user_id')
                ->toArray();

            if (!empty($children)) {
                $downline = array_merge($downline, $children);
                $queue = array_merge($queue, $children);
            }
        }

        return $downline;
    }

    /**
     * Get the path (ancestors) from root to a given node.
     */
    private function getPathToNode(int $targetId): array
    {
        $path = [];
        $current = $targetId;

        // Get ancestors by climbing up the genealogy
        while (true) {
            $placement = GenealogyPlacement::where('user_id', $current)->first();
            if (!$placement || !$placement->sponsor_id) {
                break;
            }

            $sponsor = User::find($placement->sponsor_id);
            if ($sponsor) {
                $path[] = [
                    'id' => $sponsor->id,
                    'name' => $sponsor->full_name,
                    'distributor_id' => $sponsor->distributor_id,
                ];
            }
            $current = $placement->sponsor_id;
        }

        // Reverse to show root first
        return array_reverse($path);
    }
}