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
    private function formatOrderLines($lines): array
    {
        $orderLines = [];
        foreach ($lines as $line) {
            $orderLines[] = $this->formatSingleOrderLine($line);
        }
        return $orderLines;
    }

    /**
     * Format single order line with complete details
     *
     * @param OrderLine $line
     * @return array
     */
    private function formatSingleOrderLine(OrderLine $line): array
    {
        $product = $line->product;
        $variant = $line->variant;

        // Get product images
        $productImages = [];
        $primaryImage = null;
        if ($product) {
            $productImages = $product->images->pluck('image')->toArray();
            $primaryImage = $product->images->where('is_primary', true)->first();
        }

        // Get variant attributes
        $variantAttributes = null;
        $sku = null;
        if ($variant) {
            $variantAttributes = is_string($variant->attributes)
                ? json_decode($variant->attributes, true)
                : $variant->attributes;
            $sku = $variant->sku;
        }

        return [
            'id' => $line->id,
            'product_id' => $line->product_id,
            'variant_id' => $line->variant_id,
            'product_name' => $product ? $product->name : null,
            'product_code' => $product ? $product->product_code : null,
            'hsn_code' => $product ? $product->hsn_code : null,
            'sku' => $sku,
            'variant_attributes' => $variantAttributes,
            'quantity' => $line->quantity,
            'returned_quantity' => $line->returned_quantity,
            'unit_price' => $line->unit_price,
            'gst_rate' => $line->gst_rate,
            'cgst_rate' => $line->cgst_rate,
            'sgst_rate' => $line->sgst_rate,
            'igst_rate' => $line->igst_rate,
            'cgst_amount' => $line->cgst_amount,
            'sgst_amount' => $line->sgst_amount,
            'igst_amount' => $line->igst_amount,
            'gst_amount' => $line->gst_amount,
            'line_total' => $line->line_total,
            'taxable_value' => ($line->line_total - ($line->gst_amount ?? 0)),
            'commissionable_volume' => $line->commissionable_volume,
            'tax_data' => is_string($line->tax_data) ? json_decode($line->tax_data, true) : $line->tax_data,
            'product_image' => $primaryImage
                ? asset('storage/' . $primaryImage->image)
                : null,
            'product_images' => $productImages,
            'delivery_status' => $line->delivery_status,
            'return_status' => $line->return_status,
            'delivery_notes' => $line->delivery_notes,
            'is_returnable' => $line->is_returnable,
            'return_reason' => $line->return_reason,
            'return_rejection_reason' => $line->return_rejection_reason,
            'dispatched_at' => $line->dispatched_at ? $line->dispatched_at->toISOString() : null,
            'shipped_at' => $line->shipped_at ? $line->shipped_at->toISOString() : null,
            'delivered_at' => $line->delivered_at ? $line->delivered_at->toISOString() : null,
            'return_at' => $line->return_at ? $line->return_at->toISOString() : null,
            'return_requested_at' => $line->return_requested_at ? $line->return_requested_at->toISOString() : null,
            'return_approved_at' => $line->return_approved_at ? $line->return_approved_at->toISOString() : null,
            'return_rejected_at' => $line->return_rejected_at ? $line->return_rejected_at->toISOString() : null,
            'return_completed_at' => $line->return_completed_at ? $line->return_completed_at->toISOString() : null,
        ];
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
    private function prepareInvoiceResponse($invoice, Order $order, array $lineItems, array $summarySnapshot, array $taxBreakdown, array $summaryData, array $orderLines, ?int $lineId = null): array
    {
        // If lineId is provided, filter line items
        if ($lineId) {
            // Filter line_items to only include the specific item
            $filteredLineItems = array_filter($lineItems, function ($item) use ($lineId) {
                return isset($item['order_line_id']) && $item['order_line_id'] == $lineId;
            });

            // If no match found in line_items, try to match by product_id or name
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

        return [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'order_id' => $invoice->order_id,
                'issued_at' => $invoice->issued_at ? $invoice->issued_at->toISOString() : null,
                'created_at' => $invoice->created_at ? $invoice->created_at->toISOString() : null,
                'updated_at' => $invoice->updated_at ? $invoice->updated_at->toISOString() : null,

                // Seller Details
                'seller' => [
                    'name' => $invoice->seller_name,
                    'gstin' => $invoice->seller_gstin,
                    'address' => $invoice->seller_address,
                ],

                // Buyer Details
                'buyer' => [
                    'name' => $invoice->buyer_name,
                    'gstin' => $invoice->buyer_gstin,
                    'address' => $invoice->buyer_address,
                ],

                'delivery_state' => $invoice->delivery_state,

                // Financial Details
                'subtotal_before_redemption' => $invoice->subtotal_before_redemption,
                'coin_redeemed' => $invoice->coin_redeemed,
                'coupon_code' => $invoice->coupon_code,
                'coupon_discount' => $invoice->coupon_discount,
                'shipping_charge' => $invoice->shipping_charge,
                'subtotal_after_discount' => $invoice->subtotal_after_discount,

                // Tax Details
                'total_taxable' => $invoice->total_taxable,
                'total_cgst' => $invoice->total_cgst,
                'total_sgst' => $invoice->total_sgst,
                'total_igst' => $invoice->total_igst,
                'total_tax' => $invoice->total_tax,

                // Totals
                'total' => $invoice->total,
                'total_payable' => $invoice->total_payable,

                // Line Items (from invoice)
                // 'line_items' => $lineItems,


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
                'confirmed_at' => $order->confirmed_at ? $order->confirmed_at->toISOString() : null,
                'shipped_at' => $order->shipped_at ? $order->shipped_at->toISOString() : null,
                'delivered_at' => $order->delivered_at ? $order->delivered_at->toISOString() : null,
                'cancelled_at' => $order->cancelled_at ? $order->cancelled_at->toISOString() : null,
                'refunded_at' => $order->refunded_at ? $order->refunded_at->toISOString() : null,
                'created_at' => $order->created_at ? $order->created_at->toISOString() : null,
                'updated_at' => $order->updated_at ? $order->updated_at->toISOString() : null,

                // Additional Data
                'summary_data' => $summaryData,

                // Addresses
                'billing_address' => $order->billingAddress ? [
                    'id' => $order->billingAddress->id,
                    'address_line_1' => $order->billingAddress->address_line_1,
                    'address_line_2' => $order->billingAddress->address_line_2,
                    'city' => $order->billingAddress->city,
                    'state' => $order->billingAddress->state,
                    'pincode' => $order->billingAddress->pincode,
                    'country' => $order->billingAddress->country,
                ] : null,

                'delivery_address' => $order->deliveryAddress ? [
                    'id' => $order->deliveryAddress->id,
                    'address_line_1' => $order->deliveryAddress->address_line_1,
                    'address_line_2' => $order->deliveryAddress->address_line_2,
                    'city' => $order->deliveryAddress->city,
                    'state' => $order->deliveryAddress->state,
                    'pincode' => $order->deliveryAddress->pincode,
                    'country' => $order->deliveryAddress->country,
                ] : null,

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
            ],

        ];
    }
}
