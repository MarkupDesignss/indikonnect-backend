<?php

namespace App\Mail;

use App\Models\OrderReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReturnInitiatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public OrderReturn $returnOrder;
    public array $returnItems;
    public array $refundDetails;
    public string $returnMethod;
    public ?string $trackingId;
    public ?string $courier;
    public string $userName;

    public function __construct(
        OrderReturn $returnOrder,
        array $returnItems,
        array $refundDetails,
        string $userName
    ) {
        $this->returnOrder   = $returnOrder;
        $this->returnItems   = $returnItems;
        $this->refundDetails = $refundDetails;
        $this->returnMethod  = $returnOrder->return_method;
        $this->trackingId    = $returnOrder->tracking_id;
        $this->courier       = $returnOrder->courier;
        $this->userName      = $userName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Return Request Initiated - Order #' . $this->returnOrder->order->order_reference,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.return-initiated',
            with: [
                'returnOrder'   => $this->returnOrder,
                'returnItems'   => $this->returnItems,
                'refundDetails' => $this->refundDetails,
                'returnMethod'  => $this->returnMethod,
                'trackingId'    => $this->trackingId,
                'courier'       => $this->courier,
                'userName'      => $this->userName,
            ],
        );
    }
}
