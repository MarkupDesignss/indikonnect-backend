<?php

namespace App\Mail;

use App\Models\ProformaInvoice;
use App\Services\ProformaInvoiceService;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProformaInvoiceMail extends Mailable
{
    use SerializesModels;

    public ProformaInvoice $invoice;
    public string $pdfContent;

    public function __construct(ProformaInvoice $invoice, string $pdfContent)
    {
        $this->invoice    = $invoice;
        $this->pdfContent = $pdfContent;
    }

    public function build()
    {
        $cleanName = str_replace(['/', '\\'], '-', $this->invoice->proforma_invoice_number);

        $amountInWords = app(ProformaInvoiceService::class)
            ->numberToWords((float) $this->invoice->total_payable);

        return $this->subject('Proforma Invoice - ' . $this->invoice->proforma_invoice_number)
            ->view('emails.proforma-invoice')
            ->with([
                'invoice'        => $this->invoice,
                'orderReference' => $this->invoice->summary_snapshot['order_reference'] ?? 'N/A',
                'amountInWords'  => $amountInWords,   // ✅ ab blade me milega
            ])
            ->attachData($this->pdfContent, $cleanName . '.pdf', [
                'mime' => 'application/pdf',
            ]);
    }
}
