<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReturn extends Model
{
    use SoftDeletes;

    protected $table = 'returns';

    protected $fillable = [
        'order_id',
        'user_id',
        'items',
        'status',
        'reason',
        'refund_subtotal',
        'refund_tax',
        'refund_shipping',
        'total_refund_amount',
        'refund_transaction_id',
        'refund_status',
        'total_cv_reversed',
        'admin_notes',
        'rejection_reason',
        'approved_at',
        'received_at',
        'completed_at',
        'general_images',
        'admin_id',
        'refund_processed_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'items' => 'array',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
        'refund_processed_at' => 'datetime',
        'refund_subtotal' => 'decimal:2',
        'refund_tax' => 'decimal:2',
        'refund_shipping' => 'decimal:2',
        'total_refund_amount' => 'decimal:2',
        'total_cv_reversed' => 'decimal:2',
    ];
    const REFUND_STATUS_PROCESSING = 'processing';
    const REFUND_STATUS_COMPLETED = 'completed';
    const REFUND_STATUS_FAILED = 'failed';

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_RECEIVED = 'received';
    const STATUS_COMPLETED = 'completed';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function refund()
    {
        return $this->hasOne(Refund::class);
    }

    // Get return items with product details
    public function getReturnItemsWithDetailsAttribute(): array
    {
        $items = [];
        foreach ($this->items as $item) {
            $orderLine = OrderLine::with('product')->find($item['order_line_id']);
            if ($orderLine) {
                $items[] = [
                    'order_line_id' => $orderLine->id,
                    'product' => [
                        'id' => $orderLine->product->id,
                        'name' => $orderLine->product->name,
                        'product_code' => $orderLine->product->product_code,
                        'image' => $orderLine->product->primaryImage?->image_url,
                    ],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'] ?? $orderLine->unit_price,
                    'subtotal' => $item['subtotal'] ?? ($orderLine->unit_price * $item['quantity']),
                    'reason' => $item['reason'] ?? null,
                ];
            }
        }
        return $items;
    }

    // Check if return can be completed
    public function canComplete(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    // Check if return can be approved
    public function canApprove(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    // Check if return can be rejected
    public function canReject(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    // Check if return can be marked as received
    public function canMarkReceived(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function getAllImages(): array
    {
        $images = [];

        // Add general images
        if (!empty($this->general_images) && is_array($this->general_images)) {
            $images = array_merge($images, $this->general_images);
        }

        // Add item-specific images
        if (!empty($this->items) && is_array($this->items)) {
            foreach ($this->items as $item) {
                if (!empty($item['image_paths']) && is_array($item['image_paths'])) {
                    $images = array_merge($images, $item['image_paths']);
                }
            }
        }

        return $images;
    }

    public function getAllImageUrls(): array
    {
        $urls = [];
        $images = $this->getAllImages();

        foreach ($images as $image) {
            $urls[] = asset('storage/' . $image);
        }

        return $urls;
    }
}
