<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;

class DynamicNotification extends Notification
{
    use Queueable;

    protected string $type;
    protected string $title;
    protected string $message;
    protected array $placeholders;
    protected array $extraData;

    public function __construct(
        string $type,
        string $title,
        string $message,
        array $placeholders = [],
        array $extraData = []  // Add this parameter
    ) {
        $this->type = $type;
        $this->title = $title;
        $this->message = $message;
        $this->placeholders = $placeholders;
        $this->extraData = $extraData;  // Store extra data
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification for database.
     */
    public function toDatabase($notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'placeholders' => $this->placeholders,
            'extra_data' => $this->extraData,  // Include extra data
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
        ];
    }

    /**
     * Get the broadcast representation of the notification.
     */
    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'data' => $this->toDatabase($notifiable),
            'read_at' => null,
            'created_at' => now()->toISOString(),
        ]);
    }

    protected function getIcon(): string
    {
        $icons = [
            'order_confirmed' => 'check-circle',
            'order_shipped' => 'truck',
            'order_delivered' => 'package',
            'payment_received' => 'credit-card',
            'default' => 'bell'
        ];

        return $icons[$this->type] ?? $icons['default'];
    }

    protected function getColor(): string
    {
        $colors = [
            'order_confirmed' => 'success',
            'order_shipped' => 'info',
            'order_delivered' => 'primary',
            'payment_received' => 'warning',
            'default' => 'secondary'
        ];

        return $colors[$this->type] ?? $colors['default'];
    }
}
