<?php

namespace App\Notifications;

use App\Models\PayoutRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PayoutReleasedNotification extends Notification
{
    use Queueable;

    protected PayoutRun $payoutRun;
    protected array $entryData;

    public function __construct(PayoutRun $payoutRun, array $entryData)
    {
        $this->payoutRun = $payoutRun;
        $this->entryData = $entryData;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject("Payout Released – Period {$this->payoutRun->period}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your commission payout for period {$this->payoutRun->period} has been released.")
            ->line("**Gross Commission:** ₹" . number_format($this->entryData['gross_commission'], 2))
            ->line("**TDS Deducted (2%):** ₹" . number_format($this->entryData['tds'], 2))
            ->line("**Net Payable:** ₹" . number_format($this->entryData['net_payable'], 2))
            ->action('View Ledger', url('/distributor/ledger'))
            ->line('Thank you for being part of IndieKonnect!');
    }

    public function toArray($notifiable)
    {
        return [
            'title' => "Payout Released – Period {$this->payoutRun->period}",
            'message' => "Your payout of ₹" . number_format($this->entryData['net_payable'], 2) . " has been released.",
            'payout_run_id' => $this->payoutRun->id,
            'period' => $this->payoutRun->period,
            'gross' => $this->entryData['gross_commission'],
            'tds' => $this->entryData['tds'],
            'net' => $this->entryData['net_payable'],
        ];
    }
}