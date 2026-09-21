<?php

namespace App\Services;

use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected NotificationTemplateService $templateService;

    public function __construct(NotificationTemplateService $templateService)
    {
        $this->templateService = $templateService;
    }

    /**
     * Send notification to user based on template.
     *
     * Each channel resolves its OWN template row (database vs email),
     * so an active email template is actually used for mail delivery.
     */
    public function sendUserNotification(
        User $user,
        string $eventType,
        array $data = [],
        array $channels = ['database', 'mail']
    ): bool {
        try {
            $notificationSettings = $user->notificationSettings;

            // Defaults if no settings row exists
            $emailNotifications  = $notificationSettings ? (bool) $notificationSettings->email_notifications  : true;
            $promotionalEmails   = $notificationSettings ? (bool) $notificationSettings->promotional_emails   : true;
            $orderUpdates        = $notificationSettings ? (bool) $notificationSettings->order_updates        : true;
            $paymentAlerts       = $notificationSettings ? (bool) $notificationSettings->payment_alerts       : true;

            $isPromotional = $this->isPromotionalEvent($eventType);
            $isPayment     = $this->isPaymentAlertEvent($eventType);
            $isOrder       = $this->isOrderUpdateEvent($eventType);

            foreach ($channels as $channel) {
                // 'mail' channel maps to 'email' template channel in DB
                $templateChannel = $channel === 'mail' ? 'email' : $channel;

                $template = $this->templateService->getTemplate($eventType, $templateChannel);

                if (!$template) {
                    Log::warning('No active template found for event', [
                        'event_type' => $eventType,
                        'channel'    => $templateChannel,
                        'user_id'    => $user->id,
                    ]);
                    continue;
                }

                $rendered = $this->renderTemplate($template, $data);

                /*
                |------------------------------------------------------------------
                | Database / App Notification
                |------------------------------------------------------------------
                */
                if ($channel === 'database') {
                    if ($isOrder && !$orderUpdates) {
                        Log::info('Order notification skipped', [
                            'user_id'    => $user->id,
                            'event_type' => $eventType,
                            'reason'     => 'order_updates disabled',
                        ]);
                        continue;
                    }

                    if ($isPayment && !$paymentAlerts) {
                        Log::info('Payment notification skipped', [
                            'user_id'    => $user->id,
                            'event_type' => $eventType,
                            'reason'     => 'payment_alerts disabled',
                        ]);
                        continue;
                    }

                    $this->sendDatabaseNotification($user, $template, $rendered, $data);
                    continue;
                }

                /*
                |------------------------------------------------------------------
                | Email Notification
                |------------------------------------------------------------------
                */
                if ($channel === 'mail') {
                    // Promotional events -> controlled ONLY by promotional_emails
                    if ($isPromotional) {
                        if ($promotionalEmails) {
                            $this->sendMailNotification($user, $template, $rendered);
                            Log::info('Promotional email notification sent', [
                                'user_id'    => $user->id,
                                'event_type' => $eventType,
                            ]);
                        } else {
                            Log::info('Promotional email notification skipped', [
                                'user_id'    => $user->id,
                                'event_type' => $eventType,
                                'reason'     => 'promotional_emails disabled',
                            ]);
                        }
                        continue;
                    }

                    // Payment events -> controlled by payment_alerts + email_notifications
                    if ($isPayment) {
                        if ($paymentAlerts && $emailNotifications) {
                            $this->sendMailNotification($user, $template, $rendered);
                        } else {
                            Log::info('Payment email notification skipped', [
                                'user_id'             => $user->id,
                                'event_type'          => $eventType,
                                'payment_alerts'      => $paymentAlerts,
                                'email_notifications' => $emailNotifications,
                            ]);
                        }
                        continue;
                    }

                    // All other events -> controlled by email_notifications
                    if ($emailNotifications) {
                        $this->sendMailNotification($user, $template, $rendered);
                    } else {
                        Log::info('Email notification skipped', [
                            'user_id'    => $user->id,
                            'event_type' => $eventType,
                            'reason'     => 'email_notifications disabled',
                        ]);
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send notification: ' . $e->getMessage(), [
                'event_type' => $eventType,
                'user_id'    => $user->id,
            ]);

            return false;
        }
    }

    protected function isPromotionalEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'product_added',
            'new_product',
        ], true);
    }

    protected function isPaymentAlertEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'refund_processed',
            'refund_completed',
            'refund_failed',
            'payment_received',
            'payment_failed',
            'payment_refunded',
        ], true);
    }

    protected function isOrderUpdateEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'order_confirmed',
            'order_processing',
            'order_dispatched',
            'order_shipped',
            'order_delivered',
            'order_cancelled',
            'order_returned',
            'order_refunded',
            'order_status_updated',
        ], true);
    }

    /**
     * Render template with data.
     */
    protected function renderTemplate(NotificationTemplate $template, array $data): array
    {
        $subject = $template->subject ?? '';
        $body    = $template->body ?? '';

        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $subject = str_replace('{{' . $key . '}}', (string) $value, $subject);
                $body    = str_replace('{{' . $key . '}}', (string) $value, $body);
            }
        }

        Log::info('Notification template rendered', [
            'event_type'  => $template->event_type,
            'template_id' => $template->id,
            'channel'     => $template->channel,
        ]);

        return [
            'subject'  => $subject,
            'body'     => $body,
            'template' => $template,
        ];
    }

    /**
     * Send database notification with extra data.
     */
    protected function sendDatabaseNotification(
        User $user,
        NotificationTemplate $template,
        array $rendered,
        array $data = []
    ): void {
        $extraData = [];

        if ($template->event_type === 'order_confirmed' && isset($data['order_id'])) {
            $order = Order::with(['lines.product.images'])->find($data['order_id']);

            if ($order) {
                $extraData = [
                    'order_id'        => $order->id,
                    'order_reference' => $order->order_reference,
                    'total_payable'   => $order->total_payable,
                    'confirmed_at'    => $order->confirmed_at
                        ? $order->confirmed_at->toDateTimeString()
                        : now()->toDateTimeString(),
                    'items' => $order->lines->map(function ($line) {
                        $product = $line->product;
                        $image   = $product?->images->first();

                        return [
                            'product_id'   => $product?->id,
                            'product_name' => $product?->name,
                            'product_slug' => $product?->slug ?? '',
                            'quantity'     => $line->quantity,
                            'price'        => $line->price,
                            'total'        => $line->quantity * $line->price,
                            'image'        => $image ? [
                                'id'   => $image->id,
                                'url'  => $image->url ?? $image->image_url ?? '',
                                'path' => $image->path ?? '',
                            ] : null,
                        ];
                    })->toArray(),
                ];
            }
        }

        $user->notify(new \App\Notifications\DynamicNotification(
            $template->event_type,
            $rendered['subject'],
            $rendered['body'],
            $template->placeholders ?? [],
            $extraData
        ));
    }

    /**
     * Send mail notification using blade template.
     */
    protected function sendMailNotification(
        User $user,
        NotificationTemplate $template,
        array $rendered
    ): void {
        try {
            Mail::send('emails.notification', [
                'user'     => $user,
                'subject'  => $rendered['subject'],
                'body'     => $rendered['body'],
                'template' => $template,
                'data'     => $template->placeholders ?? [],
            ], function ($message) use ($user, $rendered) {
                $message->to($user->email)
                    ->subject($rendered['subject']);
            });

            Log::info('Email notification sent to user', [
                'user_id'    => $user->id,
                'event_type' => $template->event_type,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send email notification: ' . $e->getMessage(), [
                'user_id'    => $user->id,
                'event_type' => $template->event_type,
            ]);
        }
    }
}
