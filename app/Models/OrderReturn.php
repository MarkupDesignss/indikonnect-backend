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

        // ADDED: Return type (return, cooling_off, buyback)
        'type',

        // ADDED: Extra metadata (buy-back declarations, etc.)
        'extra_data',

        // Status timestamps
        'approved_at',
        'received_at',
        'completed_at',

        // timestamps
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

        // ADDED: Cast extra_data to array
        'extra_data' => 'array',

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
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class, 'return_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getReturnItemsWithDetailsAttribute(): array
    {
        $items = $this->items ?? [];

        if (!is_array($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if (!isset($item['order_line_id']) || !isset($item['quantity'])) {
                continue;
            }

            $orderLine = OrderLine::with(['product.primaryImage'])->find($item['order_line_id']);

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
                'unit_price' => (float) ($item['unit_price'] ?? $orderLine->unit_price),
                'subtotal' => (float) ($item['subtotal'] ?? ((float) $orderLine->unit_price * (int) $item['quantity'])),
                'tax' => (float) ($item['tax'] ?? 0),
                'reason' => $item['reason'] ?? null,
                'image_paths' => $item['image_paths'] ?? [],
                'image_urls' => $this->getImageUrls($item['image_paths'] ?? []),
            ];
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | TYPE HELPERS (Direct Strings)
    |--------------------------------------------------------------------------
    */

    public function isCoolingOff(): bool
    {
        return $this->type === 'cooling_off';
    }

    public function isBuyback(): bool
    {
        return $this->type === 'buyback';
    }

    public function isReturn(): bool
    {
        return $this->type === 'return';
    }

    /*
    |--------------------------------------------------------------------------
    | STATUS HELPERS (Direct Strings)
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isReceived(): bool
    {
        return $this->status === 'received';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isApprovedOrCompleted(): bool
    {
        return in_array($this->status, ['approved', 'received', 'completed']);
    }

    public function hasBeenProcessed(): bool
    {
        return $this->status !== 'pending';
    }

    /*
    |--------------------------------------------------------------------------
    | TRANSITION CHECKS (Direct Strings)
    |--------------------------------------------------------------------------
    */

    public function canApprove(): bool
    {
        return $this->status === 'pending';
    }

    public function canReject(): bool
    {
        return $this->status === 'pending';
    }

    public function canMarkReceived(): bool
    {
        return $this->status === 'approved';
    }

    public function canComplete(): bool
    {
        return $this->status === 'received';
    }

    /*
    |--------------------------------------------------------------------------
    | REFUND STATUS HELPERS (Direct Strings)
    |--------------------------------------------------------------------------
    */

    public function isRefundProcessing(): bool
    {
        return $this->refund_status === 'processing';
    }

    public function isRefundCompleted(): bool
    {
        return $this->refund_status === 'completed';
    }

    public function isRefundFailed(): bool
    {
        return $this->refund_status === 'failed';
    }

    /*
    |--------------------------------------------------------------------------
    | DECLARATION HELPERS (Buy-Back)
    |--------------------------------------------------------------------------
    */

    public function getDeclaration(string $key, $default = null)
    {
        return $this->extra_data[$key] ?? $default;
    }

    public function hasAllDeclarations(): bool
    {
        return $this->getDeclaration('declares_marketable') === true
            && $this->getDeclaration('declares_unsold') === true
            && $this->getDeclaration('declares_unused') === true;
    }

    /*
    |--------------------------------------------------------------------------
    | ITEM HELPERS
    |--------------------------------------------------------------------------
    */

    public function getItemsCount(): int
    {
        return count($this->items ?? []);
    }

    public function getTotalQuantity(): int
    {
        $total = 0;
        foreach ($this->items ?? [] as $item) {
            $total += (int) ($item['quantity'] ?? 0);
        }
        return $total;
    }

    public function getLineIds(): array
    {
        $ids = [];
        foreach ($this->items ?? [] as $item) {
            if (isset($item['order_line_id'])) {
                $ids[] = $item['order_line_id'];
            }
        }
        return $ids;
    }

    /*
    |--------------------------------------------------------------------------
    | IMAGE HELPERS
    |--------------------------------------------------------------------------
    */

    public function getAllImages(): array
    {
        $images = [];

        if (!empty($this->general_images) && is_array($this->general_images)) {
            $images = array_merge($images, $this->general_images);
        }

        if (!empty($this->items) && is_array($this->items)) {
            foreach ($this->items as $item) {
                if (!empty($item['image_paths']) && is_array($item['image_paths'])) {
                    $images = array_merge($images, $item['image_paths']);
                }
            }
        }

        return array_values($images);
    }

    public function getAllImageUrls(): array
    {
        return $this->getImageUrls($this->getAllImages());
    }

    private function getImageUrls(array $images): array
    {
        $urls = [];

        foreach ($images as $image) {
            if (empty($image)) {
                continue;
            }

            if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                $urls[] = $image;
                continue;
            }

            $urls[] = asset('storage/' . ltrim($image, '/'));
        }

        return $urls;
    }

    /*
    |--------------------------------------------------------------------------
    | LABEL HELPERS (Direct Strings)
    |--------------------------------------------------------------------------
    */

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'received' => 'Received',
            'completed' => 'Completed',
            default => 'Unknown',
        };
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'return' => 'Return',
            'cooling_off' => 'Cooling-Off Withdrawal',
            'buyback' => 'Buy-Back',
            default => 'Unknown',
        };
    }

    public function getRefundStatusLabel(): string
    {
        return match ($this->refund_status) {
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => 'Not Initiated',
        };
    }
}