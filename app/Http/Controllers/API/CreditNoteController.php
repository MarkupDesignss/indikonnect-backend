<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class CreditNoteController extends Controller
{
    /**
     * Admin: Get all credit notes with filters
     * GET /api/admin/credit-notes
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // 1. Subquery: Aggregate order-level item data & statuses
            $taxRate = 'COALESCE(order_lines.gst_rate, tax_categories.rate, 0)';

            $lineAggSub = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
                ->leftJoin('tax_categories', 'tax_categories.id', '=', 'products.tax_category_id')
                ->select('order_lines.order_id')
                ->selectRaw('SUM(order_lines.quantity) as total_qty')
                ->selectRaw('
                SUM(
                    order_lines.line_total +
                    order_lines.shipping_charge +
                    (COALESCE(products.service_charge, 0) * order_lines.quantity)
                ) as total_item_amount
            ')
                ->selectRaw("
                CASE
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'delivered' THEN 1 ELSE 0 END) = COUNT(*) THEN 'delivered'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'delivered' THEN 1 ELSE 0 END) > 0 THEN 'partial_delivered'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'shipped' THEN 1 ELSE 0 END) > 0
                        AND SUM(CASE WHEN order_lines.delivery_status NOT IN ('shipped','delivered') THEN 1 ELSE 0 END) > 0 THEN 'partial_shipped'
                    WHEN SUM(CASE WHEN order_lines.delivery_status NOT IN ('shipped','delivered') THEN 1 ELSE 0 END) = 0 THEN 'shipped'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'dispatched' THEN 1 ELSE 0 END) > 0
                        AND SUM(CASE WHEN order_lines.delivery_status NOT IN ('dispatched','shipped','delivered') THEN 1 ELSE 0 END) > 0 THEN 'partial_dispatched'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'dispatched' THEN 1 ELSE 0 END) > 0 THEN 'dispatched'
                    ELSE 'confirmed'
                END AS delivery_status
            ")
                ->selectRaw("
                CASE
                    WHEN SUM(CASE WHEN order_lines.return_status = 'completed' THEN 1 ELSE 0 END) = COUNT(*) THEN 'completed'
                    WHEN SUM(CASE WHEN order_lines.return_status IN ('requested', 'approved', 'completed') THEN 1 ELSE 0 END) > 0 THEN 'partial_return'
                    ELSE 'none'
                END AS return_status
            ")
                ->groupBy('order_lines.order_id');

            // 2. Main Query
            $query = CreditNote::query()
                ->leftJoin('orders', 'orders.id', '=', 'credit_notes.order_id')
                ->leftJoin('refunds', 'refunds.id', '=', 'credit_notes.refund_id')
                ->leftJoin('invoices', 'invoices.order_line_id', '=', 'credit_notes.order_line_id') // NEW: Join invoice per line
                ->leftJoinSub($lineAggSub, 'line_agg', function ($join) {
                    $join->on('line_agg.order_id', '=', 'orders.id');
                })
                ->distinct()
                ->select([
                    // Credit Note Details
                    'credit_notes.id',
                    'credit_notes.credit_note_number',
                    'credit_notes.original_invoice_number',
                    'credit_notes.order_id',
                    'credit_notes.order_line_id',
                    'credit_notes.refund_id',

                    // Buyer Details
                    'credit_notes.buyer_name',
                    'credit_notes.buyer_email',
                    'credit_notes.buyer_address',
                    'credit_notes.buyer_state',
                    'credit_notes.buyer_gstin',
                    'credit_notes.buyer_type',

                    // Reason & Dates
                    'credit_notes.reason',
                    'credit_notes.issued_at',

                    // Financials (from credit_notes table)
                    'credit_notes.amount',
                    'credit_notes.taxable_value',
                    'credit_notes.cgst_amount',
                    'credit_notes.sgst_amount',
                    'credit_notes.igst_amount',
                    'credit_notes.total_gst',

                    // Raw Items JSON
                    'credit_notes.items',

                    // Invoice Data (Live from invoices table)
                    'invoices.invoice_number as live_invoice_number',
                    'invoices.created_at as invoice_created_at',

                    // Order Details
                    'orders.order_reference',
                    'orders.order_type',
                    'orders.status as order_status',
                    'orders.payment_gateway',
                    'orders.gateway_transaction_id',
                    'orders.subtotal',
                    'orders.shipping_charge as order_shipping_charge',
                    'orders.total_payable',
                    'orders.amount_paid',

                    // Refund Details
                    'refunds.amount as refund_amount',
                    'refunds.refund_method',
                    'refunds.status as refund_status',
                    'refunds.gateway_reference',
                    'refunds.completed_at as refund_completed_at',

                    // Aggregated Data
                    'line_agg.total_qty as item_count',
                    'line_agg.total_item_amount',
                    'line_agg.delivery_status',
                    'line_agg.return_status',
                ]);

            // 3. Filters
            if ($request->filled('order_id')) {
                $query->where('credit_notes.order_id', $request->order_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('credit_notes.issued_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('credit_notes.issued_at', '<=', $request->date_to);
            }

            if ($request->filled('buyer_type')) {
                $query->where('credit_notes.buyer_type', $request->buyer_type);
            }

            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('credit_notes.credit_note_number', 'LIKE', "%{$search}%")
                        ->orWhere('credit_notes.original_invoice_number', 'LIKE', "%{$search}%")
                        ->orWhere('invoices.invoice_number', 'LIKE', "%{$search}%") // Search live invoice too
                        ->orWhere('credit_notes.buyer_name', 'LIKE', "%{$search}%")
                        ->orWhere('credit_notes.buyer_email', 'LIKE', "%{$search}%")
                        ->orWhere('orders.order_reference', 'LIKE', "%{$search}%");
                });
            }

            // 4. Pagination
            $query->orderBy('credit_notes.issued_at', 'desc');
            $creditNotes = $query->paginate(20);

            // 5. Format Response
            $creditNotes->getCollection()->transform(function ($row, $index) use ($creditNotes) {
                // Parse the items JSON if it exists
                $itemsParsed = [];
                if (!empty($row->items)) {
                    try {
                        $itemsParsed = is_string($row->items) ? json_decode($row->items, true) : $row->items;
                    } catch (\Exception $e) {
                        $itemsParsed = [];
                    }
                }

                // Calculate S.No based on pagination
                $sno = ($creditNotes->currentPage() - 1) * $creditNotes->perPage() + $index + 1;

                return [
                    's_no' => $sno,

                    // Credit Note Details
                    'id' => $row->id,
                    'credit_note_number' => $row->credit_note_number,

                    // Pulled from the invoices table if it exists, otherwise fallback to the snapshot value
                    'original_invoice_number' => $row->live_invoice_number ?? $row->original_invoice_number,
                    'invoice_date' => $row->invoice_created_at ? \Carbon\Carbon::parse($row->invoice_created_at)->format('d-m-Y') : null,

                    'order_reference' => $row->order_reference,
                    'order_id' => $row->order_id,
                    'order_line_id' => $row->order_line_id,
                    'refund_id' => $row->refund_id,
                    'issued_at' => $row->issued_at,
                    'reason' => $row->reason,

                    // Buyer Details
                    'buyer_name' => $row->buyer_name,
                    'buyer_type' => $row->buyer_type,
                    'buyer_email' => $row->buyer_email,
                    'buyer_gstin' => $row->buyer_gstin ?? "URP",
                    'buyer_address' => $row->buyer_address,
                    'buyer_state' => $row->buyer_state,

                    // Order Details
                    'order_type' => $row->order_type,
                    'order_status' => $row->order_status,
                    'delivery_status' => $row->delivery_status ?? 'confirmed',
                    'return_status' => $row->return_status ?? 'none',
                    'payment_gateway' => $row->payment_gateway,
                    'gateway_transaction_id' => $row->gateway_transaction_id,

                    // Order Financials
                    'subtotal' => (float) $row->subtotal,
                    'shipping_charge' => (float) $row->order_shipping_charge,
                    'total_payable' => (float) $row->total_payable,
                    'amount_paid' => (float) $row->amount_paid,

                    // Actual Credit Note Financials
                    'credit_note_amount' => (float) $row->amount,
                    'taxable_value' => round((float) $row->taxable_value, 2),
                    'total_gst' => round((float) $row->total_gst, 2),

                    // GST Breakdown
                    'gst_breakdown' => [
                        'cgst' => round((float) $row->cgst_amount, 2),
                        'sgst' => round((float) $row->sgst_amount, 2),
                        'igst' => round((float) $row->igst_amount, 2),
                    ],

                    // Refund Details
                    'refund_amount' => (float) ($row->refund_amount ?? 0),
                    'refund_method' => $row->refund_method,
                    'refund_status' => $row->refund_status,
                    'gateway_reference' => $row->gateway_reference,
                    'refund_completed_at' => $row->refund_completed_at,

                    // Order Items Info
                    'item_count' => (int) ($row->item_count ?? 0),
                    'total_item_amount' => round((float) ($row->total_item_amount ?? 0), 2),

                    // Raw Items JSON array
                    'items' => $itemsParsed,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $creditNotes,
            ]);
        } catch (Exception $e) {
            Log::error('Admin credit notes list error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch credit notes.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Get single credit note details
     * GET /api/admin/credit-notes/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['order', 'refund'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $creditNote,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Credit note not found.',
            ], 404);
        }
    }

    /**
     * User: Get credit note details (with authorization check)
     * GET /api/credit-notes/{id}
     */
    public function userShow(int $id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['order'])->findOrFail($id);

            // Authorization: User can only view their own credit notes
            if ($creditNote->order->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this credit note.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $creditNote,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Credit note not found.',
            ], 404);
        }
    }

    /**
     * User: Get all credit notes for an order
     * GET /api/orders/{orderReference}/credit-notes
     */
    public function getByOrder(string $orderReference): JsonResponse
    {
        try {
            $order = Order::where('order_reference', $orderReference)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            $creditNotes = $order->creditNotes()
                ->orderBy('issued_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $creditNotes,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found or unauthorized.',
            ], 404);
        }
    }

    /**
     * Admin: Export credit notes to CSV (optional)
     * GET /api/admin/credit-notes/export
     */
    public function export(Request $request)
    {
        try {
            $query = CreditNote::with(['order']);

            if ($request->filled('date_from')) {
                $query->whereDate('issued_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('issued_at', '<=', $request->date_to);
            }

            $creditNotes = $query->orderBy('issued_at', 'desc')->get();

            if ($creditNotes->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No credit notes found for the selected period.',
                ], 404);
            }

            // Generate CSV
            $filename = 'credit-notes-' . now()->format('Y-m-d') . '.csv';
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"$filename\"",
            ];

            $callback = function () use ($creditNotes) {
                $file = fopen('php://output', 'w');
                fputcsv($file, [
                    'Credit Note Number',
                    'Original Invoice',
                    'Order Reference',
                    'Buyer Name',
                    'Buyer Email',
                    'Buyer State',
                    'Buyer Type',
                    'Taxable Value',
                    'CGST',
                    'SGST',
                    'IGST',
                    'Total GST',
                    'Total Amount',
                    'Reason',
                    'Issued At',
                ]);

                foreach ($creditNotes as $cn) {
                    fputcsv($file, [
                        $cn->credit_note_number,
                        $cn->original_invoice_number,
                        $cn->order->order_reference ?? '-',
                        $cn->buyer_name,
                        $cn->buyer_email,
                        $cn->buyer_state,
                        $cn->buyer_type,
                        number_format($cn->taxable_value, 2),
                        number_format($cn->cgst_amount, 2),
                        number_format($cn->sgst_amount, 2),
                        number_format($cn->igst_amount, 2),
                        number_format($cn->total_gst, 2),
                        number_format($cn->amount, 2),
                        $cn->reason,
                        $cn->issued_at->format('Y-m-d H:i:s'),
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } catch (Exception $e) {
            Log::error('Credit note export error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to export credit notes.',
            ], 500);
        }
    }

    /**
     * User: Download credit note data as JSON (for client-side PDF generation)
     * GET /api/credit-notes/{id}/download-data
     */
    public function downloadData(int $id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['order'])->findOrFail($id);

            // Authorization: User can only download their own credit notes
            if ($creditNote->order->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this credit note.',
                ], 403);
            }

            // Return structured data for frontend to generate PDF
            return response()->json([
                'success' => true,
                'data' => $this->formatCreditNoteData($creditNote),
            ]);
        } catch (Exception $e) {
            Log::error('Credit note download data error', [
                'credit_note_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Credit note not found.',
            ], 404);
        }
    }

    /**
     * Admin: Download credit note data as JSON (for client-side PDF generation)
     * GET /api/admin/credit-notes/{id}/download-data
     */
    public function downloadAdminData(int $id): JsonResponse
    {
        try {
            $creditNote = CreditNote::with(['order'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->formatCreditNoteData($creditNote),
            ]);
        } catch (Exception $e) {
            Log::error('Admin credit note download data error', [
                'credit_note_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Credit note not found.',
            ], 404);
        }
    }

    /**
     * Format credit note data for frontend
     */
    private function formatCreditNoteData(CreditNote $creditNote): array
    {
        return [
            'credit_note_number' => $creditNote->credit_note_number,
            'original_invoice_number' => $creditNote->original_invoice_number,
            'order_reference' => $creditNote->order->order_reference ?? '-',
            'issued_at' => $creditNote->issued_at->format('d M Y H:i:s'),
            'buyer' => [
                'name' => $creditNote->buyer_name,
                'email' => $creditNote->buyer_email,
                'address' => $creditNote->buyer_address,
                'state' => $creditNote->buyer_state,
                'gstin' => $creditNote->buyer_gstin,
                'type' => $creditNote->buyer_type,
            ],
            'items' => $creditNote->items ?? [],
            'totals' => [
                'taxable_value' => number_format($creditNote->taxable_value, 2),
                'cgst' => number_format($creditNote->cgst_amount, 2),
                'sgst' => number_format($creditNote->sgst_amount, 2),
                'igst' => number_format($creditNote->igst_amount, 2),
                'total_gst' => number_format($creditNote->total_gst, 2),
                'amount' => number_format($creditNote->amount, 2),
            ],
            'reason' => $creditNote->reason,
            'company' => [
                'name' => env('APP_NAME', 'IndieKonnect'),
                'gstin' => env('COMPANY_GSTIN', '22XXXXX'),
                'address' => env('COMPANY_ADDRESS', 'Punjab, India'),
                'state' => env('SUPPLIER_STATE', 'Punjab'),
            ],
        ];
    }
}
