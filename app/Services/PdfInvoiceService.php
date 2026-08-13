<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use App\Mail\InvoiceMail;
use Exception;
use Illuminate\Support\Facades\Log;

class PdfInvoiceService
{
    public function generateInvoicePDF(Invoice $invoice)
    {
        $data = [
            'invoice' => $invoice,
            'order' => $invoice->order,
            'user' => $invoice->order->user,
            'lineItems' => json_decode($invoice->line_items, true),
            'summaryData' => json_decode($invoice->summary_snapshot, true),
        ];

        $pdf = PDF::loadView('pdf.invoice', $data);
        $pdf->setPaper('A4', 'portrait');

        return $pdf;
    }

    public function generateAndSendInvoice(Order $order, Invoice $invoice)
    {
        try {
            $pdf = $this->generateInvoicePDF($invoice);

            $pdfContent = $pdf->output();
            $pdfPath = 'invoices/invoice_' . $invoice->invoice_number . '.pdf';

            // Save PDF to storage
            Storage::disk('local')->put($pdfPath, $pdfContent);

            // Send email with PDF attachment
            Mail::to($order->user->email)->send(new InvoiceMail($order, $invoice, $pdfContent));

            // Update invoice with PDF path
            $invoice->update([
                'pdf_path' => $pdfPath
            ]);

            return $pdfPath;
        } catch (Exception $e) {
            Log::error('Failed to generate and send invoice: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id
            ]);
            throw $e;
        }
    }

    public function regenerateInvoice(Invoice $invoice)
    {
        try {
            $pdf = $this->generateInvoicePDF($invoice);
            $pdfContent = $pdf->output();
            $pdfPath = 'invoices/invoice_' . $invoice->invoice_number . '.pdf';

            Storage::disk('local')->put($pdfPath, $pdfContent);

            $invoice->update([
                'pdf_path' => $pdfPath
            ]);

            return $pdfPath;
        } catch (Exception $e) {
            Log::error('Failed to regenerate invoice: ' . $e->getMessage(), [
                'invoice_id' => $invoice->id
            ]);
            throw $e;
        }
    }
}
