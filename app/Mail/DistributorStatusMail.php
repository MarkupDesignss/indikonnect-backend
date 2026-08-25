<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DistributorStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $distributor,
        public string $status
    ) {}

    public function build()
    {
        $subject = $this->status === 'active'
            ? 'Your Distributor Account Has Been Activated'
            : 'Your Distributor Account Has Been Suspended';

        return $this->subject($subject)
            ->view('emails.distributor-status');
    }
}