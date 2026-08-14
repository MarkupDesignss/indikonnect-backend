<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $invoice;
    public $pdfContent;

    public function __construct($order, $invoice, $pdfContent)
    {
        $this->order = $order;
        $this->invoice = $invoice;
        $this->pdfContent = $pdfContent;
    }

    public function build()
    {
        return $this->subject('Order confirmation #' . $this->order->order_reference)
            ->view('emails.invoice')
            ->attachData($this->pdfContent, 'Order_' . $this->order->order_reference  . '.pdf', [
                'mime' => 'application/pdf',
            ]);
    }
}
