<?php
namespace App\Services\Ignite;

use App\Models\Order;
use App\Models\User;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Support\Facades\Log;

class CvAllocationPlanner
{
    public function __construct(private IgniteClient $ignite) {}

    /**
     * Plan from a saved Order (used by job fallback + admin preview endpoint).
     */
    public function plan(Order $order): array
    {
        $order->loadMissing(['lines.product', 'user']);
        $user     = $order->user;
        $mainBaId = preg_replace('/-(002|003)$/', '-001', $user->distributor_id);

        $lines = $order->lines->map(fn ($l) => (object) [
            'product_id'             => $l->product_id,
            'quantity'               => $l->quantity,
            'product'                => $l->product,
            'ignite_catalog_item_id' => $l->ignite_catalog_item_id,
        ])->all();

        return $this->buildPlan($user, $mainBaId, $lines, $order->confirmed_at);
    }

    /**
     * Plan from cart/buy-now lines (used by checkout summary preview).
     */
    public function planFromLines(User $user, string $mainBaId, array $lines, ?\DateTimeInterface $orderedAt = null): array
    {
        return $this->buildPlan($user, $mainBaId, $lines, $orderedAt ?? now());
    }

    /**
     * Core calculation — pure, no DB writes, no createOrder.
     */
    // private function buildPlan(User $user, string $mainBaId, array $lines, \DateTimeInterface $orderedAt): array
    // {
    //     $localCurrency = config('services.ignite.local_currency');

    //     // ── 1. catalog ──
    //     $catalogRes = $this->ignite->catalog();
    //     if (($catalogRes['code'] ?? null) !== ResultCode::OK->value) {
    //         throw new \Exception("catalog() refused: " . ($catalogRes['msg'] ?? 'unknown'));
    //     }

    //     $items       = collect($catalogRes['data']['items'] ?? []);
    //     $byRef       = $items->whereNotNull('partner_product_ref')->groupBy('partner_product_ref');
    //     $byProduct   = $items->groupBy('product_id');
    //     $byCatalogId = $items->keyBy('catalog_item_id');

    //     // ── 2. map lines ──
    //     $mapped        = [];
    //     $totalCv       = 0.0;
    //     $lineBreakdown = [];
    //     $skippedLines  = [];

    //     foreach ($lines as $line) {
    //         $product    = $line->product;
    //         $igniteItem = null;
    //         $matchedBy  = null;

    //         if (!empty($line->ignite_catalog_item_id)) {
    //             $igniteItem = $byCatalogId->get((int) $line->ignite_catalog_item_id);
    //             $matchedBy  = 'snapshot';
    //         }

    //         if (!$igniteItem && $product && !empty($product->partner_product_ref)) {
    //             $candidates = $byRef->get($product->partner_product_ref, collect());
    //             if ($candidates->count() === 1) {
    //                 $igniteItem = $candidates->first();
    //                 $matchedBy  = 'partner_product_ref';
    //             } elseif ($candidates->count() > 1) {
    //                 $skippedLines[] = [
    //                     'product_id' => $product->id,
    //                     'reason'     => 'Ambiguous partner_product_ref.',
    //                 ];
    //                 continue;
    //             }
    //         }

    //         if (!$igniteItem && $product && !empty($product->ignite_product_id)) {
    //             $candidates = $byProduct->get((int) $product->ignite_product_id, collect());
    //             if ($candidates->count() === 1) {
    //                 $igniteItem = $candidates->first();
    //                 $matchedBy  = 'ignite_product_id';
    //             } elseif ($candidates->count() > 1) {
    //                 $skippedLines[] = [
    //                     'product_id' => $product->id,
    //                     'reason'     => 'Multiple catalog lines; no partner_product_ref.',
    //                 ];
    //                 continue;
    //             }
    //         }

    //         if (!$igniteItem || empty($igniteItem['catalog_item_id'])) {
    //             $skippedLines[] = [
    //                 'product_id'   => $product?->id,
    //                 'product_name' => $product?->name ?? 'Unknown',
    //                 'reason'       => 'No catalog_item_id resolvable.',
    //             ];
    //             continue;
    //         }

    //         $lineCv = round((float) $igniteItem['cv'] * (int) $line->quantity, 4);

    //         $mapped[] = [
    //             'product_id'      => (int) $igniteItem['product_id'],
    //             'qty'             => (int) $line->quantity,
    //             'catalog_item_id' => (int) $igniteItem['catalog_item_id'],
    //         ];

    //         $lineBreakdown[] = [
    //             'product_id'      => $product?->id,
    //             'product_name'    => $product?->name,
    //             'qty'             => (int) $line->quantity,
    //             'catalog_item_id' => (int) $igniteItem['catalog_item_id'],
    //             'cv_per_unit'     => (float) $igniteItem['cv'],
    //             'line_cv'         => $lineCv,
    //             'matched_by'      => $matchedBy,
    //         ];

    //         $totalCv += $lineCv;
    //     }

    //     // ── Early return: no mapped lines ──
    //     if (empty($mapped) || $totalCv <= 0) {
    //         return $this->emptyPlan(
    //             $mainBaId,
    //             $mapped,
    //             $lineBreakdown,
    //             $skippedLines,
    //             'No mapped products or zero CV.'
    //         );
    //     }

    //     // ── 3. plan + member + tree ──
    //     $planRes = $this->ignite->planRanks();
    //     if (($planRes['code'] ?? null) !== ResultCode::OK->value) {
    //         throw new \Exception("planRanks() refused: " . ($planRes['msg'] ?? 'unknown'));
    //     }
    //     $plan          = $planRes['data']['plan'] ?? [];
    //     $qualifyCv     = (float) ($plan['qualify_cv'] ?? 0);
    //     $baseProductCv = (float) ($plan['base_product_cv'] ?? 0);

    //     $memberRes = $this->ignite->memberDetails($mainBaId);
    //     if (($memberRes['code'] ?? null) !== ResultCode::OK->value) {
    //         throw new \Exception("memberDetails() refused: " . ($memberRes['msg'] ?? 'unknown'));
    //     }
    //     $member = $memberRes['data'];

    //     $treeRes = $this->ignite->binaryTree($mainBaId, 3);
    //     if (($treeRes['code'] ?? null) !== ResultCode::OK->value) {
    //         throw new \Exception("binaryTree() refused: " . ($treeRes['msg'] ?? 'unknown'));
    //     }
    //     $treeRows = collect($treeRes['data']['rows'] ?? []);
    //     $level1   = $treeRows->where('level', 1);
    //     $child002 = $level1->firstWhere('binary_side', 1);
    //     $child003 = $level1->firstWhere('binary_side', 2);
    //     $ba002    = $child002['ba_id'] ?? null;
    //     $ba003    = $child003['ba_id'] ?? null;

    //     // ── Early return: not placed ──
    //     if (!$ba002 || !$ba003) {
    //         return [
    //             'main_ba_id'    => $mainBaId,
    //             'total_cv'      => round($totalCv, 4),
    //             'lines'         => $mapped,
    //             'line_breakdown'=> $lineBreakdown,
    //             'skipped_lines' => $skippedLines,
    //             'allocations'   => [],
    //             'extra_tcs'     => [],
    //             'leftover'      => $this->fmt($totalCv),
    //             'summary'       => $this->summaryBlock(
    //                 $qualifyCv, $baseProductCv, $totalCv, $totalCv, 0, $totalCv,
    //                 $mainBaId, 0, $member,
    //                 null, 0, [],
    //                 null, 0, []
    //             ),
    //             'validations'   => ['placed' => false],
    //             'blocking'      => ['Member has no -002/-003 (not placed).'],
    //         ];
    //     }

    //     $mem002Res = $this->ignite->memberDetails($ba002);
    //     $mem003Res = $this->ignite->memberDetails($ba003);
    //     $mem002    = ($mem002Res['code'] ?? null) === ResultCode::OK->value ? $mem002Res['data'] : [];
    //     $mem003    = ($mem003Res['code'] ?? null) === ResultCode::OK->value ? $mem003Res['data'] : [];

    //     $cv001Have = (float) ($member['cv_allocated'] ?? 0);
    //     $cv002Have = (float) ($mem002['cv_allocated'] ?? 0);
    //     $cv003Have = (float) ($mem003['cv_allocated'] ?? 0);

    //     // ── 4. allocations ──
    //     $cv001 = 0.0;
    //     if (empty($member['qualified_at'])) {
    //         $cv001 = min(
    //             max(0.0, round($qualifyCv - $cv001Have, 4)),
    //             round($totalCv, 4)
    //         );
    //     }
    //     $cv001     = round($cv001, 4);
    //     $remaining = round($totalCv - $cv001, 4);

    //     $cv002Need = max(0.0, round($qualifyCv - $cv002Have, 4));
    //     $cv003Need = max(0.0, round($qualifyCv - $cv003Have, 4));

    //     $cv002     = min($remaining, $cv002Need);
    //     $remaining = round($remaining - $cv002, 4);

    //     $cv003     = min($remaining, $cv003Need);
    //     $remaining = round($remaining - $cv003, 4);

    //     $allocations = [];
    //     if ($cv001 > 0.0001) {
    //         $allocations[] = [
    //             'target'            => '001',
    //             'ba_id'             => $mainBaId,
    //             'cv'                => $this->fmt($cv001),
    //             'reason'            => '001_qualify',
    //             'before'            => $this->fmt($cv001Have),
    //             'after'             => $this->fmt($cv001Have + $cv001),
    //             'qualify_cv'        => $this->fmt($qualifyCv),
    //             'already_qualified' => false,
    //         ];
    //     }
    //     if ($cv002 > 0.0001) {
    //         $allocations[] = [
    //             'target'            => '002',
    //             'ba_id'             => $ba002,
    //             'cv'                => $this->fmt($cv002),
    //             'reason'            => '002_qualify',
    //             'before'            => $this->fmt($cv002Have),
    //             'after'             => $this->fmt($cv002Have + $cv002),
    //             'qualify_cv'        => $this->fmt($qualifyCv),
    //             'already_qualified' => !empty($mem002['qualified_at']),
    //         ];
    //     }
    //     if ($cv003 > 0.0001) {
    //         $allocations[] = [
    //             'target'            => '003',
    //             'ba_id'             => $ba003,
    //             'cv'                => $this->fmt($cv003),
    //             'reason'            => '003_qualify',
    //             'before'            => $this->fmt($cv003Have),
    //             'after'             => $this->fmt($cv003Have + $cv003),
    //             'qualify_cv'        => $this->fmt($qualifyCv),
    //             'already_qualified' => !empty($mem003['qualified_at']),
    //         ];
    //     }

    //     // ── 5. extra TCs + leftover ──
    //     $extraTcs  = [];
    //     $freeSlots = [];
    //     foreach ($treeRows as $row) {
    //         if (empty($row['far_left_id'])) {
    //             $freeSlots[] = [
    //                 'placement_member_id' => (int) $row['member_id'],
    //                 'side'                => 1,
    //                 'placement_ba_id'     => $row['ba_id'] ?? null,
    //             ];
    //         }
    //         if (empty($row['far_right_id'])) {
    //             $freeSlots[] = [
    //                 'placement_member_id' => (int) $row['member_id'],
    //                 'side'                => 2,
    //                 'placement_ba_id'     => $row['ba_id'] ?? null,
    //             ];
    //         }
    //     }

    //     $extraTcCount = 0;
    //     $leftover     = $remaining;

    //     if ($remaining > 0.0001 && $baseProductCv > 0) {
    //         $extraTcCount = (int) floor($remaining / $baseProductCv);
    //         $extraTcCv    = round($extraTcCount * $baseProductCv, 4);
    //         $leftover     = round($remaining - $extraTcCv, 4);

    //         // ── Early return: not enough free slots ──
    //         if ($extraTcCount > count($freeSlots)) {
    //             return [
    //                 'main_ba_id'    => $mainBaId,
    //                 'total_cv'      => round($totalCv, 4),
    //                 'lines'         => $mapped,
    //                 'line_breakdown'=> $lineBreakdown,
    //                 'skipped_lines' => $skippedLines,
    //                 'allocations'   => $allocations,
    //                 'extra_tcs'     => [],
    //                 'leftover'      => $this->fmt($leftover),
    //                 'summary'       => $this->summaryBlock(
    //                     $qualifyCv, $baseProductCv, $totalCv, $remaining, $extraTcCount, $leftover,
    //                     $mainBaId, $cv001Have, $member,
    //                     $ba002, $cv002Have, $mem002,
    //                     $ba003, $cv003Have, $mem003
    //                 ),
    //                 'validations'   => ['free_slots_enough' => false],
    //                 'blocking'      => ["Only " . count($freeSlots) . " free slots for {$extraTcCount} TCs."],
    //             ];
    //         }

    //         for ($i = 0; $i < $extraTcCount; $i++) {
    //             $slot       = $freeSlots[$i];
    //             $extraTcs[] = [
    //                 'index'               => $i,
    //                 'placement_member_id' => $slot['placement_member_id'],
    //                 'placement_ba_id'     => $slot['placement_ba_id'],
    //                 'side'                => $slot['side'],
    //                 'cv'                  => $this->fmt($baseProductCv),
    //             ];
    //             $allocations[] = [
    //                 'target'   => 'extra_tc:' . $i,
    //                 'extra_tc' => $i,
    //                 'cv'       => $this->fmt($baseProductCv),
    //                 'reason'   => 'extra_tc',
    //             ];
    //         }
    //     }

    //     if ($leftover > 0.0001) {
    //         $placed = false;
    //         if ($cv002Have + $cv002 + $leftover <= $qualifyCv + 0.0001) {
    //             $allocations[] = [
    //                 'target' => '002_leftover',
    //                 'ba_id'  => $ba002,
    //                 'cv'     => $this->fmt($leftover),
    //                 'reason' => 'leftover_002',
    //             ];
    //             $placed = true;
    //         } elseif ($cv003Have + $cv003 + $leftover <= $qualifyCv + 0.0001) {
    //             $allocations[] = [
    //                 'target' => '003_leftover',
    //                 'ba_id'  => $ba003,
    //                 'cv'     => $this->fmt($leftover),
    //                 'reason' => 'leftover_003',
    //             ];
    //             $placed = true;
    //         }

    //         // ── Early return: leftover cannot be allocated ──
    //         if (!$placed) {
    //             return [
    //                 'main_ba_id'    => $mainBaId,
    //                 'total_cv'      => round($totalCv, 4),
    //                 'lines'         => $mapped,
    //                 'line_breakdown'=> $lineBreakdown,
    //                 'skipped_lines' => $skippedLines,
    //                 'allocations'   => $allocations,
    //                 'extra_tcs'     => $extraTcs,
    //                 'leftover'      => $this->fmt($leftover),
    //                 'summary'       => $this->summaryBlock(
    //                     $qualifyCv, $baseProductCv, $totalCv, $remaining, $extraTcCount, $leftover,
    //                     $mainBaId, $cv001Have, $member,
    //                     $ba002, $cv002Have, $mem002,
    //                     $ba003, $cv003Have, $mem003
    //                 ),
    //                 'validations'   => ['leftover_allocatable' => false],
    //                 'blocking'      => ["Leftover CV {$leftover} cannot be allocated."],
    //             ];
    //         }
    //     }

    //     // ── 6. sum check ──
    //     $allocSum = 0.0;
    //     foreach ($allocations as $a) $allocSum += (float) $a['cv'];
    //     $allocSum = round($allocSum, 4);
    //     $sumMatch = abs($allocSum - round($totalCv, 4)) <= 0.0001;

    //     $salesWeekOk = $this->validateSalesWeek($this->ignite, $orderedAt);

    //     $blocking = [];
    //     if (!$sumMatch)    $blocking[] = "Allocation sum {$allocSum} != total CV {$totalCv}.";
    //     if (!$salesWeekOk) $blocking[] = 'ordered_at falls in no sales week.';

    //     // ── 7. Final return ──
    //     return [
    //         'main_ba_id'    => $mainBaId,
    //         'total_cv'      => round($totalCv, 4),
    //         'lines'         => $mapped,
    //         'line_breakdown'=> $lineBreakdown,
    //         'skipped_lines' => $skippedLines,
    //         'allocations'   => $allocations,
    //         'extra_tcs'     => $extraTcs,
    //         'leftover'      => $this->fmt($leftover),
    //         'summary'       => $this->summaryBlock(
    //             $qualifyCv, $baseProductCv, $totalCv, $remaining, $extraTcCount, $leftover,
    //             $mainBaId, $cv001Have, $member,
    //             $ba002, $cv002Have, $mem002,
    //             $ba003, $cv003Have, $mem003
    //         ),
    //         'validations'   => [
    //             'sum_match'         => $sumMatch,
    //             'free_slots_enough' => true,
    //             'placed'            => true,
    //             'sales_week_ok'     => $salesWeekOk,
    //         ],
    //         'blocking'      => $blocking,
    //     ];
    // }
    private function buildPlan(User $user, string $mainBaId, array $lines, \DateTimeInterface $orderedAt): array
    {
        $localCurrency = config('services.ignite.local_currency');

        // ── 1. catalog ──
        $catalogRes = $this->ignite->catalog();
        if (($catalogRes['code'] ?? null) !== ResultCode::OK->value) {
            throw new \Exception("catalog() refused: " . ($catalogRes['msg'] ?? 'unknown'));
        }

        $items       = collect($catalogRes['data']['items'] ?? []);
        $byRef       = $items->whereNotNull('partner_product_ref')->groupBy('partner_product_ref');
        $byProduct   = $items->groupBy('product_id');
        $byCatalogId = $items->keyBy('catalog_item_id');

        // ── 2. map lines ──
        $mapped        = [];
        $totalCv       = 0.0;
        $lineBreakdown = [];
        $skippedLines  = [];

        foreach ($lines as $line) {
            $product    = $line->product;
            $igniteItem = null;
            $matchedBy  = null;

            if (!empty($line->ignite_catalog_item_id)) {
                $igniteItem = $byCatalogId->get((int) $line->ignite_catalog_item_id);
                $matchedBy  = 'snapshot';
            }

            if (!$igniteItem && $product && !empty($product->partner_product_ref)) {
                $candidates = $byRef->get($product->partner_product_ref, collect());
                if ($candidates->count() === 1) {
                    $igniteItem = $candidates->first();
                    $matchedBy  = 'partner_product_ref';
                } elseif ($candidates->count() > 1) {
                    $skippedLines[] = [
                        'product_id' => $product->id,
                        'reason'     => 'Ambiguous partner_product_ref.',
                    ];
                    continue;
                }
            }

            if (!$igniteItem && $product && !empty($product->ignite_product_id)) {
                $candidates = $byProduct->get((int) $product->ignite_product_id, collect());
                if ($candidates->count() === 1) {
                    $igniteItem = $candidates->first();
                    $matchedBy  = 'ignite_product_id';
                } elseif ($candidates->count() > 1) {
                    $skippedLines[] = [
                        'product_id' => $product->id,
                        'reason'     => 'Multiple catalog lines; no partner_product_ref.',
                    ];
                    continue;
                }
            }

            if (!$igniteItem || empty($igniteItem['catalog_item_id'])) {
                $skippedLines[] = [
                    'product_id'   => $product?->id,
                    'product_name' => $product?->name ?? 'Unknown',
                    'reason'       => 'No catalog_item_id resolvable.',
                ];
                continue;
            }

            $lineCv = round((float) $igniteItem['cv'] * (int) $line->quantity, 4);

            $mapped[] = [
                'product_id'      => (int) $igniteItem['product_id'],
                'qty'             => (int) $line->quantity,
                'catalog_item_id' => (int) $igniteItem['catalog_item_id'],
            ];

            $lineBreakdown[] = [
                'product_id'      => $product?->id,
                'product_name'    => $product?->name,
                'qty'             => (int) $line->quantity,
                'catalog_item_id' => (int) $igniteItem['catalog_item_id'],
                'cv_per_unit'     => (float) $igniteItem['cv'],
                'line_cv'         => $lineCv,
                'matched_by'      => $matchedBy,
            ];

            $totalCv += $lineCv;
        }

        // ── Early return: no mapped lines ──
        if (empty($mapped) || $totalCv <= 0) {
            return $this->emptyPlan(
                $mainBaId,
                $mapped,
                $lineBreakdown,
                $skippedLines,
                'No mapped products or zero CV.'
            );
        }

        // ── 3. plan + member + tree ──
        $planRes = $this->ignite->planRanks();
        if (($planRes['code'] ?? null) !== ResultCode::OK->value) {
            throw new \Exception("planRanks() refused: " . ($planRes['msg'] ?? 'unknown'));
        }
        $plan          = $planRes['data']['plan'] ?? [];
        $qualifyCv     = (float) ($plan['qualify_cv'] ?? 0);
        $baseProductCv = (float) ($plan['base_product_cv'] ?? 0);

        $memberRes = $this->ignite->memberDetails($mainBaId);
        if (($memberRes['code'] ?? null) !== ResultCode::OK->value) {
            throw new \Exception("memberDetails() refused: " . ($memberRes['msg'] ?? 'unknown'));
        }
        $member = $memberRes['data'];

        $treeRes = $this->ignite->binaryTree($mainBaId, 3);
        if (($treeRes['code'] ?? null) !== ResultCode::OK->value) {
            throw new \Exception("binaryTree() refused: " . ($treeRes['msg'] ?? 'unknown'));
        }
        $treeRows = collect($treeRes['data']['rows'] ?? []);
        $level1   = $treeRows->where('level', 1);
        $child002 = $level1->firstWhere('binary_side', 1);
        $child003 = $level1->firstWhere('binary_side', 2);
        $ba002    = $child002['ba_id'] ?? null;
        $ba003    = $child003['ba_id'] ?? null;

        // ── Early return: not placed ──
        if (!$ba002 || !$ba003) {
            return [
                'main_ba_id'    => $mainBaId,
                'total_cv'      => round($totalCv, 4),
                'lines'         => $mapped,
                'line_breakdown'=> $lineBreakdown,
                'skipped_lines' => $skippedLines,
                'allocations'   => [],
                'extra_tcs'     => [],
                'leftover'      => $this->fmt($totalCv),
                'summary'       => $this->summaryBlock(
                    $qualifyCv, $baseProductCv, $totalCv, $totalCv, 0, $totalCv,
                    $mainBaId, 0, $member,
                    null, 0, [],
                    null, 0, []
                ),
                'validations'   => ['placed' => false],
                'blocking'      => ['Member has no -002/-003 (not placed).'],
            ];
        }

        $mem002Res = $this->ignite->memberDetails($ba002);
        $mem003Res = $this->ignite->memberDetails($ba003);
        $mem002    = ($mem002Res['code'] ?? null) === ResultCode::OK->value ? $mem002Res['data'] : [];
        $mem003    = ($mem003Res['code'] ?? null) === ResultCode::OK->value ? $mem003Res['data'] : [];

        $cv001Have = (float) ($member['cv_allocated'] ?? 0);
        $cv002Have = (float) ($mem002['cv_allocated'] ?? 0);
        $cv003Have = (float) ($mem003['cv_allocated'] ?? 0);

        // ── 4. allocations ──
        $cv001 = 0.0;
        if (empty($member['qualified_at'])) {
            $cv001 = min(
                max(0.0, round($qualifyCv - $cv001Have, 4)),
                round($totalCv, 4)
            );
        }
        $cv001     = round($cv001, 4);
        $remaining = round($totalCv - $cv001, 4);

        $cv002Need = max(0.0, round($qualifyCv - $cv002Have, 4));
        $cv003Need = max(0.0, round($qualifyCv - $cv003Have, 4));

        $cv002     = min($remaining, $cv002Need);
        $remaining = round($remaining - $cv002, 4);

        $cv003     = min($remaining, $cv003Need);
        $remaining = round($remaining - $cv003, 4);

        $allocations = [];
        if ($cv001 > 0.0001) {
            $allocations[] = [
                'target'            => '001',
                'label'             => 'TC-001', // <--- Naya label
                'ba_id'             => $mainBaId,
                'cv'                => $this->fmt($cv001),
                'reason'            => '001_qualify',
                'before'            => $this->fmt($cv001Have),
                'after'             => $this->fmt($cv001Have + $cv001),
                'qualify_cv'        => $this->fmt($qualifyCv),
                'already_qualified' => false,
            ];
        }
        if ($cv002 > 0.0001) {
            $allocations[] = [
                'target'            => '002',
                'label'             => 'TC-002', // <--- Naya label
                'ba_id'             => $ba002,
                'cv'                => $this->fmt($cv002),
                'reason'            => '002_qualify',
                'before'            => $this->fmt($cv002Have),
                'after'             => $this->fmt($cv002Have + $cv002),
                'qualify_cv'        => $this->fmt($qualifyCv),
                'already_qualified' => !empty($mem002['qualified_at']),
            ];
        }
        if ($cv003 > 0.0001) {
            $allocations[] = [
                'target'            => '003',
                'label'             => 'TC-003', // <--- Naya label
                'ba_id'             => $ba003,
                'cv'                => $this->fmt($cv003),
                'reason'            => '003_qualify',
                'before'            => $this->fmt($cv003Have),
                'after'             => $this->fmt($cv003Have + $cv003),
                'qualify_cv'        => $this->fmt($qualifyCv),
                'already_qualified' => !empty($mem003['qualified_at']),
            ];
        }

        // ── 5. extra TCs + leftover ──
        $extraTcs  = [];
        $freeSlots = [];
        foreach ($treeRows as $row) {
            if (empty($row['far_left_id'])) {
                $freeSlots[] = [
                    'placement_member_id' => (int) $row['member_id'],
                    'side'                => 1,
                    'placement_ba_id'     => $row['ba_id'] ?? null,
                ];
            }
            if (empty($row['far_right_id'])) {
                $freeSlots[] = [
                    'placement_member_id' => (int) $row['member_id'],
                    'side'                => 2,
                    'placement_ba_id'     => $row['ba_id'] ?? null,
                ];
            }
        }

        $extraTcCount = 0;
        $leftover     = $remaining;

        if ($remaining > 0.0001 && $baseProductCv > 0) {
            $extraTcCount = (int) floor($remaining / $baseProductCv);
            $extraTcCv    = round($extraTcCount * $baseProductCv, 4);
            $leftover     = round($remaining - $extraTcCv, 4);

            // ── Early return: not enough free slots ──
            if ($extraTcCount > count($freeSlots)) {
                return [
                    'main_ba_id'    => $mainBaId,
                    'total_cv'      => round($totalCv, 4),
                    'lines'         => $mapped,
                    'line_breakdown'=> $lineBreakdown,
                    'skipped_lines' => $skippedLines,
                    'allocations'   => $allocations,
                    'extra_tcs'     => [],
                    'leftover'      => $this->fmt($leftover),
                    'summary'       => $this->summaryBlock(
                        $qualifyCv, $baseProductCv, $totalCv, $remaining, $extraTcCount, $leftover,
                        $mainBaId, $cv001Have, $member,
                        $ba002, $cv002Have, $mem002,
                        $ba003, $cv003Have, $mem003
                    ),
                    'validations'   => ['free_slots_enough' => false],
                    'blocking'      => ["Only " . count($freeSlots) . " free slots for {$extraTcCount} TCs."],
                ];
            }

            for ($i = 0; $i < $extraTcCount; $i++) {
                $slot       = $freeSlots[$i];
                
                // Calculate the sequential number for Extra TCs (starts from 4)
                $tcNumber = 4 + $i;
                $tcLabel  = 'TC-' . str_pad($tcNumber, 3, '0', STR_PAD_LEFT); // e.g., TC-004, TC-005

                $extraTcs[] = [
                    'index'               => $i,
                    'label'               => $tcLabel, // <--- Naya label
                    'placement_member_id' => $slot['placement_member_id'],
                    'placement_ba_id'     => $slot['placement_ba_id'],
                    'side'                => $slot['side'],
                    'cv'                  => $this->fmt($baseProductCv),
                ];
                $allocations[] = [
                    'target'   => 'extra_tc:' . $i,
                    'label'    => $tcLabel, // <--- Naya label
                    'extra_tc' => $i,
                    'cv'       => $this->fmt($baseProductCv),
                    'reason'   => 'extra_tc',
                ];
            }
        }

        if ($leftover > 0.0001) {
            $placed = false;
            if ($cv002Have + $cv002 + $leftover <= $qualifyCv + 0.0001) {
                $allocations[] = [
                    'target' => '002_leftover',
                    'label'  => 'TC-002', // <--- Naya label
                    'ba_id'  => $ba002,
                    'cv'     => $this->fmt($leftover),
                    'reason' => 'leftover_002',
                ];
                $placed = true;
            } elseif ($cv003Have + $cv003 + $leftover <= $qualifyCv + 0.0001) {
                $allocations[] = [
                    'target' => '003_leftover',
                    'label'  => 'TC-003', // <--- Naya label
                    'ba_id'  => $ba003,
                    'cv'     => $this->fmt($leftover),
                    'reason' => 'leftover_003',
                ];
                $placed = true;
            }

            // ── Early return: leftover cannot be allocated ──
            if (!$placed) {
                return [
                    'main_ba_id'    => $mainBaId,
                    'total_cv'      => round($totalCv, 4),
                    'lines'         => $mapped,
                    'line_breakdown'=> $lineBreakdown,
                    'skipped_lines' => $skippedLines,
                    'allocations'   => $allocations,
                    'extra_tcs'     => $extraTcs,
                    'leftover'      => $this->fmt($leftover),
                    'summary'       => $this->summaryBlock(
                        $qualifyCv, $baseProductCv, $totalCv, $remaining, $extraTcCount, $leftover,
                        $mainBaId, $cv001Have, $member,
                        $ba002, $cv002Have, $mem002,
                        $ba003, $cv003Have, $mem003
                    ),
                    'validations'   => ['leftover_allocatable' => false],
                    'blocking'      => ["Leftover CV {$leftover} cannot be allocated."],
                ];
            }
        }

        // ── 6. sum check ──
        $allocSum = 0.0;
        foreach ($allocations as $a) $allocSum += (float) $a['cv'];
        $allocSum = round($allocSum, 4);
        $sumMatch = abs($allocSum - round($totalCv, 4)) <= 0.0001;

        $salesWeekOk = $this->validateSalesWeek($this->ignite, $orderedAt);

        $blocking = [];
        if (!$sumMatch)    $blocking[] = "Allocation sum {$allocSum} != total CV {$totalCv}.";
        if (!$salesWeekOk) $blocking[] = 'ordered_at falls in no sales week.';

        // ── 7. Final return ──
        return [
            'main_ba_id'    => $mainBaId,
            'total_cv'      => round($totalCv, 4),
            'lines'         => $mapped,
            'line_breakdown'=> $lineBreakdown,
            'skipped_lines' => $skippedLines,
            'allocations'   => $allocations,
            'extra_tcs'     => $extraTcs,
            'leftover'      => $this->fmt($leftover),
            'summary'       => $this->summaryBlock(
                $qualifyCv, $baseProductCv, $totalCv, $remaining, $extraTcCount, $leftover,
                $mainBaId, $cv001Have, $member,
                $ba002, $cv002Have, $mem002,
                $ba003, $cv003Have, $mem003
            ),
            'validations'   => [
                'sum_match'         => $sumMatch,
                'free_slots_enough' => true,
                'placed'            => true,
                'sales_week_ok'     => $salesWeekOk,
            ],
            'blocking'      => $blocking,
        ];
    }

    /**
     * Empty plan (no mapped lines / zero CV).
     */
    private function emptyPlan(
        string $mainBaId,
        array $mapped,
        array $lineBreakdown,
        array $skippedLines,
        string $reason
    ): array {
        return [
            'main_ba_id'    => $mainBaId,
            'total_cv'      => 0,
            'lines'         => $mapped,
            'line_breakdown'=> $lineBreakdown,
            'skipped_lines' => $skippedLines,
            'allocations'   => [],
            'extra_tcs'     => [],
            'leftover'      => '0.0000',
            'summary'       => $this->summaryBlock(
                0, 0, 0, 0, 0, 0,
                $mainBaId, 0, [],
                null, 0, [],
                null, 0, []
            ),
            'validations'   => ['no_mapped_lines' => true],
            'blocking'      => [$reason],
        ];
    }

    /**
     * Build the `summary` block for the response.
     */
    private function summaryBlock(
        float $qualifyCv,
        float $baseProductCv,
        float $totalCv,
        float $remaining,
        int $extraTcCount,
        float $leftover,
        string $mainBaId,
        float $cv001Have,
        array $member,
        ?string $ba002,
        float $cv002Have,
        array $mem002,
        ?string $ba003,
        float $cv003Have,
        array $mem003
    ): array {
        $nodes = [];

        if ($mainBaId) {
            $nodes['001'] = [
                'ba_id'             => $mainBaId,
                'cv_allocated'      => $this->fmt($cv001Have),
                'qualified_at'      => $member['qualified_at'] ?? null,
                'already_qualified' => !empty($member['qualified_at']),
                'needs_cv'          => $this->fmt(max(0, $qualifyCv - $cv001Have)),
            ];
        }
        if ($ba002) {
            $nodes['002'] = [
                'ba_id'             => $ba002,
                'cv_allocated'      => $this->fmt($cv002Have),
                'qualified_at'      => $mem002['qualified_at'] ?? null,
                'already_qualified' => !empty($mem002['qualified_at']),
                'needs_cv'          => $this->fmt(max(0, $qualifyCv - $cv002Have)),
            ];
        }
        if ($ba003) {
            $nodes['003'] = [
                'ba_id'             => $ba003,
                'cv_allocated'      => $this->fmt($cv003Have),
                'qualified_at'      => $mem003['qualified_at'] ?? null,
                'already_qualified' => !empty($mem003['qualified_at']),
                'needs_cv'          => $this->fmt(max(0, $qualifyCv - $cv003Have)),
            ];
        }

        return [
            'qualify_cv'      => $this->fmt($qualifyCv),
            'base_product_cv' => $this->fmt($baseProductCv),
            'total_cv'        => $this->fmt($totalCv),
            'remaining_cv'    => $this->fmt($remaining),
            'extra_tc_count'  => $extraTcCount,
            'leftover_cv'     => $this->fmt($leftover),
            'nodes'           => $nodes,
        ];
    }

    private function fmt(float $v): string
    {
        return number_format($v, 4, '.', '');
    }

    private function validateSalesWeek(IgniteClient $ignite, \DateTimeInterface $orderedAt): bool
    {
        $year = (int) $orderedAt->format('Y');
        foreach ([$year, $year + 1] as $y) {
            $res = $ignite->salesCalendar($y);
            if (($res['code'] ?? null) !== ResultCode::OK->value) continue;
            $s = $orderedAt->format('Y-m-d H:i:s');
            foreach ($res['data']['months'] ?? [] as $m) {
                foreach ($m['weeks'] ?? [] as $w) {
                    if ($s >= $w['start_at'] && $s < $w['end_at']) return true;
                }
            }
        }
        return false;
    }
}