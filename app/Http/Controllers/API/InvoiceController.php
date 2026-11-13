<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    /**
     * Get invoice by order ID or order line ID
     *
     * @param Request $request
     * @param int $orderId
     * @param int|null $lineId
     * @return JsonResponse
     *
     * Routes:
     * - GET /api/invoices/order/{orderId} - For full order invoice
     * - GET /api/invoices/order/{orderId}/{lineId} - For specific line item invoice
     */
    public function getInvoiceByOrder(Request $request, int $orderId, ?int $lineId = null): JsonResponse
    {
        try {
            // Find the order with necessary relationships
            $order = Order::with([
                'invoice',
                'user',
                'billingAddress',
                'deliveryAddress'
            ])->find($orderId);

            // Check if order exists
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Check if invoice exists for this order
            if (!$order->invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found for this order'
                ], 404);
            }

            $invoice = $order->invoice;

            // If lineId is provided, fetch specific line item
            if ($lineId) {
                // Find the specific order line
                $orderLine = OrderLine::with([
                    'product.images',
                    'variant'
                ])->where('order_id', $orderId)
                    ->find($lineId);

                if (!$orderLine) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Order line not found for this order'
                    ], 404);
                }

                // Format single order line
                $orderLines = $this->formatSingleOrderLine($orderLine);
                $invoiceType = 'single_item';
                $message = 'Invoice for single item retrieved successfully';
            } else {
                // Load all order lines for full order
                $order->load(['lines.product.images', 'lines.variant']);

                // Format all order lines
                $orderLines = $this->formatOrderLines($order->lines);
                $invoiceType = 'full_order';
                $message = 'Invoice retrieved successfully';
            }

            // Decode JSON fields
            $lineItems = json_decode($invoice->line_items, true) ?? [];
            $summarySnapshot = json_decode($invoice->summary_snapshot, true) ?? [];
            $taxBreakdown = json_decode($order->tax_breakdown, true) ?? [];
            $summaryData = json_decode($order->summary_data, true) ?? [];

            // Prepare complete response
            $responseData = $this->prepareInvoiceResponse(
                $invoice,
                $order,
                $lineItems,
                $summarySnapshot,
                $taxBreakdown,
                $summaryData,
                $orderLines,
                $lineId
            );

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $responseData,
                'invoice_type' => $invoiceType
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching invoice details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format all order lines with complete details
     *
     * @param $lines
     * @return array
     */
    protected function formatOrderLines($orderLines): array
    {
        $formatted = [];

        foreach ($orderLines as $line) {
            $formatted[] = $this->buildInvoiceLineBreakdown($line);
        }

        return $formatted;
    }

    protected function resolveComboTaxRateForInvoice($product): float
    {
        if (($product->product_type ?? 'single') !== 'combo') {
            return 0.0;
        }

        $groups = \App\Models\Product::normalizeComboGroups($product->parent_combo_id ?? null);

        $childIds = collect($groups)
            ->flatten()
            ->map(fn($v) => (int) $v)
            ->filter(fn($id) => $id > 0 && $id !== (int) $product->id)
            ->unique()
            ->values()
            ->all();

        if (empty($childIds)) {
            return 0.0;
        }

        $maxRate = \App\Models\Product::query()
            ->whereIn('id', $childIds)
            ->whereNull('deleted_at')
            ->with('taxCategory:id,rate')
            ->get()
            ->map(fn($p) => (float) ($p->taxCategory->rate ?? 0))
            ->max();

        return (float) ($maxRate ?? 0);
    }

    protected function buildInvoiceLineBreakdown(OrderLine $orderLine): array
    {
        $product = $orderLine->product;
        $qty     = (int) $orderLine->quantity;

        // ------------------------------------------
        // Tax rate (from saved gst_rate on line, fallback tax category)
        // ------------------------------------------
        $gstRate = (float) ($orderLine->gst_rate ?? 0);

        if ($gstRate === 0 && $product) {
            $gstRate = (float) ($product->taxCategory?->rate ?? 0);

            // Combo fallback — highest tax rate among children
            if ($gstRate === 0 && ($product->product_type ?? 'single') === 'combo') {
                $gstRate = (float) $this->resolveComboTaxRateForInvoice($product);
            }
        }

        $gstFactor = 1 + ($gstRate / 100); // divisor for inclusive prices

        // ------------------------------------------
        // Product pricing (admin prices are GST-inclusive)
        // ------------------------------------------
        $priceInclGst = (float) $orderLine->unit_price; // GST-inclusive

        $priceExclGst = $gstFactor > 0
            ? round($priceInclGst / $gstFactor, 2)
            : $priceInclGst;

        $gstAmountPerUnit = round($priceInclGst - $priceExclGst, 2);

        $linePriceIncl = round($priceInclGst * $qty, 2);
        $linePriceExcl = round($priceExclGst * $qty, 2);
        $lineGstAmount = round($linePriceIncl - $linePriceExcl, 2);

        // ------------------------------------------
        // Shipping (also GST-inclusive)
        // ------------------------------------------
        $shippingPerUnitIncl = (float) ($orderLine->shipping_charge ?? 0);
        $shippingLineIncl    = round($shippingPerUnitIncl * $qty, 2);

        $shippingPerUnitExcl = $gstFactor > 0
            ? round($shippingPerUnitIncl / $gstFactor, 2)
            : $shippingPerUnitIncl;

        $shippingLineExcl = round($shippingPerUnitExcl * $qty, 2);
        $shippingGstAmt   = round($shippingLineIncl - $shippingLineExcl, 2);

        // ------------------------------------------
        // Line totals (product + shipping)
        // ------------------------------------------
        $lineTotalInclGst = round($linePriceIncl + $shippingLineIncl, 2);
        $lineTotalExclGst = round($linePriceExcl + $shippingLineExcl, 2);
        $lineTotalGst     = round($lineTotalInclGst - $lineTotalExclGst, 2);

        // ------------------------------------------
        // CGST / SGST / IGST split
        // (from saved order line columns — set at checkout based on supplier vs delivery state)
        // ------------------------------------------
        $cgstAmount = (float) ($orderLine->cgst_amount ?? 0);
        $sgstAmount = (float) ($orderLine->sgst_amount ?? 0);
        $igstAmount = (float) ($orderLine->igst_amount ?? 0);

        // If not saved (legacy rows), recompute from total GST
        if (($cgstAmount + $sgstAmount + $igstAmount) === 0.0 && $lineGstAmount > 0) {
            // We don't know intra/inter from line alone here — leave blank, caller can decide
            $igstAmount = $lineGstAmount;
        }

        return [
            'order_line_id'         => $orderLine->id,
            'product_id'            => $orderLine->product_id,
            'variant_id'            => $orderLine->variant_id,
            'product_name'          => $product?->name ?? 'Unknown',
            'product_code'          => $product?->product_code,
            'hsn_code'              => $product?->hsn_code,
            'uom'                   => $product?->uom,
            'quantity'              => $qty,

            // ---------- Tax rate ----------
            'gst_rate_percent'      => $gstRate,
            'tax_category_name'     => $product?->taxCategory?->name ?? 'No Tax',

            // ---------- Product pricing ----------
            'price_incl_gst'        => round($priceInclGst, 2),
            'price_excl_gst'        => $priceExclGst,
            'gst_amount_per_unit'   => $gstAmountPerUnit,
            'line_price_incl_gst'   => $linePriceIncl,
            'line_price_excl_gst'   => $linePriceExcl,
            'line_gst_amount'       => $lineGstAmount,

            // ---------- Shipping ----------
            'shipping_per_unit_incl_gst' => round($shippingPerUnitIncl, 2),
            'shipping_per_unit_excl_gst' => $shippingPerUnitExcl,
            'shipping_incl_gst'     => $shippingLineIncl,
            'shipping_excl_gst'     => $shippingLineExcl,
            'shipping_gst_amount'   => $shippingGstAmt,

            // ---------- Line totals (product + shipping) ----------
            'line_total_incl_gst'   => $lineTotalInclGst,
            'line_total_excl_gst'   => $lineTotalExclGst,
            'line_total_gst_amount' => $lineTotalGst,

            // ---------- CGST/SGST/IGST (from DB) ----------
            'cgst_amount'           => round($cgstAmount, 2),
            'sgst_amount'           => round($sgstAmount, 2),
            'igst_amount'           => round($igstAmount, 2),
        ];
    }

    /**
     * Format single order line with complete details
     *
     * @param OrderLine $line
     * @return array
     */
    // private function formatSingleOrderLine(OrderLine $line): array
    // {
    //     $product = $line->product;
    //     $variant = $line->variant;

    //     // Get product images
    //     $productImages = [];
    //     $primaryImage = null;
    //     if ($product) {
    //         $productImages = $product->images->pluck('image')->toArray();
    //         $primaryImage = $product->images->where('is_primary', true)->first();
    //     }

    //     // Get variant attributes
    //     $variantAttributes = null;
    //     $sku = null;
    //     if ($variant) {
    //         $variantAttributes = is_string($variant->attributes)
    //             ? json_decode($variant->attributes, true)
    //             : $variant->attributes;
    //         $sku = $variant->sku;
    //     }

    //     // Shipping charge per unit and line total
    //     $shippingChargePerUnit = $line->shipping_charge ?? 0;
    //     $lineShippingCharge = $shippingChargePerUnit * $line->quantity;

    //     return [
    //         'id' => $line->id,
    //         'product_id' => $line->product_id,
    //         'variant_id' => $line->variant_id,
    //         'product_name' => $product ? $product->name : null,
    //         'product_code' => $product ? $product->product_code : null,
    //         'hsn_code' => $product ? $product->hsn_code : null,
    //         'sku' => $sku,
    //         'variant_attributes' => $variantAttributes,
    //         'quantity' => $line->quantity,
    //         'returned_quantity' => $line->returned_quantity,
    //         'unit_price' => $line->unit_price,

    //         // Shipping charges
    //         'shipping_charge_per_unit' => round($shippingChargePerUnit, 2),
    //         'total_shipping_charge' => round($lineShippingCharge, 2),

    //         'gst_rate' => $line->gst_rate,
    //         'cgst_rate' => $line->cgst_rate,
    //         'sgst_rate' => $line->sgst_rate,
    //         'igst_rate' => $line->igst_rate,
    //         'cgst_amount' => $line->cgst_amount,
    //         'sgst_amount' => $line->sgst_amount,
    //         'igst_amount' => $line->igst_amount,
    //         'gst_amount' => $line->gst_amount,
    //         // 'line_total' => $line->line_total,
    //         'per_unit_delivery_charge' => ($line->product->shipping_charge),
    //         'total_delivery_charge' => ($line->quantity * $line->product->shipping_charge),
    //         'line_total' => $line->line_total + ($line->quantity * $line->product->shipping_charge) ?? null,
    //         'taxable_value' => ($line->line_total - ($line->gst_amount ?? 0)),
    //         'commissionable_volume' => $line->commissionable_volume,
    //         'tax_data' => is_string($line->tax_data) ? json_decode($line->tax_data, true) : $line->tax_data,
    //         'product_image' => $primaryImage
    //             ? asset('storage/' . $primaryImage->image)
    //             : null,
    //         'product_images' => $productImages,
    //         'delivery_status' => $line->delivery_status,
    //         'return_status' => $line->return_status,
    //         'delivery_notes' => $line->delivery_notes,
    //         'is_returnable' => $line->is_returnable,
    //         'return_reason' => $line->return_reason,
    //         'return_rejection_reason' => $line->return_rejection_reason,
    //         'dispatched_at' => $line->dispatched_at ? $line->dispatched_at : null,
    //         'shipped_at' => $line->shipped_at ? $line->shipped_at : null,
    //         'delivered_at' => $line->delivered_at ? $line->delivered_at : null,
    //         'return_at' => $line->return_at ? $line->return_at : null,
    //         'return_requested_at' => $line->return_requested_at ? $line->return_requested_at : null,
    //         'return_approved_at' => $line->return_approved_at ? $line->return_approved_at : null,
    //         'return_rejected_at' => $line->return_rejected_at ? $line->return_rejected_at : null,
    //         'return_completed_at' => $line->return_completed_at ? $line->return_completed_at : null,
    //     ];
    // }
    protected function formatSingleOrderLine(OrderLine $orderLine): array
    {
        return $this->buildInvoiceLineBreakdown($orderLine);
    }

    /**
     * Prepare complete invoice response
     *
     * @param $invoice
     * @param Order $order
     * @param array $lineItems
     * @param array $summarySnapshot
     * @param array $taxBreakdown
     * @param array $summaryData
     * @param array $orderLines
     * @param int|null $lineId
     * @return array
     */
    private function prepareInvoiceResponse(
        $invoice,
        Order $order,
        array $lineItems,
        array $summarySnapshot,
        array $taxBreakdown,
        array $summaryData,
        array $orderLines,
        ?int $lineId = null
    ): array {
        // If lineId is provided, filter line items
        if ($lineId) {
            $filteredLineItems = array_filter($lineItems, function ($item) use ($lineId) {
                return isset($item['order_line_id']) && $item['order_line_id'] == $lineId;
            });

            if (empty($filteredLineItems)) {
                $filteredLineItems = array_filter($lineItems, function ($item) use ($orderLines) {
                    $orderLine = $orderLines[0] ?? null;
                    if ($orderLine) {
                        return isset($item['product_id']) && $item['product_id'] == $orderLine['product_id'];
                    }
                    return false;
                });
            }

            $lineItems = array_values($filteredLineItems);
        }

        // ============================================================
        // INVOICE-LEVEL GST AGGREGATES (from $orderLines)
        // ============================================================
        $totals = [
            'price_incl_gst'     => 0.0,
            'price_excl_gst'     => 0.0,
            'product_gst_amount' => 0.0,

            'shipping_incl_gst'  => 0.0,
            'shipping_excl_gst'  => 0.0,
            'shipping_gst_amount' => 0.0,

            'total_incl_gst'     => 0.0,
            'total_excl_gst'     => 0.0,
            'total_gst_amount'   => 0.0,

            'cgst_amount'        => 0.0,
            'sgst_amount'        => 0.0,
            'igst_amount'        => 0.0,
        ];

        foreach ($orderLines as $line) {
            $totals['price_incl_gst']      += (float) ($line['line_price_incl_gst']     ?? 0);
            $totals['price_excl_gst']      += (float) ($line['line_price_excl_gst']     ?? 0);
            $totals['product_gst_amount']  += (float) ($line['line_gst_amount']         ?? 0);

            $totals['shipping_incl_gst']   += (float) ($line['shipping_incl_gst']       ?? 0);
            $totals['shipping_excl_gst']   += (float) ($line['shipping_excl_gst']       ?? 0);
            $totals['shipping_gst_amount'] += (float) ($line['shipping_gst_amount']     ?? 0);

            $totals['total_incl_gst']      += (float) ($line['line_total_incl_gst']     ?? 0);
            $totals['total_excl_gst']      += (float) ($line['line_total_excl_gst']     ?? 0);
            $totals['total_gst_amount']    += (float) ($line['line_total_gst_amount']   ?? 0);

            $totals['cgst_amount']         += (float) ($line['cgst_amount']             ?? 0);
            $totals['sgst_amount']         += (float) ($line['sgst_amount']             ?? 0);
            $totals['igst_amount']         += (float) ($line['igst_amount']             ?? 0);
        }

        $totals = array_map(fn($v) => round($v, 2), $totals);

        // UTGST label — UT states ke liye SGST ko UTGST bolte hain
        $utStates = [
            'andaman and nicobar islands',
            'chandigarh',
            'dadra and nagar haveli and daman and diu',
            'ladakh',
            'lakshadweep',
        ];

        $deliveryState = strtolower(trim((string) ($order->deliveryAddress->state ?? '')));
        $sgstLabel     = in_array($deliveryState, $utStates, true) ? 'UTGST' : 'SGST';

        return [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'order_id' => $invoice->order_id,
                'issued_at' => $invoice->issued_at ? $invoice->issued_at : null,
                'created_at' => $invoice->created_at ? $invoice->created_at : null,
                'updated_at' => $invoice->updated_at ? $invoice->updated_at : null,

                // Seller Details
                'seller' => [
                    'name'    => $invoice->seller_name,
                    'gstin'   => $invoice->seller_gstin,
                    'address' => !empty($invoice->seller_address) ? $invoice->seller_address : "punjab",
                ],

                // Buyer Details — split into billing & delivery addresses
                'buyer' => [
                    'name'  => $invoice->buyer_name,
                    'gstin' => $invoice->buyer_gstin,

                    // Billing address (billing_* fields)
                    'billing_address' => $order->billingAddress ? [
                        'id'             => $order->billingAddress->id,
                        'recipient_name' => $order->billingAddress->billing_recipient_name,
                        'contact_number' => $order->billingAddress->billing_contact_number,
                        'address_line_1' => $order->billingAddress->billing_address_line_1,
                        'address_line_2' => $order->billingAddress->billing_address_line_2,
                        'city'           => $order->billingAddress->billing_city,
                        'post_office'    => $order->billingAddress->billing_post_office,
                        'type'           => $order->billingAddress->type ?? null,
                        'state'          => $order->billingAddress->billing_state,
                        'pincode'        => $order->billingAddress->billing_postcode,
                        'country'        => $order->billingAddress->billing_country,
                    ] : null,

                    // Delivery address (normal fields)
                    'delivery_address' => $order->deliveryAddress ? [
                        'id'             => $order->deliveryAddress->id,
                        'recipient_name' => $order->deliveryAddress->recipient_name,
                        'contact_number' => $order->deliveryAddress->contact_number,
                        'address_line_1' => $order->deliveryAddress->address_line_1,
                        'address_line_2' => $order->deliveryAddress->address_line_2,
                        'city'           => $order->deliveryAddress->city,
                        'post_office'    => $order->deliveryAddress->post_office,
                        'type'           => $order->deliveryAddress->type ?? null,
                        'state'          => $order->deliveryAddress->state,
                        'pincode'        => $order->deliveryAddress->postcode,
                        'country'        => $order->deliveryAddress->country,
                    ] : null,
                ],

                'delivery_state' => $invoice->delivery_state,

                // Financial Details
                'subtotal_before_redemption' => $invoice->subtotal_before_redemption,
                'coin_redeemed'              => $invoice->coin_redeemed,
                'coupon_code'                => $invoice->coupon_code,
                'coupon_discount'            => $invoice->coupon_discount,
                'shipping_charge'            => $invoice->shipping_charge,
                'subtotal_after_discount'    => $invoice->subtotal_after_discount,

                // Tax Details
                'total_taxable' => $invoice->total_taxable,
                'total_cgst'    => $invoice->total_cgst,
                'total_sgst'    => $invoice->total_sgst,
                'total_igst'    => $invoice->total_igst,
                'total_tax'     => $invoice->total_tax,

                // Totals
                'total'         => $invoice->total,
                'total_payable' => $invoice->total_payable,

                // PDF Path
                'pdf_path' => $invoice->pdf_path,
            ],

            'order' => [
                'id' => $order->id,
                'order_reference' => $order->order_reference,
                'user_id' => $order->user_id,
                'order_type' => $order->order_type,
                'status' => $order->status,
                'delivery_status' => $order->delivery_status,
                'return_status' => $order->return_status,
                'refund_status' => $order->refund_status,

                // Financial Details
                'subtotal' => $order->subtotal,
                'total_gst' => $order->total_gst,
                'total_cgst' => $order->total_cgst,
                'total_sgst' => $order->total_sgst,
                'total_igst' => $order->total_igst,
                'shipping_charge' => $order->shipping_charge,
                'shipping_method_id' => $order->shipping_method_id,
                'coin_redeemed' => $order->coin_redeemed,
                'coin_redeemed_amount' => $order->coin_redeemed_amount,
                'coupon_discount' => $order->coupon_discount,
                'coupon_code' => $order->coupon_code,
                'total_payable' => $order->total_payable,
                'amount_paid' => $order->amount_paid,
                'commissionable_volume' => $order->commissionable_volume,

                // Payment Details
                'payment_gateway' => $order->payment_gateway,
                'gateway_transaction_id' => $order->gateway_transaction_id,
                'checkout_type' => $order->checkout_type,

                // Shipping Details
                'courier_company' => $order->courier_company,
                'courier_tracking_number' => $order->courier_tracking_number,
                'courier_status' => $order->courier_status,
                'courier_delivery_date' => $order->courier_delivery_date,
                'delivery_notes' => $order->delivery_notes,

                // Timestamps
                'confirmed_at' => $order->confirmed_at ? $order->confirmed_at : null,
                'shipped_at' => $order->shipped_at ? $order->shipped_at : null,
                'delivered_at' => $order->delivered_at ? $order->delivered_at : null,
                'cancelled_at' => $order->cancelled_at ? $order->cancelled_at : null,
                'refunded_at' => $order->refunded_at ? $order->refunded_at : null,
                'created_at' => $order->created_at ? $order->created_at : null,
                'updated_at' => $order->updated_at ? $order->updated_at : null,

                // Additional Data
                'summary_data' => $summaryData,



                // User Details
                'user' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                    'phone' => $order->user->phone ?? null,
                ] : null,

                'order_items' => $orderLines,
                'items_count' => count($orderLines),
                'total_items' => count($orderLines),

                'gst_breakdown' => [
                    // SGST label — SGST ya UTGST (state ke hisaab se)
                    'sgst_label' => $sgstLabel,

                    // Product
                    'price_incl_gst'      => $totals['price_incl_gst'],
                    'price_excl_gst'      => $totals['price_excl_gst'],
                    'product_gst_amount'  => $totals['product_gst_amount'],

                    // Shipping
                    'shipping_incl_gst'   => $totals['shipping_incl_gst'],
                    'shipping_excl_gst'   => $totals['shipping_excl_gst'],
                    'shipping_gst_amount' => $totals['shipping_gst_amount'],

                    // Overall
                    'total_incl_gst'   => $totals['total_incl_gst'],
                    'total_excl_gst'   => $totals['total_excl_gst'],
                    'total_gst_amount' => $totals['total_gst_amount'],

                    // CGST / SGST / IGST
                    'cgst_amount' => $totals['cgst_amount'],
                    'sgst_amount' => $totals['sgst_amount'],
                    'igst_amount' => $totals['igst_amount'],
                ],
            ],
        ];
    }
}
