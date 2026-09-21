<?php

namespace App\Services;

use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\User;
use App\Models\ProductImage;
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
     * Send notification to user based on template
     */
    // public function sendUserNotification(
    //     User $user,
    //     string $eventType,
    //     array $data = [],
    //     array $channels = ['database', 'mail']
    // ): bool {
    //     try {
    //         // Get active template for the event
    //         $template = $this->templateService->getTemplate($eventType, 'database');

    //         if (!$template) {
    //             Log::warning('No active template found for event', [
    //                 'event_type' => $eventType,
    //                 'user_id' => $user->id
    //             ]);
    //             return false;
    //         }

    //         // Render the template with data
    //         $rendered = $this->renderTemplate($template, $data);

    //         // Send to database
    //         if (in_array('database', $channels)) {
    //             $this->sendDatabaseNotification($user, $template, $rendered, $data);
    //         }

    //         // Send to mail
    //         if (in_array('mail', $channels) && $template->channel === 'email') {
    //             $this->sendMailNotification($user, $template, $rendered);
    //         }

    //         Log::info('Dynamic order confirmation notification sent to customer', [
    //             'event_type' => 'order_confirmed'
    //         ]);

    //         return true;
    //     } catch (\Exception $e) {
    //         Log::error('Failed to send notification: ' . $e->getMessage(), [
    //             'event_type' => $eventType,
    //             'user_id' => $user->id
    //         ]);
    //         return false;
    //     }
    // }

    public function sendUserNotification(
        User $user,
        string $eventType,
        array $data = [],
        array $channels = ['database', 'mail']
    ): bool {
        try {
            $template = $this->templateService->getTemplate($eventType, 'database');

            if (!$template) {
                Log::warning('No active template found for event', [
                    'event_type' => $eventType,
                    'user_id' => $user->id
                ]);

                return false;
            }

            $rendered = $this->renderTemplate($template, $data);

            $notificationSettings = $user->notificationSettings;

            // Default enabled if settings record does not exist
            $emailNotifications = $notificationSettings
                ? $notificationSettings->email_notifications
                : true;

            $promotionalEmails = $notificationSettings
                ? $notificationSettings->promotional_emails
                : true;

            $orderUpdates = $notificationSettings
                ? $notificationSettings->order_updates
                : true;

            $paymentAlerts = $notificationSettings
                ? $notificationSettings->payment_alerts
                : true;

            /*
        |--------------------------------------------------------------------------
        | Database / App Notification
        |--------------------------------------------------------------------------
        */

            if (in_array('database', $channels)) {

                if (
                    $this->isOrderUpdateEvent($eventType)
                    && !$orderUpdates
                ) {
                    Log::info('Order notification skipped', [
                        'user_id' => $user->id,
                        'event_type' => $eventType,
                        'reason' => 'order_updates disabled',
                    ]);
                } elseif (
                    $this->isPaymentAlertEvent($eventType)
                    && !$paymentAlerts
                ) {
                    Log::info('Payment notification skipped', [
                        'user_id' => $user->id,
                        'event_type' => $eventType,
                        'reason' => 'payment_alerts disabled',
                    ]);
                } else {
                    $this->sendDatabaseNotification(
                        $user,
                        $template,
                        $rendered,
                        $data
                    );
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Email Notification
        |--------------------------------------------------------------------------
        */

            if (
                in_array('mail', $channels)
                && $template->channel === 'email'
            ) {

                /*
            |--------------------------------------------------------------------------
            | Promotional Emails
            |--------------------------------------------------------------------------
            | Example: product_added
            | Controlled ONLY by promotional_emails
            |--------------------------------------------------------------------------
            */

                if ($this->isPromotionalEvent($eventType)) {

                    if ($promotionalEmails) {
                        $this->sendMailNotification(
                            $user,
                            $template,
                            $rendered
                        );

                        Log::info('Promotional email notification sent', [
                            'user_id' => $user->id,
                            'event_type' => $eventType,
                        ]);
                    } else {
                        Log::info('Promotional email notification skipped', [
                            'user_id' => $user->id,
                            'event_type' => $eventType,
                            'reason' => 'promotional_emails disabled',
                        ]);
                    }

                    /*
            |--------------------------------------------------------------------------
            | Payment Emails
            |--------------------------------------------------------------------------
            | Controlled by payment_alerts + email_notifications
            |--------------------------------------------------------------------------
            */
                } elseif ($this->isPaymentAlertEvent($eventType)) {

                    if ($paymentAlerts && $emailNotifications) {
                        $this->sendMailNotification(
                            $user,
                            $template,
                            $rendered
                        );
                    } else {
                        Log::info('Payment email notification skipped', [
                            'user_id' => $user->id,
                            'event_type' => $eventType,
                            'payment_alerts' => $paymentAlerts,
                            'email_notifications' => $emailNotifications,
                        ]);
                    }

                    /*
            |--------------------------------------------------------------------------
            | Other Emails
            |--------------------------------------------------------------------------
            | Controlled by email_notifications
            |--------------------------------------------------------------------------
            */
                } elseif ($emailNotifications) {

                    $this->sendMailNotification(
                        $user,
                        $template,
                        $rendered
                    );
                } else {
                    Log::info('Email notification skipped', [
                        'user_id' => $user->id,
                        'event_type' => $eventType,
                        'reason' => 'email_notifications disabled',
                    ]);
                }
            }

            return true;
        } catch (\Exception $e) {

            Log::error('Failed to send notification: ' . $e->getMessage(), [
                'event_type' => $eventType,
                'user_id' => $user->id
            ]);

            return false;
        }
    }

    protected function isPromotionalEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'product_added',
            'new_product',
        ]);
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
        ]);
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
        ]);
    }

    /**
     * Render template with data
     */
    protected function renderTemplate(NotificationTemplate $template, array $data): array
    {
        $subject = $template->subject ?? '';
        $body = $template->body ?? '';

        // Replace placeholders
        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $subject = str_replace('{{' . $key . '}}', $value, $subject);
                $body = str_replace('{{' . $key . '}}', $value, $body);
            }
        }

        Log::info('Dynamic order confirmation notification sent to customer', [
            'event_type' => 'order_confirmm'
        ]);

        return [
            'subject' => $subject,
            'body' => $body,
            'template' => $template
        ];
    }

    /**
     * Send database notification with extra data
     */
    protected function sendDatabaseNotification(User $user, NotificationTemplate $template, array $rendered, array $data = []): void
    {
        // Build extra data if event is order_confirmed
        $extraData = [];

        if ($template->event_type === 'order_confirmed' && isset($data['order_id'])) {
            $order = Order::with(['lines.product.images'])->find($data['order_id']);

            if ($order) {
                $extraData = [
                    'order_id' => $order->id,
                    'order_reference' => $order->order_reference,
                    'total_payable' => $order->total_payable,
                    'confirmed_at' => $order->confirmed_at ? $order->confirmed_at->toDateTimeString() : now()->toDateTimeString(),
                    'items' => $order->lines->map(function ($line) {
                        $product = $line->product;
                        $image = $product->images->first();

                        return [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_slug' => $product->slug ?? '',
                            'quantity' => $line->quantity,
                            'price' => $line->price,
                            'total' => $line->quantity * $line->price,
                            'image' => $image ? [
                                'id' => $image->id,
                                'url' => $image->url ?? $image->image_url ?? '',
                                'path' => $image->path ?? '',
                            ] : null,
                            // 'product_data' => $product->toArray()
                        ];
                    })->toArray()
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
     * Send mail notification using blade template
     */
    protected function sendMailNotification(User $user, NotificationTemplate $template, array $rendered): void
    {
        try {
            Mail::send('emails.notification', [
                'user' => $user,
                'subject' => $rendered['subject'],
                'body' => $rendered['body'],
                'template' => $template,
                'data' => $template->placeholders ?? []
            ], function ($message) use ($user, $rendered) {
                $message->to($user->email)
                    ->subject($rendered['subject']);
            });

            Log::info('Email notification sent to user', [
                'user_id' => $user->id,
                'event_type' => $template->event_type
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send email notification: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'event_type' => $template->event_type
            ]);
        }
    }
}
