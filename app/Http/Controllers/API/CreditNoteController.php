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

            // ✅ 1b. Invoice aggregate — ONE invoice_number + date PER order_line_id
            $invoiceSub = DB::table('invoices')
                ->select('order_line_id')
                ->selectRaw('MIN(invoice_number) as invoice_number')
                ->selectRaw('MIN(created_at) as invoice_created_at')
                ->whereNotNull('order_line_id')
                ->groupBy('order_line_id');

            // 2. Main Query
            $query = CreditNote::query()
                ->leftJoin('orders', 'orders.id', '=', 'credit_notes.order_id')
                ->leftJoin('refunds', 'refunds.id', '=', 'credit_notes.refund_id')
                // join aggregated invoice per order_line_id (no duplicate rows)
                ->leftJoinSub($invoiceSub, 'invoices', function ($join) {
                    $join->on('invoices.order_line_id', '=', 'credit_notes.order_line_id');
                })
                ->leftJoinSub($lineAggSub, 'line_agg', function ($join) {
                    $join->on('line_agg.order_id', '=', 'orders.id');
                })
                ->distinct()
                ->select([
                    'credit_notes.id',
                    'credit_notes.credit_note_number',
                    'credit_notes.original_invoice_number',
                    'credit_notes.order_id',
                    'credit_notes.order_line_id',
                    'credit_notes.refund_id',

                    'credit_notes.buyer_name',
                    'credit_notes.buyer_email',
                    'credit_notes.buyer_address',
                    'credit_notes.buyer_state',
                    'credit_notes.buyer_gstin',
                    'credit_notes.buyer_type',

                    'credit_notes.reason',
                    'credit_notes.issued_at',

                    'credit_notes.amount',
                    'credit_notes.taxable_value',
                    'credit_notes.cgst_amount',
                    'credit_notes.sgst_amount',
                    'credit_notes.igst_amount',
                    'credit_notes.total_gst',

                    // per credit-note line (order_line_id) invoice
                    'invoices.invoice_number as live_invoice_number',
                    'invoices.invoice_created_at as invoice_created_at',

                    'orders.order_reference',
                    'orders.order_type',
                    'orders.status as order_status',
                    'orders.payment_gateway',
                    'orders.gateway_transaction_id',
                    'orders.subtotal',
                    'orders.shipping_charge as order_shipping_charge',
                    'orders.total_payable',
                    'orders.amount_paid',

                    'refunds.amount as refund_amount',
                    'refunds.refund_method',
                    'refunds.status as refund_status',
                    'refunds.gateway_reference',
                    'refunds.completed_at as refund_completed_at',

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
                        ->orWhere('invoices.invoice_number', 'LIKE', "%{$search}%")
                        ->orWhere('credit_notes.buyer_name', 'LIKE', "%{$search}%")
                        ->orWhere('credit_notes.buyer_email', 'LIKE', "%{$search}%")
                        ->orWhere('orders.order_reference', 'LIKE', "%{$search}%");
                });
            }

            // 4. Pagination
            $query->orderBy('credit_notes.issued_at', 'desc');
            $creditNotes = $query->paginate(20);

            // 5. Fetch ALL order lines (with product, tax, INVOICE) for every order that has a credit note
            $orderIds = $creditNotes->getCollection()->pluck('order_id')->filter()->unique()->values()->all();

            $orderLines = collect();
            if (!empty($orderIds)) {
                $orderLines = DB::table('order_lines')
                    ->join('products', 'products.id', '=', 'order_lines.product_id')
                    ->leftJoin('tax_categories', 'tax_categories.id', '=', 'products.tax_category_id')
                    // invoice per order_line_id (aggregated — no row duplication)
                    ->leftJoinSub($invoiceSub, 'inv', function ($join) {
                        $join->on('inv.order_line_id', '=', 'order_lines.id');
                    })
                    ->whereIn('order_lines.order_id', $orderIds)
                    ->orderBy('order_lines.id', 'asc')
                    ->select([
                        'order_lines.order_id',
                        'order_lines.id as order_line_id',
                        'order_lines.quantity',
                        'order_lines.unit_price',
                        'order_lines.line_total',
                        // GST rate: prefer line-level, fall back to tax category
                        DB::raw('COALESCE(order_lines.gst_rate, tax_categories.rate, 0) as gst_rate'),
                        'order_lines.shipping_charge',
                        'products.name as product_name',
                        'products.slug as product_slug',
                        'products.hsn_code',
                        'products.product_code',
                        'products.service_charge',
                        // Product prices (resolved per buyer_type in PHP)
                        'products.retail_price',
                        'products.distributor_price',
                        // per-line invoice number + date
                        'inv.invoice_number as invoice_number',
                        'inv.invoice_created_at as invoice_created_at',
                    ])
                    ->get()
                    ->groupBy('order_id');
            }

            // Supplier state (for intra/inter state GST split)
            $supplierState = strtolower(trim(config('app.supplier_state', 'Punjab')));

            // 6. Format Response — FLATTENED PER ORDER LINE
            $flatData = [];

            $creditNotes->getCollection()->each(function ($row) use (
                $orderLines,
                $supplierState,
                &$flatData
            ) {
                // Resolve buyer/delivery state for GST split
                $buyerStateLower = strtolower(trim((string) ($row->buyer_state ?? '')));
                $isIntraState = ($supplierState === $buyerStateLower && !empty($buyerStateLower));

                // Buyer type (for product price selection)
                $buyerType = strtolower(trim((string) ($row->buyer_type ?? '')));

                // Order/credit-note level header (repeated per line)
                $header = [
                    'credit_note_number' => $row->credit_note_number,

                    // fallback — will be overridden per line below
                    'original_invoice_number' => $row->live_invoice_number ?? $row->original_invoice_number,
                    'invoice_date' => $row->invoice_created_at
                        ? \Carbon\Carbon::parse($row->invoice_created_at)->format('d-m-Y')
                        : null,

                    'order_reference' => $row->order_reference,
                    'order_id' => $row->order_id,
                    'order_line_id' => $row->order_line_id,
                    'refund_id' => $row->refund_id,
                    'issued_at' => $row->issued_at,
                    'reason' => $row->reason,

                    'buyer_name' => $row->buyer_name,
                    'buyer_type' => $row->buyer_type,
                    'buyer_email' => $row->buyer_email,
                    'buyer_gstin' => $row->buyer_gstin ?? "URP",
                    'buyer_address' => $row->buyer_address,
                    'buyer_state' => $row->buyer_state,

                    'order_type' => $row->order_type,
                    'order_status' => $row->order_status,
                    'delivery_status' => 'refunded',
                    'return_status' => 'refunded',
                    'payment_gateway' => $row->payment_gateway,
                    'gateway_transaction_id' => $row->gateway_transaction_id,

                    'credit_note_amount' => (float) $row->amount,
                    'refund_amount' => (float) $row->amount,

                    'refund_method' => $row->refund_method,
                    'refund_status' => $row->refund_status,
                    'gateway_reference' => $row->gateway_reference,
                    'refund_completed_at' => $row->refund_completed_at,
                ];

                $lines = $orderLines->get($row->order_id, collect());

                // No lines? Emit one zeroed row (no per-line invoice available)
                if ($lines->isEmpty()) {
                    $flatData[] = array_merge($header, [
                        // no line → no per-line invoice
                        'original_invoice_number' => null,
                        'invoice_date' => null,

                        'order_line_id' => $row->order_line_id,
                        'item_count' => 0,
                        'product_name' => null,
                        'product_slug' => null,
                        'hsn_code' => null,
                        'product_code' => null,
                        'product_price' => 0,
                        'quantity' => 0,
                        'unit_price' => 0,
                        'line_total' => 0,
                        'gst_rate' => 0,
                        'shipping_charge_line' => 0,
                        'service_charge' => 0,
                        'item_taxable_value' => 0,
                        'item_product_gst' => 0,
                        'item_shipping_gst' => 0,
                        'item_cgst' => 0,
                        'item_sgst' => 0,
                        'item_igst' => 0,
                        'item_total_gst' => 0,
                        'gst_breakdown' => [
                            'cgst' => 0,
                            'sgst' => 0,
                            'igst' => 0,
                        ],
                        'item_total' => 0,
                    ]);
                    return;
                }

                // One flat row per order line — every line has its own invoice + gst_rate + GST split
                foreach ($lines as $line) {
                    $qty = (float) ($line->quantity ?? 0);
                    $unitPrice = (float) ($line->unit_price ?? 0);
                    $lineTotal = (float) ($line->line_total ?? 0);
                    $gstRate = (float) ($line->gst_rate ?? 0);   // COALESCE(line.gst_rate, tax_categories.rate, 0)
                    $shippingCharge = (float) ($line->shipping_charge ?? 0);
                    $serviceChargePerUnit = (float) ($line->service_charge ?? 0);

                    // Product price based on buyer_type
                    $productPrice = ($buyerType === 'distributor')
                        ? (float) ($line->distributor_price ?? 0)
                        : (float) ($line->retail_price ?? 0);

                    $divisor = 1 + ($gstRate / 100);

                    // GST extraction (inclusive prices) — per line, independent
                    $productGst = $divisor > 0 ? ($lineTotal - ($lineTotal / $divisor)) : 0;
                    $shippingGst = $divisor > 0 ? ($shippingCharge - ($shippingCharge / $divisor)) : 0;

                    $totalServiceCharge = $serviceChargePerUnit * $qty;

                    $itemTaxableValue = $divisor > 0 ? ($lineTotal / $divisor) : $lineTotal;

                    $itemTotalGst = $productGst + $shippingGst;

                    // CGST/SGST/IGST split per line
                    if ($isIntraState) {
                        $itemCgst = round($itemTotalGst / 2, 2);
                        $itemSgst = round($itemTotalGst / 2, 2);
                        $itemIgst = 0;
                    } else {
                        $itemCgst = 0;
                        $itemSgst = 0;
                        $itemIgst = round($itemTotalGst, 2);
                    }

                    $itemTotal = $lineTotal + $shippingCharge + $totalServiceCharge;

                    // per-line invoice (fallback to credit-note level if missing)
                    $lineInvoiceNumber = $line->invoice_number
                        ?? ($row->live_invoice_number ?? $row->original_invoice_number);

                    $lineInvoiceDate = $line->invoice_created_at
                        ? \Carbon\Carbon::parse($line->invoice_created_at)->format('d-m-Y')
                        : ($row->invoice_created_at
                            ? \Carbon\Carbon::parse($row->invoice_created_at)->format('d-m-Y')
                            : null);

                    $flatData[] = array_merge($header, [
                        's_no' => count($flatData) + 1,

                        'order_line_id' => $line->order_line_id,

                        // PER-LINE invoice — overrides the header's order-wide value
                        'original_invoice_number' => $lineInvoiceNumber,
                        'invoice_date' => $lineInvoiceDate,

                        'item_count' => (int) ($line->quantity ?? 0),
                        'product_name' => $line->product_name,
                        'product_slug' => $line->product_slug,
                        'hsn_code' => $line->hsn_code,
                        'product_code' => $line->product_code,
                        // product_price resolved by buyer_type (distributor_price / retail_price)
                        'product_price' => round($productPrice, 2),
                        'quantity' => $qty,
                        'unit_price' => round($unitPrice, 2),
                        'line_total' => round($lineTotal, 2),
                        'gst_rate' => round($gstRate, 2),
                        'shipping_charge_line' => round($shippingCharge, 2),
                        'service_charge' => round($serviceChargePerUnit, 2),

                        'item_taxable_value' => round($itemTaxableValue, 2),
                        'item_product_gst' => round($productGst, 2),
                        'item_shipping_gst' => round($shippingGst, 2),
                        'item_cgst' => $itemCgst,
                        'item_sgst' => $itemSgst,
                        'item_igst' => $itemIgst,
                        'item_total_gst' => round($itemTotalGst, 2),

                        // gst_breakdown is PER ITEM (line-level split), not credit-note level
                        'gst_breakdown' => [
                            'cgst' => $itemCgst,
                            'sgst' => $itemSgst,
                            'igst' => $itemIgst,
                        ],

                        'item_total' => round($itemTotal, 2),
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => $flatData,
                    'current_page' => $creditNotes->currentPage(),
                    'per_page' => $creditNotes->perPage(),
                    'total' => $creditNotes->total(),
                    'last_page' => $creditNotes->lastPage(),
                    'from' => $creditNotes->firstItem(),
                    'to' => $creditNotes->lastItem(),
                ],
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

    /**
     * Flattened, paginated credit notes list for the HQ team (no auth token required).
     *
     * Expands each credit note into one row per order line, including per-line
     * invoice details, GST breakdown (CGST/SGST for intra-state, IGST otherwise),
     * refund info, and buyer details. Prices resolved by buyer type
     * (distributor_price vs retail_price).
     *
     * @param  Request  $request  Optional filters: order_id, date_from, date_to, buyer_type, search.
     * @return JsonResponse       { success, data: { data[], current_page, per_page, total, last_page, from, to } }
     *
     * @throws Exception          Logged and returned as HTTP 500 with { success: false }.
     *
     * @internal Public endpoint — protect via IP allowlist or shared-secret header.
     */

    public function hqCreditNotes(Request $request): JsonResponse
    {
        try {
            // 1. Order-level line aggregation (delivery/return status + totals)
            $lineAggSub = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
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

            // 2. Invoice aggregate — one invoice per order_line_id
            $invoiceSub = DB::table('invoices')
                ->select('order_line_id')
                ->selectRaw('MIN(invoice_number) as invoice_number')
                ->selectRaw('MIN(created_at) as invoice_created_at')
                ->whereNotNull('order_line_id')
                ->groupBy('order_line_id');

            // 3. Main query
            $query = CreditNote::query()
                ->leftJoin('orders', 'orders.id', '=', 'credit_notes.order_id')
                ->leftJoin('refunds', 'refunds.id', '=', 'credit_notes.refund_id')
                ->leftJoinSub($invoiceSub, 'invoices', function ($join) {
                    $join->on('invoices.order_line_id', '=', 'credit_notes.order_line_id');
                })
                ->leftJoinSub($lineAggSub, 'line_agg', function ($join) {
                    $join->on('line_agg.order_id', '=', 'orders.id');
                })
                ->select([
                    // Credit note
                    'credit_notes.id',
                    'credit_notes.credit_note_number',
                    'credit_notes.original_invoice_number',
                    'credit_notes.order_id',
                    'credit_notes.order_line_id',
                    'credit_notes.reason',
                    'credit_notes.issued_at',
                    'credit_notes.amount',
                    'credit_notes.taxable_value',
                    'credit_notes.cgst_amount',
                    'credit_notes.sgst_amount',
                    'credit_notes.igst_amount',
                    'credit_notes.total_gst',

                    // Buyer
                    'credit_notes.buyer_name',
                    'credit_notes.buyer_email',
                    'credit_notes.buyer_address',
                    'credit_notes.buyer_state',
                    'credit_notes.buyer_gstin',
                    'credit_notes.buyer_type',

                    // Live invoice (per line)
                    'invoices.invoice_number as live_invoice_number',
                    'invoices.invoice_created_at as invoice_created_at',

                    // Order
                    'orders.order_reference',
                    'orders.order_type',
                    'orders.status as order_status',
                    'orders.payment_gateway',
                    'orders.subtotal',
                    'orders.shipping_charge as order_shipping_charge',
                    'orders.total_payable',
                    'orders.amount_paid',

                    // Refund
                    'refunds.amount as refund_amount',
                    'refunds.refund_method',
                    'refunds.status as refund_status',
                    'refunds.completed_at as refund_completed_at',

                    // Aggregated line status
                    'line_agg.total_qty as item_count',
                    'line_agg.total_item_amount',
                    'line_agg.delivery_status',
                    'line_agg.return_status',
                ]);

            // 4. Filters
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
                        ->orWhere('invoices.invoice_number', 'LIKE', "%{$search}%")
                        ->orWhere('credit_notes.buyer_name', 'LIKE', "%{$search}%")
                        ->orWhere('credit_notes.buyer_email', 'LIKE', "%{$search}%")
                        ->orWhere('orders.order_reference', 'LIKE', "%{$search}%");
                });
            }

            // 5. Paginate
            $query->orderBy('credit_notes.issued_at', 'desc');
            $creditNotes = $query->paginate(20);

            // 6. Fetch order lines for all orders in this page
            $orderIds = $creditNotes->getCollection()->pluck('order_id')->filter()->unique()->values()->all();

            $orderLines = collect();
            if (!empty($orderIds)) {
                $orderLines = DB::table('order_lines')
                    ->join('products', 'products.id', '=', 'order_lines.product_id')
                    ->leftJoin('tax_categories', 'tax_categories.id', '=', 'products.tax_category_id')
                    ->leftJoinSub($invoiceSub, 'inv', function ($join) {
                        $join->on('inv.order_line_id', '=', 'order_lines.id');
                    })
                    ->whereIn('order_lines.order_id', $orderIds)
                    ->orderBy('order_lines.id', 'asc')
                    ->select([
                        'order_lines.order_id',
                        'order_lines.id as order_line_id',
                        'order_lines.quantity',
                        'order_lines.unit_price',
                        'order_lines.line_total',
                        DB::raw('COALESCE(order_lines.gst_rate, tax_categories.rate, 0) as gst_rate'),
                        'order_lines.shipping_charge',
                        'products.name as product_name',
                        'products.hsn_code',
                        'products.service_charge',
                        'products.retail_price',
                        'products.distributor_price',
                        'inv.invoice_number as invoice_number',
                        'inv.invoice_created_at as invoice_created_at',
                    ])
                    ->get()
                    ->groupBy('order_id');
            }

            $supplierState = strtolower(trim(config('app.supplier_state', 'Punjab')));

            // 7. Build response
            $flatData = [];

            $creditNotes->getCollection()->each(function ($row) use ($orderLines, $supplierState, &$flatData) {
                $buyerStateLower = strtolower(trim((string) ($row->buyer_state ?? '')));
                $isIntraState = ($supplierState === $buyerStateLower && !empty($buyerStateLower));
                $buyerType = strtolower(trim((string) ($row->buyer_type ?? '')));

                $lines = $orderLines->get($row->order_id, collect());

                // Base (header) data — only HQ-relevant fields
                $base = [
                    'credit_note_number' => $row->credit_note_number,
                    'credit_note_date' => $row->issued_at
                        ? \Carbon\Carbon::parse($row->issued_at)->format('d-m-Y')
                        : null,
                    'reason' => $row->reason,
                    'order_reference' => $row->order_reference,
                    'order_type' => $row->order_type,

                    'buyer' => [
                        'name' => $row->buyer_name,
                        'type' => $row->buyer_type,
                        'email' => $row->buyer_email,
                        'gstin' => $row->buyer_gstin ?? 'URP',
                        'address' => $row->buyer_address,
                        'state' => $row->buyer_state,
                    ],

                    'credit_note_amount' => (float) $row->amount,
                    'taxable_value' => (float) $row->taxable_value,
                    'cgst' => (float) $row->cgst_amount,
                    'sgst' => (float) $row->sgst_amount,
                    'igst' => (float) $row->igst_amount,
                    'total_gst' => (float) $row->total_gst,

                    'refund' => [
                        'amount' => (float) ($row->refund_amount ?? 0),
                        'method' => $row->refund_method,
                        'status' => $row->refund_status,
                        'completed_at' => $row->refund_completed_at,
                    ],

                    'order_status' => $row->order_status,
                    'delivery_status' => 'refunded',
                    'return_status' => 'refunded',
                ];

                // No lines → single zero row
                if ($lines->isEmpty()) {
                    $flatData[] = array_merge($base, [
                        's_no' => count($flatData) + 1,
                        'original_invoice_number' => null,
                        'invoice_date' => null,
                        'item' => null,
                        'gst_breakdown' => ['cgst' => 0, 'sgst' => 0, 'igst' => 0],
                        'item_total' => 0,
                    ]);
                    return;
                }

                // One row per order line
                foreach ($lines as $line) {
                    $qty = (float) ($line->quantity ?? 0);
                    $unitPrice = (float) ($line->unit_price ?? 0);
                    $lineTotal = (float) ($line->line_total ?? 0);
                    $gstRate = (float) ($line->gst_rate ?? 0);
                    $shippingCharge = (float) ($line->shipping_charge ?? 0);
                    $serviceChargePerUnit = (float) ($line->service_charge ?? 0);

                    $productPrice = ($buyerType === 'distributor')
                        ? (float) ($line->distributor_price ?? 0)
                        : (float) ($line->retail_price ?? 0);

                    $divisor = 1 + ($gstRate / 100);
                    $productGst = $divisor > 0 ? ($lineTotal - ($lineTotal / $divisor)) : 0;
                    $shippingGst = $divisor > 0 ? ($shippingCharge - ($shippingCharge / $divisor)) : 0;
                    $itemTaxableValue = $divisor > 0 ? ($lineTotal / $divisor) : $lineTotal;
                    $itemTotalGst = $productGst + $shippingGst;
                    $totalServiceCharge = $serviceChargePerUnit * $qty;
                    $itemTotal = $lineTotal + $shippingCharge + $totalServiceCharge;

                    if ($isIntraState) {
                        $itemCgst = round($itemTotalGst / 2, 2);
                        $itemSgst = round($itemTotalGst / 2, 2);
                        $itemIgst = 0;
                    } else {
                        $itemCgst = 0;
                        $itemSgst = 0;
                        $itemIgst = round($itemTotalGst, 2);
                    }

                    $lineInvoiceNumber = $line->invoice_number
                        ?? ($row->live_invoice_number ?? $row->original_invoice_number);
                    $lineInvoiceDate = $line->invoice_created_at
                        ? \Carbon\Carbon::parse($line->invoice_created_at)->format('d-m-Y')
                        : ($row->invoice_created_at
                            ? \Carbon\Carbon::parse($row->invoice_created_at)->format('d-m-Y')
                            : null);

                    $flatData[] = array_merge($base, [
                        's_no' => count($flatData) + 1,
                        'original_invoice_number' => $lineInvoiceNumber,
                        'invoice_date' => $lineInvoiceDate,

                        'item' => [
                            'product_name' => $line->product_name,
                            'hsn_code' => $line->hsn_code,
                            'product_price' => round($productPrice, 2),
                            'quantity' => $qty,
                            'unit_price' => round($unitPrice, 2),
                            'line_total' => round($lineTotal, 2),
                            'gst_rate' => round($gstRate, 2),
                            'shipping_charge' => round($shippingCharge, 2),
                            'service_charge' => round($serviceChargePerUnit, 2),
                            'taxable_value' => round($itemTaxableValue, 2),
                            'product_gst' => round($productGst, 2),
                            'shipping_gst' => round($shippingGst, 2),
                        ],

                        'gst_breakdown' => [
                            'cgst' => $itemCgst,
                            'sgst' => $itemSgst,
                            'igst' => $itemIgst,
                        ],
                        'item_total_gst' => round($itemTotalGst, 2),
                        'item_total' => round($itemTotal, 2),
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => $flatData,
                    'current_page' => $creditNotes->currentPage(),
                    'per_page' => $creditNotes->perPage(),
                    'total' => $creditNotes->total(),
                    'last_page' => $creditNotes->lastPage(),
                    'from' => $creditNotes->firstItem(),
                    'to' => $creditNotes->lastItem(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('HQ credit notes list error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch credit notes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}