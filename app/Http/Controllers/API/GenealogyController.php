<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\GenealogyPlacement;
use App\Models\CommissionLedger;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GenealogyController extends Controller
{
    /**
     * Get the binary tree for the authenticated distributor.
     * Supports ?depth=3 (default 3, max 10).
     */
    public function tree(Request $request)
    {
        $user = Auth::user();
        $depth = min((int) $request->input('depth', 3), 10);

        $rootPlacement = GenealogyPlacement::where('user_id', $user->id)->first();
        if (!$rootPlacement) {
            return response()->json([
                'success' => false,
                'message' => 'Genealogy placement not found.'
            ], 404);
        }

        // Build recursive tree
        $tree = $this->buildTree($user->id, 0, $depth);

        // Compute leg volumes
        $volumes = $this->computeLegVolumes($user->id);

        // Count direct children per leg
        $leftCount = GenealogyPlacement::where('sponsor_id', $user->id)
            ->where('position', 'left')->where('status', 'active')->count();
        $rightCount = GenealogyPlacement::where('sponsor_id', $user->id)
            ->where('position', 'right')->where('status', 'active')->count();

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
     * Lazy loading: Get direct children (left and right) of a specific node.
     */
    public function children(Request $request, int $userId)
    {
        $authUser = Auth::user();

        // Security: ensure the requested node is within the auth user's downline
        if (!$this->isInDownline($authUser->id, $userId)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view this node.'
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
            if ($child->position === 'left') $left = $formatted;
            else $right = $formatted;
        }

        return response()->json([
            'success' => true,
            'data' => compact('left', 'right', 'userId')
        ]);
    }

    /**
     * Search for a distributor by name or distributor_id within downline.
     */
    public function search(Request $request)
    {
        $user = Auth::user();
        $query = $request->input('query');

        if (empty($query) || strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Query must be at least 2 characters.'
            ], 422);
        }

        $downlineIds = $this->getDownlineIds($user->id);
        if (empty($downlineIds)) {
            return response()->json(['success' => true, 'data' => [], 'total' => 0]);
        }

        $results = User::whereIn('id', $downlineIds)
            ->where(function ($q) use ($query) {
                $q->where('full_name', 'LIKE', "%{$query}%")
                  ->orWhere('distributor_id', 'LIKE', "%{$query}%");
            })
            ->limit(20)
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->full_name,
                'distributor_id' => $u->distributor_id,
                'level' => GenealogyPlacement::where('user_id', $u->id)->value('level'),
                'position' => GenealogyPlacement::where('user_id', $u->id)->value('position'),
                'joined_at' => $u->created_at->toDateString(),
                'path' => $this->getPathToNode($u->id),
            ]);

        return response()->json(['success' => true, 'data' => $results, 'total' => $results->count()]);
    }

    /**
     * Flat list of downline with pagination.
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

        if ($level !== null) $query->where('level', (int)$level);
        if ($position && in_array($position, ['left', 'right'])) $query->where('position', $position);

        $downline = $query->orderBy('level')->orderBy('position')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $downline->map(fn($p) => $this->formatNode($p)),
            'pagination' => [
                'total' => $downline->total(),
                'per_page' => $downline->perPage(),
                'current_page' => $downline->currentPage(),
                'last_page' => $downline->lastPage(),
            ]
        ]);
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Recursively build tree up to maxDepth.
     */
    private function buildTree($userId, $currentDepth, $maxDepth)
    {
        $placement = GenealogyPlacement::where('user_id', $userId)->first();
        if (!$placement || $placement->status !== 'active') return null;

        $node = $this->formatNode($placement);

        if ($currentDepth >= $maxDepth) {
            $node['has_children'] = GenealogyPlacement::where('sponsor_id', $userId)
                ->where('status', 'active')->exists();
            $node['left'] = $node['right'] = null;
            return $node;
        }

        $children = GenealogyPlacement::where('sponsor_id', $userId)
            ->where('status', 'active')->get();

        $node['left'] = $children->firstWhere('position', 'left')
            ? $this->buildTree($children->firstWhere('position', 'left')->user_id, $currentDepth + 1, $maxDepth)
            : null;

        $node['right'] = $children->firstWhere('position', 'right')
            ? $this->buildTree($children->firstWhere('position', 'right')->user_id, $currentDepth + 1, $maxDepth)
            : null;

        $node['has_children'] = $children->isNotEmpty();
        return $node;
    }

    /**
     * Format a placement node for JSON.
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
                ->where('status', 'active')->exists(),
        ];
    }

    /**
     * Get total CV volume for a user (cached for 5 minutes).
     */
    private function getUserVolume(int $userId): float
    {
        return Cache::remember("genealogy_volume_{$userId}", 300, function () use ($userId) {
            return CommissionLedger::where('distributor_id', $userId)
                ->whereIn('status', ['released', 'pending'])
                ->sum('gross_amount');
        });
    }

    /**
     * Compute left and right leg volumes for a root user.
     */
    private function computeLegVolumes(int $rootUserId): array
    {
        $left = 0;
        $right = 0;

        $leftChild = GenealogyPlacement::where('sponsor_id', $rootUserId)
            ->where('position', 'left')->where('status', 'active')->first();
        if ($leftChild) $left = $this->sumTreeVolume($leftChild->user_id);

        $rightChild = GenealogyPlacement::where('sponsor_id', $rootUserId)
            ->where('position', 'right')->where('status', 'active')->first();
        if ($rightChild) $right = $this->sumTreeVolume($rightChild->user_id);

        return ['left' => $left, 'right' => $right];
    }

    /**
     * Recursively sum volumes of an entire subtree.
     */
    private function sumTreeVolume(int $userId): float
    {
        $volume = $this->getUserVolume($userId);
        $children = GenealogyPlacement::where('sponsor_id', $userId)
            ->where('status', 'active')->get();
        foreach ($children as $child) {
            $volume += $this->sumTreeVolume($child->user_id);
        }
        return $volume;
    }

    private function getWeakerLeg(float $left, float $right): string
    {
        if ($left == 0 && $right == 0) return 'equal';
        if ($left < $right) return 'left';
        if ($right < $left) return 'right';
        return 'equal';
    }

    private function hasMoreLevels(int $userId, int $depth): bool
    {
        return GenealogyPlacement::where('sponsor_id', $userId)
            ->where('status', 'active')->exists();
    }

    /**
     * Check if a node is in the downline of the root (BFS).
     */
    private function isInDownline(int $rootId, int $targetId): bool
    {
        if ($rootId == $targetId) return true;
        $queue = [$rootId];
        $visited = [];
        while ($queue) {
            $current = array_shift($queue);
            if (in_array($current, $visited)) continue;
            $visited[] = $current;
            $children = GenealogyPlacement::where('sponsor_id', $current)
                ->where('status', 'active')->pluck('user_id')->toArray();
            if (in_array($targetId, $children)) return true;
            $queue = array_merge($queue, $children);
        }
        return false;
    }

    /**
     * Get all downline IDs (including nested).
     */
    private function getDownlineIds(int $rootId): array
    {
        $ids = [];
        $queue = [$rootId];
        while ($queue) {
            $current = array_shift($queue);
            $children = GenealogyPlacement::where('sponsor_id', $current)
                ->where('status', 'active')->pluck('user_id')->toArray();
            if ($children) {
                $ids = array_merge($ids, $children);
                $queue = array_merge($queue, $children);
            }
        }
        return $ids;
    }

    /**
     * Get path (ancestors) from root to a given node.
     */
    private function getPathToNode(int $targetId): array
    {
        $path = [];
        $current = $targetId;
        while (true) {
            $placement = GenealogyPlacement::where('user_id', $current)->first();
            if (!$placement || !$placement->sponsor_id) break;
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
        return array_reverse($path);
    }
}