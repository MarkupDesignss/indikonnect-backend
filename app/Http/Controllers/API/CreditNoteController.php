<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
            $query = CreditNote::with(['order', 'refund']);

            // Filters
            if ($request->filled('order_id')) {
                $query->where('order_id', $request->order_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('issued_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('issued_at', '<=', $request->date_to);
            }

            if ($request->filled('buyer_type')) {
                $query->where('buyer_type', $request->buyer_type);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('credit_note_number', 'LIKE', "%{$search}%")
                      ->orWhere('original_invoice_number', 'LIKE', "%{$search}%")
                      ->orWhere('buyer_name', 'LIKE', "%{$search}%")
                      ->orWhere('buyer_email', 'LIKE', "%{$search}%");
                });
            }

            $creditNotes = $query->orderBy('issued_at', 'desc')->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $creditNotes,
            ]);
        } catch (Exception $e) {
            Log::error('Admin credit notes list error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch credit notes.',
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