<?php

namespace App\Mail;

use App\Models\CorrectionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailUpdateLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $link;
    public string $userName;
    public string $newEmail;
    public int $expiresInMinutes;

    public function __construct(public CorrectionRequest $correctionRequest, string $token)
    {
        $this->link             = url("/email-update/{$token}");
        $this->userName         = $correctionRequest->user->full_name ?? 'User';
        $this->newEmail         = $correctionRequest->new_email ?? '';
        $this->expiresInMinutes = 10;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm Your New Email Address',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-update-link',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
