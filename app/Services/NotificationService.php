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
    public function sendUserNotification(
        User $user,
        string $eventType,
        array $data = [],
        array $channels = ['database', 'mail']
    ): bool {
        try {
            // Get active template for the event
            $template = $this->templateService->getTemplate($eventType, 'database');

            if (!$template) {
                Log::warning('No active template found for event', [
                    'event_type' => $eventType,
                    'user_id' => $user->id
                ]);
                return false;
            }

            // Render the template with data
            $rendered = $this->renderTemplate($template, $data);

            // Send to database
            if (in_array('database', $channels)) {
                $this->sendDatabaseNotification($user, $template, $rendered, $data);
            }

            // Send to mail
            if (in_array('mail', $channels) && $template->channel === 'email') {
                $this->sendMailNotification($user, $template, $rendered);
            }

            Log::info('Dynamic order confirmation notification sent to customer', [
                'event_type' => 'order_confirmed'
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send notification: ' . $e->getMessage(), [
                'event_type' => $eventType,
                'user_id' => $user->id
            ]);
            return false;
        }
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
                            'product_data' => $product->toArray()
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
            $extraData  // Pass extra data
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
