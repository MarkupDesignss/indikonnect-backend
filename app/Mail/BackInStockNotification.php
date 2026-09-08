<?php
// app/Mail/BackInStockNotification.php

namespace App\Mail;

use App\Models\NotifyMe;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BackInStockNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $notifyMe;
    public $product;
    public $variant;
    public $notificationType;

    public function __construct(NotifyMe $notifyMe)
    {
        $this->notifyMe = $notifyMe;
        $this->notificationType = $notifyMe->notification_type;

        if ($this->notificationType === 'product') {
            $this->product = Product::find($notifyMe->product_id);
            $this->variant = null;
        } else {
            $this->variant = ProductVariant::find($notifyMe->variant_id);
            $this->product = $this->variant ? $this->variant->product : null;
        }
    }

    public function build()
    {
        $subject = $this->product
            ? "{$this->product->name} is back in stock!"
            : "Item is back in stock!";

        $view = $this->notificationType === 'product'
            ? 'emails.back_in_stock_product'
            : 'emails.back_in_stock_variant';

        return $this->subject($subject)
            ->view($view)
            ->with([
                'product' => $this->product,
                'variant' => $this->variant,
                'notificationType' => $this->notificationType
            ]);
    }
}
