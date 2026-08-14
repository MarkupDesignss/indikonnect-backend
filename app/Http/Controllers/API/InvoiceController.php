<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    public function getInvoiceByOrder(Request $request, $orderId): JsonResponse
    {
        try {
            // Find the order with its relationships
            $order = Order::with([
                'invoice',
                'lines.product.images' // Load product images with order lines
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

            // Format the response data
            $invoiceData = $this->formatInvoiceData($order);

            return response()->json([
                'success' => true,
                'data' => $invoiceData
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
     * Format invoice data with related information
     *
     * @param Order $order
     * @return array
     */
    private function formatInvoiceData(Order $order): array
    {
        $invoice = $order->invoice;

        // Format order lines with product details
        $orderLines = $order->lines->map(function ($line) {
            $product = $line->product;
            $primaryImage = $product ? $product->images->where('is_primary', true)->first() : null;

            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $product ? $product->name : null,
                'product_code' => $product ? $product->product_code : null,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'gst_rate' => $line->gst_rate,
                'gst_amount' => $line->gst_amount,
                'line_total' => $line->line_total,
                'commissionable_volume' => $line->commissionable_volume,
                'tax_data' => $line->tax_data,
                'product_image' => $primaryImage ? $primaryImage->image : null,
                'product_images' => $product ? $product->images->pluck('image')->toArray() : []
            ];
        })->toArray();

        // Return formatted data
        return [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'issued_at' => $invoice->issued_at,
                'pdf_path' => $invoice->pdf_path,
                'subtotal_before_redemption' => $invoice->subtotal_before_redemption,
                'coin_redeemed' => $invoice->coin_redeemed,
                'total_taxable' => $invoice->total_taxable,
                'total_cgst' => $invoice->total_cgst,
                'total_sgst' => $invoice->total_sgst,
                'total_igst' => $invoice->total_igst,
                'total_tax' => $invoice->total_tax,
                'coupon_code' => $invoice->coupon_code,
                'coupon_discount' => $invoice->coupon_discount,
                'shipping_charge' => $invoice->shipping_charge,
                'subtotal_after_discount' => $invoice->subtotal_after_discount,
                'total' => $invoice->total,
                'total_payable' => $invoice->total_payable,
                'line_items' => $invoice->line_items,
                'summary_snapshot' => $invoice->summary_snapshot,
                'seller_details' => [
                    'name' => $invoice->seller_name,
                    'gstin' => $invoice->seller_gstin,
                    'address' => $invoice->seller_address,
                ],
                'buyer_details' => [
                    'name' => $invoice->buyer_name,
                    'gstin' => $invoice->buyer_gstin,
                    'address' => $invoice->buyer_address,
                ],
                'delivery_state' => $invoice->delivery_state,
            ],
            'order' => [
                'id' => $order->id,
                'order_reference' => $order->order_reference,
                'order_type' => $order->order_type,
                'subtotal' => $order->subtotal,
                'total_gst' => $order->total_gst,
                'shipping_charge' => $order->shipping_charge,
                'coin_redeemed' => $order->coin_redeemed,
                'coin_redeemed_amount' => $order->coin_redeemed_amount,
                'total_payable' => $order->total_payable,
                'amount_paid' => $order->amount_paid,
                'status' => $order->status,
                'payment_gateway' => $order->payment_gateway,
                'gateway_transaction_id' => $order->gateway_transaction_id,
                'confirmed_at' => $order->confirmed_at,
                'courier_company' => $order->courier_company,
                'courier_tracking_number' => $order->courier_tracking_number,
                'courier_status' => $order->courier_status,
                'courier_delivery_date' => $order->courier_delivery_date,
                'delivery_notes' => $order->delivery_notes,
                'tax_breakdown' => $order->tax_breakdown,
                'summary_data' => $order->summary_data,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
            ],
            'order_lines' => $orderLines
        ];
    }
}
