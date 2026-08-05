<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Subscriber;

class NewSubscriptionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Subscriber $subscriber)
    {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Newsletter Subscription',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-subscription',
            with: [
                'email' => $this->subscriber->email,
                'subscribedAt' => $this->subscriber->subscribed_at,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
