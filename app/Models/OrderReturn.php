<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

        // Refund details
        'refund_subtotal',
        'refund_tax',
        'refund_shipping',
        'total_refund_amount',
        'refund_transaction_id',
        'refund_status',
        'refund_processed_at',

        // CV
        'total_cv_reversed',

        // Images
        'general_images',

        // Admin / processing
        'admin_id',
        'admin_notes',
        'rejection_reason',

        // Status timestamps
        'approved_at',
        'received_at',
        'completed_at',

        // Laravel timestamps
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        // JSON columns
        'items' => 'array',
        'general_images' => 'array',

        // Date/time columns
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
        'refund_processed_at' => 'datetime',

        // Decimal columns
        'refund_subtotal' => 'decimal:2',
        'refund_tax' => 'decimal:2',
        'refund_shipping' => 'decimal:2',
        'total_refund_amount' => 'decimal:2',
        'total_cv_reversed' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Return Status Constants
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_COMPLETED = 'completed';

    /*
    |--------------------------------------------------------------------------
    | Refund Status Constants
    |--------------------------------------------------------------------------
    */

    public const REFUND_STATUS_PROCESSING = 'processing';
    public const REFUND_STATUS_COMPLETED = 'completed';
    public const REFUND_STATUS_FAILED = 'failed';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Return belongs to an order.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Return belongs to a user/customer.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Return was processed by an admin.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Return has one refund.
     */
    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class, 'return_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get return items with product details.
     *
     * Usage:
     * $return->return_items_with_details
     */
    public function getReturnItemsWithDetailsAttribute(): array
    {
        $items = $this->items ?? [];

        if (!is_array($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {

            if (
                !isset($item['order_line_id']) ||
                !isset($item['quantity'])
            ) {
                continue;
            }

            $orderLine = OrderLine::with([
                'product.primaryImage'
            ])->find($item['order_line_id']);

            if (!$orderLine || !$orderLine->product) {
                continue;
            }

            $result[] = [
                'order_line_id' => $orderLine->id,

                'product' => [
                    'id' => $orderLine->product->id,
                    'name' => $orderLine->product->name,
                    'product_code' => $orderLine->product->product_code,
                    'image' => $orderLine->product->primaryImage?->image_url,
                ],

                'quantity' => (int) $item['quantity'],

                'unit_price' => (float) (
                    $item['unit_price']
                    ?? $orderLine->unit_price
                ),

                'subtotal' => (float) (
                    $item['subtotal']
                    ?? (
                        (float) $orderLine->unit_price
                        * (int) $item['quantity']
                    )
                ),

                'tax' => (float) ($item['tax'] ?? 0),

                'reason' => $item['reason'] ?? null,

                'image_paths' => $item['image_paths'] ?? [],

                'image_urls' => $this->getImageUrls(
                    $item['image_paths'] ?? []
                ),
            ];
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Return Status Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if return can be completed.
     */
    public function canComplete(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    /**
     * Check if return can be approved.
     */
    public function canApprove(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if return can be rejected.
     */
    public function canReject(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if return can be marked as received.
     */
    public function canMarkReceived(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /*
    |--------------------------------------------------------------------------
    | Image Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get all return image paths.
     */
    public function getAllImages(): array
    {
        $images = [];

        /*
         * General return images
         */
        if (
            !empty($this->general_images) &&
            is_array($this->general_images)
        ) {
            $images = array_merge(
                $images,
                $this->general_images
            );
        }

        /*
         * Item-specific images
         */
        if (
            !empty($this->items) &&
            is_array($this->items)
        ) {
            foreach ($this->items as $item) {

                if (
                    !empty($item['image_paths']) &&
                    is_array($item['image_paths'])
                ) {
                    $images = array_merge(
                        $images,
                        $item['image_paths']
                    );
                }
            }
        }

        return array_values($images);
    }

    /**
     * Get all return image URLs.
     */
    public function getAllImageUrls(): array
    {
        return $this->getImageUrls(
            $this->getAllImages()
        );
    }

    /**
     * Convert image paths to public URLs.
     */
    private function getImageUrls(array $images): array
    {
        $urls = [];

        foreach ($images as $image) {

            if (empty($image)) {
                continue;
            }

            /*
             * If already a full URL, don't prepend storage.
             */
            if (
                str_starts_with($image, 'http://') ||
                str_starts_with($image, 'https://')
            ) {
                $urls[] = $image;
                continue;
            }

            $urls[] = asset(
                'storage/' . ltrim($image, '/')
            );
        }

        return $urls;
    }

    /*
    |--------------------------------------------------------------------------
    | Refund Status Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if refund is processing.
     */
    public function isRefundProcessing(): bool
    {
        return $this->refund_status === self::REFUND_STATUS_PROCESSING;
    }

    /**
     * Check if refund is completed.
     */
    public function isRefundCompleted(): bool
    {
        return $this->refund_status === self::REFUND_STATUS_COMPLETED;
    }

    /**
     * Check if refund failed.
     */
    public function isRefundFailed(): bool
    {
        return $this->refund_status === self::REFUND_STATUS_FAILED;
    }

    /**
     * Check if return is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if return is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if return is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if return is received.
     */
    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    /**
     * Check if return is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
