<?php

namespace App\Services\Ignite;

use App\Models\Order;
use App\Sdks\Partner\IgniteClient;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CvAllocationService
{
    public function __construct(
        private readonly IgniteClient $ignite
    ) {}

    /**
     * Preview CV allocation for an order.
     */
    public function preview(Order $order): array
    {
        $totalCv = $this->calculateOrderCv($order);

        $mainBaId = $order->user->distributor_id ?? null;

        if (!$mainBaId) {
            throw ValidationException::withMessages([
                'distributor_id' => 'Distributor BA ID not found.',
            ]);
        }

        $mainBaId = preg_replace('/-(001|002|003)$/', '-001', $mainBaId);

        $snapshot = $this->getSnapshot($mainBaId);

        $allocation = $this->buildDefaultAllocation($totalCv, $snapshot);

        return [
            'order_id' => $order->id,
            'total_cv' => $totalCv,
            'qualify_cv' => $snapshot['qualify_cv'],
            'base_product_cv' => $snapshot['base_product_cv'],

            'members' => $snapshot['members'],

            'available_placements' => $snapshot['available_placements'],

            'earned_extra_tcs' => $allocation['earned_extra_tcs'],
            'banked_extra_tcs' => $allocation['banked_extra_tcs'],

            'allocations' => $allocation['allocations'],
            'extra_tcs' => $allocation['extra_tcs'],
        ];
    }

    /**
     * Calculate actual CV from Ignite catalog.
     */
    public function calculateOrderCv(Order $order): int
    {
        $response = $this->ignite->catalog();

        $data = $this->responseData($response, 'Ignite catalog');

        $catalogItems = $data['items'] ?? [];

        if (!$catalogItems) {
            throw new RuntimeException(
                'Ignite catalog has no available items.'
            );
        }

        $totalCv = 0;

        foreach ($order->lines as $line) {

            $product = $line->product;

            $catalogItemId = $line->ignite_catalog_item_id
                ?? $product?->ignite_catalog_item_id;

            $matches = [];

            // First priority: catalog item ID.
            if ($catalogItemId) {
                $matches = array_filter(
                    $catalogItems,
                    fn ($item) =>
                        (int) ($item['catalog_item_id'] ?? 0)
                        === (int) $catalogItemId
                );
            }

            // Second priority: Ignite product ID.
            if (!$matches) {

                $igniteProductId = $line->ignite_product_id
                    ?? $product?->ignite_product_id;

                if ($igniteProductId) {
                    $matches = array_filter(
                        $catalogItems,
                        fn ($item) =>
                            (int) ($item['product_id'] ?? 0)
                            === (int) $igniteProductId
                    );
                }
            }

            // Third priority: partner product reference.
            if (!$matches) {

                $partnerProductRef = $line->partner_product_ref
                    ?? $product?->partner_product_ref;

                if ($partnerProductRef) {
                    $matches = array_filter(
                        $catalogItems,
                        fn ($item) =>
                            ($item['partner_product_ref'] ?? null)
                            === $partnerProductRef
                    );
                }
            }

            if (count($matches) !== 1) {
                throw ValidationException::withMessages([
                    'products' =>
                        "Unable to identify one Ignite catalog line for order line ID {$line->id}.",
                ]);
            }

            $catalogItem = array_values($matches)[0];

            $qty = (int) ($line->quantity ?? $line->qty ?? 0);

            $unitCv = (float) ($catalogItem['cv'] ?? 0);

            if ($qty < 1 || $unitCv <= 0) {
                throw ValidationException::withMessages([
                    'products' =>
                        "Invalid quantity or CV for order line ID {$line->id}.",
                ]);
            }

            $totalCv += (int) round($unitCv * $qty);
        }

        if ($totalCv <= 0) {
            throw ValidationException::withMessages([
                'total_cv' => 'Order CV must be greater than zero.',
            ]);
        }

        return $totalCv;
    }

    /**
     * Fetch current Ignite qualification and placement information.
     */
    public function getSnapshot(string $mainBaId): array
    {
        $planResponse = $this->ignite->planRanks();

        $planData = $this->responseData(
            $planResponse,
            'Ignite plan ranks'
        );

        $plan = $planData['plan'] ?? [];

        $qualifyCv = (int) round(
            (float) ($plan['qualify_cv'] ?? 0)
        );

        $baseProductCv = (int) round(
            (float) ($plan['base_product_cv'] ?? 0)
        );

        if ($qualifyCv <= 0 || $baseProductCv <= 0) {
            throw new RuntimeException(
                'Invalid Ignite qualification or base product CV.'
            );
        }

        $baIds = [
            '001' => $mainBaId,
            '002' => preg_replace('/-001$/', '-002', $mainBaId),
            '003' => preg_replace('/-001$/', '-003', $mainBaId),
        ];

        $members = [];

        foreach ($baIds as $suffix => $baId) {

            $response = $this->ignite->memberDetails($baId);

            $members[$suffix] = $this->responseData(
                $response,
                "Ignite member details {$baId}"
            );
        }

        $treeResponse = $this->ignite->binaryTree(
            $mainBaId,
            5
        );

        $treeData = $this->responseData(
            $treeResponse,
            'Ignite binary tree'
        );

        return [
            'qualify_cv' => $qualifyCv,
            'base_product_cv' => $baseProductCv,
            'members' => $members,

            'available_placements' => $this->getAvailablePlacements(
                $treeData['rows'] ?? []
            ),
        ];
    }

    /**
     * Generate default allocation.
     *
     * Always return TC-001, TC-002 and TC-003.
     *
     * Priority:
     * 1. TC-001 qualification.
     * 2. TC-002 qualification.
     * 3. TC-003 qualification.
     * 4. Eligible Extra TCs.
     * 5. Remaining CV to TC-002.
     */
    public function buildDefaultAllocation(
        int $totalCv,
        array $snapshot
    ): array {

        $qualifyCv = $snapshot['qualify_cv'];

        $baseProductCv = $snapshot['base_product_cv'];

        $members = $snapshot['members'];

        $remaining = $totalCv;

        $allocations = [];

        $extraTcs = [];

        /*
         * STEP 1:
         * Calculate qualification gaps.
         */

        $qualificationGaps = [];

        foreach (['001', '002', '003'] as $suffix) {

            $member = $members[$suffix];

            if ($suffix === '001' && !empty($member['qualified_at'])) {
                $gap = 0;
            } else {
                $currentCv = (int) round(
                    (float) ($member['cv_allocated'] ?? 0)
                );

                $gap = max(0, $qualifyCv - $currentCv);
            }

            $qualificationGaps[$suffix] = $gap;
        }

        /*
         * STEP 2:
         * Allocate CV to qualification gaps.
         */

        foreach (['001', '002', '003'] as $suffix) {

            $member = $members[$suffix];

            $amount = min(
                $remaining,
                $qualificationGaps[$suffix]
            );

            $allocations[$suffix] = [
                'tc' => 'TC-' . $suffix,
                'member_id' => (int) $member['member_id'],
                'ba_id' => $member['ba_id'],
                'cv' => (string) $amount,
                'extra_tc' => 0,
            ];

            $remaining -= $amount;
        }

        /*
         * STEP 3:
         * Check whether TC-002 and TC-003 qualify
         * after this order allocation.
         */

        $leftQualified = (
            (int) round(
                (float) ($members['002']['cv_allocated'] ?? 0)
            )
            + (int) $allocations['002']['cv']
        ) >= $qualifyCv;

        $rightQualified = (
            (int) round(
                (float) ($members['003']['cv_allocated'] ?? 0)
            )
            + (int) $allocations['003']['cv']
        ) >= $qualifyCv;

        /*
         * STEP 4:
         * Calculate earned Extra TCs.
         */

        $earnedExtraTcs = 0;

        if (
            $leftQualified
            && $rightQualified
            && $remaining >= $baseProductCv
        ) {
            $earnedExtraTcs = intdiv(
                $remaining,
                $baseProductCv
            );
        }

        /*
         * STEP 5:
         * Assign Extra TC placement.
         */

        $availableSlots = $snapshot['available_placements'] ?? [];

        $placeCount = min(
            $earnedExtraTcs,
            count($availableSlots)
        );

        for ($i = 0; $i < $placeCount; $i++) {

            $slot = $availableSlots[$i];

            $extraTcs[] = [
                'placement_member_id' => (int) $slot['placement_member_id'],
                'side' => (int) $slot['side'],
            ];

            $allocations[] = [
                'extra_tc' => $i,
                'cv' => (string) $baseProductCv,
            ];

            $remaining -= $baseProductCv;
        }

        /*
         * STEP 6:
         * Remaining CV goes to TC-002.
         *
         * Update existing row instead of creating
         * a duplicate TC-002 row.
         */

        if ($remaining > 0) {

            $allocations['002']['cv'] = (string) (
                (int) $allocations['002']['cv'] + $remaining
            );

            $remaining = 0;
        }

        /*
         * STEP 7:
         * Return all three original TCs plus
         * any Extra TC allocations.
         */

        return [
            'allocations' => array_values($allocations),

            'extra_tcs' => $extraTcs,

            'earned_extra_tcs' => $earnedExtraTcs,

            'banked_extra_tcs' => max(
                0,
                $earnedExtraTcs - $placeCount
            ),
        ];
    }

    /**
     * Validate frontend-selected allocation against Ignite.
     */
    public function validateSelection(
        string $mainBaId,
        int $totalCv,
        array $allocations,
        array $extraTcs
    ): void {

        $snapshot = $this->getSnapshot($mainBaId);

        $qualifyCv = $snapshot['qualify_cv'];

        $baseProductCv = $snapshot['base_product_cv'];

        $members = $snapshot['members'];

        $allowedBaIds = [
            $members['001']['ba_id'],
            $members['002']['ba_id'],
            $members['003']['ba_id'],
        ];

        $totalAllocated = 0;

        $byBaId = [];

        $byExtraTc = [];

        $seenBaIds = [];

        foreach ($allocations as $allocation) {

            $hasBaId = !empty($allocation['ba_id']);

            $hasExtraTc = array_key_exists('extra_tc', $allocation);

            if ($hasBaId === $hasExtraTc) {
                throw ValidationException::withMessages([
                    'allocations' =>
                        'Each allocation must contain either ba_id or extra_tc.',
                ]);
            }

            $cv = filter_var(
                $allocation['cv'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($cv === false || $cv < 0) {
                throw ValidationException::withMessages([
                    'allocations' =>
                        'Allocation CV must be a non-negative whole number.',
                ]);
            }

            if ($hasExtraTc && $cv <= 0) {
                throw ValidationException::withMessages([
                    'allocations' =>
                        'Extra TC allocation CV must be greater than zero.',
                ]);
            }

            $totalAllocated += $cv;

            /*
             * Original TC allocation.
             */

            if ($hasBaId) {

                $baId = $allocation['ba_id'];

                if (!in_array($baId, $allowedBaIds, true)) {
                    throw ValidationException::withMessages([
                        'allocations' => "Invalid BA ID: {$baId}",
                    ]);
                }

                if (isset($seenBaIds[$baId])) {
                    throw ValidationException::withMessages([
                        'allocations' =>
                            "Duplicate allocation found for BA ID: {$baId}",
                    ]);
                }

                $seenBaIds[$baId] = true;

                $byBaId[$baId] = $cv;

            } else {

                /*
                 * Extra TC allocation.
                 */

                $index = filter_var(
                    $allocation['extra_tc'],
                    FILTER_VALIDATE_INT
                );

                if (
                    $index === false
                    || !array_key_exists($index, $extraTcs)
                ) {
                    throw ValidationException::withMessages([
                        'allocations' =>
                            'Invalid extra TC index.',
                    ]);
                }

                $byExtraTc[$index] = (
                    $byExtraTc[$index] ?? 0
                ) + $cv;
            }
        }

        /*
         * All three original TCs must be present.
         */

        foreach ($allowedBaIds as $baId) {

            if (!array_key_exists($baId, $byBaId)) {
                throw ValidationException::withMessages([
                    'allocations' =>
                        "Allocation row missing for BA ID: {$baId}",
                ]);
            }
        }

        /*
         * Total CV must equal order CV.
         */

        if ($totalAllocated !== $totalCv) {
            throw ValidationException::withMessages([
                'allocations' =>
                    "Allocation total must equal order CV ({$totalCv}).",
            ]);
        }

        /*
         * Preserve original TC-001 qualification rule.
         */

        $main = $members['001'];

        $mainGap = !empty($main['qualified_at'])
            ? 0
            : max(
                0,
                $qualifyCv - (int) round(
                    (float) ($main['cv_allocated'] ?? 0)
                )
            );

        $expectedMainCv = min($totalCv, $mainGap);

        $actualMainCv = $byBaId[$main['ba_id']] ?? 0;

        if ($actualMainCv !== $expectedMainCv) {
            throw ValidationException::withMessages([
                'allocations' =>
                    "Main account -001 must receive exactly {$expectedMainCv} CV.",
            ]);
        }

        /*
         * Check qualification after selected allocation.
         */

        $leftCurrent = (int) round(
            (float) ($members['002']['cv_allocated'] ?? 0)
        );

        $rightCurrent = (int) round(
            (float) ($members['003']['cv_allocated'] ?? 0)
        );

        $leftAfter = $leftCurrent
            + ($byBaId[$members['002']['ba_id']] ?? 0);

        $rightAfter = $rightCurrent
            + ($byBaId[$members['003']['ba_id']] ?? 0);

        /*
         * No Extra TC allocation before both
         * original sub-accounts qualify.
         */

        if (
            $extraTcs
            && (
                $leftAfter < $qualifyCv
                || $rightAfter < $qualifyCv
            )
        ) {
            throw ValidationException::withMessages([
                'allocations' =>
                    'Both -002 and -003 must qualify before allocating CV to extra TCs.',
            ]);
        }

        /*
         * Every Extra TC must have an allocation.
         */

        if (count($extraTcs) !== count($byExtraTc)) {
            throw ValidationException::withMessages([
                'extra_tcs' =>
                    'Every selected extra TC must have a corresponding CV allocation.',
            ]);
        }

        /*
         * Calculate Extra TC entitlement.
         */

        $requiredOriginalCv = $mainGap
            + max(0, $qualifyCv - $leftCurrent)
            + max(0, $qualifyCv - $rightCurrent);

        $surplus = max(
            0,
            $totalCv - $requiredOriginalCv
        );

        $entitlement = intdiv(
            $surplus,
            $baseProductCv
        );

        if (count($extraTcs) > $entitlement) {
            throw ValidationException::withMessages([
                'extra_tcs' =>
                    "Only {$entitlement} extra TC(s) are eligible for this order.",
            ]);
        }

        /*
         * Validate each Extra TC CV.
         */

        foreach ($extraTcs as $index => $extraTc) {

            if (
                ($byExtraTc[$index] ?? 0)
                !== $baseProductCv
            ) {
                throw ValidationException::withMessages([
                    'allocations' =>
                        "Extra TC {$index} must receive {$baseProductCv} CV.",
                ]);
            }
        }

        /*
         * Validate placement parent and side.
         */

        $validSlots = [];

        foreach ($snapshot['available_placements'] as $slot) {

            $key = $slot['placement_member_id']
                . ':'
                . $slot['side'];

            $validSlots[$key] = true;
        }

        $usedSlots = [];

        foreach ($extraTcs as $extraTc) {

            $parentId = (int) (
                $extraTc['placement_member_id'] ?? 0
            );

            $side = (int) (
                $extraTc['side'] ?? 0
            );

            $key = $parentId . ':' . $side;

            if (
                !isset($validSlots[$key])
                || isset($usedSlots[$key])
            ) {
                throw ValidationException::withMessages([
                    'extra_tcs' =>
                        'An extra TC placement is invalid or already selected.',
                ]);
            }

            $usedSlots[$key] = true;
        }
    }

    /**
     * Get every available end-of-leg placement slot.
     */
    private function getAvailablePlacements(array $rows): array
    {
        $slots = [];

        foreach ($rows as $row) {

            $memberId = (int) ($row['member_id'] ?? 0);

            if (!$memberId) {
                continue;
            }

            foreach ([1, 2] as $side) {

                $farField = $side === 1
                    ? 'far_left_id'
                    : 'far_right_id';

                $parentId = !empty($row[$farField])
                    ? (int) $row[$farField]
                    : $memberId;

                $key = $parentId . ':' . $side;

                $slots[$key] = [
                    'placement_member_id' => $parentId,
                    'side' => $side,
                ];
            }
        }

        return array_values($slots);
    }

    /**
     * Extract allocation for a BA ID.
     */
    private function allocatedTo(
        array $allocations,
        string $baId
    ): int {

        $total = 0;

        foreach ($allocations as $allocation) {

            if (($allocation['ba_id'] ?? null) === $baId) {
                $total += (int) $allocation['cv'];
            }
        }

        return $total;
    }

    /**
     * Validate Ignite API response.
     */
    private function responseData(
        array $response,
        string $action
    ): array {

        if (($response['code'] ?? null) !== 0) {

            throw new RuntimeException(
                $action
                . ' failed: '
                . ($response['msg'] ?? 'Unknown Ignite error')
            );
        }

        return $response['data'] ?? [];
    }
}
