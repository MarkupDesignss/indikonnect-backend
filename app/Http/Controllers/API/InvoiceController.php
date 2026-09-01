<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    protected InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Generate invoice for a given order (Admin only)
     *
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function generate(Request $request, int $orderId): JsonResponse
    {
        try {
            // Check if user has admin permission
            if (!$request->user() || !$request->user()->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can generate invoices.'
                ], 403);
            }

            // Find order with relationships
            $order = Order::with([
                'user',
                'deliveryAddress',
                'lines.product.images',
                'lines.variant'
            ])->find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Check if invoice already exists
            if ($order->invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice already exists for this order',
                    'data' => [
                        'invoice' => $order->invoice,
                        'invoice_number' => $order->invoice->invoice_number
                    ]
                ], 409);
            }

            // Validate order status
            $allowedStatuses = ['completed', 'delivered', 'confirmed', 'shipped'];
            if (!in_array($order->status, $allowedStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => "Invoice can only be generated for orders with status: " . implode(', ', $allowedStatuses),
                    'current_status' => $order->status
                ], 400);
            }

            DB::beginTransaction();

            // Generate invoice using service
            $invoice = $this->invoiceService->generateInvoice($order);

            DB::commit();

            // Format response with all necessary details
            $invoiceData = $this->formatInvoiceData($order);

            return response()->json([
                'success' => true,
                'message' => 'Invoice generated successfully',
                'data' => $invoiceData,
                'download_url' => url("/api/invoices/download/{$invoice->id}")
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice generation failed: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate invoice: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get invoice by order ID
     *
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function getInvoiceByOrder(Request $request, int $orderId): JsonResponse
    {
        try {
            // Find the order with its relationships
            $order = Order::with([
                'invoice',
                'lines.product.images',
                'lines.variant',
                'user',
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

            // Format the response data
            $invoiceData = $this->formatInvoiceData($order);

            return response()->json([
                'success' => true,
                'data' => $invoiceData
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch invoice: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching invoice details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format invoice data with all necessary details
     *
     * @param Order $order
     * @return array
     */
    private function formatInvoiceData(Order $order): array
    {
        $invoice = $order->invoice;

        // Decode line items from invoice
        $lineItems = json_decode($invoice->line_items, true) ?? [];

        // Decode summary snapshot
        $summarySnapshot = json_decode($invoice->summary_snapshot, true) ?? [];

        // Format order lines with product details
        $orderLines = $order->lines->map(function ($line) {
            $product = $line->product;
            $primaryImage = $product ? $product->images->where('is_primary', true)->first() : null;

            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'product_name' => $product ? $product->name : null,
                'product_code' => $product ? $product->product_code : null,
                'hsn_code' => $product ? $product->hsn_code : null,
                'sku' => $line->variant ? $line->variant->sku : null,
                'variant_attributes' => $line->variant ? json_decode($line->variant->attributes, true) : null,
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
                'taxable_value' => $line->line_total - $line->gst_amount,
                'commissionable_volume' => $line->commissionable_volume,
                'tax_data' => json_decode($line->tax_data, true),
                'product_image' => $primaryImage ? $primaryImage->image : null,
                'product_images' => $product ? $product->images->pluck('image')->toArray() : [],
                'delivery_status' => $line->delivery_status,
                'return_status' => $line->return_status,
                'dispatched_at' => $line->dispatched_at,
                'delivered_at' => $line->delivered_at,
                'shipped_at' => $line->shipped_at,
            ];
        })->toArray();

        // Return complete formatted data
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
                'line_items' => $lineItems,

                // Summary Snapshot
                'summary_snapshot' => $summarySnapshot,

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
                'tax_breakdown' => json_decode($order->tax_breakdown, true),
                'summary_data' => json_decode($order->summary_data, true),
            ],

            'order_lines' => $orderLines
        ];
    }
}
