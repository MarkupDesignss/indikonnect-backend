<?php

namespace App\Services;

class GSTCalculator
{
    /**
     * Calculate GST for a given amount and rate
     */
    public function calculate(float $amount, float $rate, string $deliveryState, string $supplierState = null): array
    {
        $supplierState = $supplierState ?? config('app.supplier_state', 'Maharashtra');
        $totalTax = ($amount * $rate) / 100;

        if (strtolower($deliveryState) === strtolower($supplierState)) {
            // Intra-state: CGST + SGST (half each)
            return [
                'cgst' => round($totalTax / 2, 2),
                'sgst' => round($totalTax / 2, 2),
                'igst' => 0,
                'total' => round($totalTax, 2),
            ];
        } else {
            // Inter-state: IGST
            return [
                'cgst' => 0,
                'sgst' => 0,
                'igst' => round($totalTax, 2),
                'total' => round($totalTax, 2),
            ];
        }
    }

    /**
     * Calculate total GST for all items
     */
    public function calculateTotal(array $items, string $deliveryState): array
    {
        $totalCgst = 0;
        $totalSgst = 0;
        $totalIgst = 0;
        $totalTax = 0;

        foreach ($items as $item) {
            $tax = $this->calculate($item['taxable_value'], $item['gst_rate'], $deliveryState);
            $totalCgst += $tax['cgst'];
            $totalSgst += $tax['sgst'];
            $totalIgst += $tax['igst'];
            $totalTax += $tax['total'];
        }

        return [
            'total_cgst' => round($totalCgst, 2),
            'total_sgst' => round($totalSgst, 2),
            'total_igst' => round($totalIgst, 2),
            'total_tax' => round($totalTax, 2),
        ];
    }
}