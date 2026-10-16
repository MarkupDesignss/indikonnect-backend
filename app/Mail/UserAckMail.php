<?php

namespace App\Mail;

use App\Models\CorrectionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserAckMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CorrectionRequest $req) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'We received your correction request — #' . $this->req->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.correction.user',
        );
    }
}
