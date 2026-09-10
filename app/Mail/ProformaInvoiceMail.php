<?php

namespace App\Mail;

use App\Models\ProformaInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProformaInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ProformaInvoice $invoice,
        public string $pdfPath
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Proforma Invoice - ' . $this->invoice->proforma_invoice_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proforma-invoice',
            with: [
                'invoice' => $this->invoice,
                'orderReference' => $this->invoice->summary_snapshot['order_reference'] ?? 'N/A',
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->pdfPath)
                ->as($this->invoice->proforma_invoice_number . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
