<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'commissionable_volume' => 'decimal:2',
        'returned_quantity' => 'integer',
        'dispatched_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    // Relationships
    public function review()
    {
        return $this->hasOne(ProductReview::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    // public function shippingDetails()
    // {
    //     return $this->hasMany(OrderShippingDetail::class);
    // }

    public function shippingDetails()
    {
        return $this->hasOne(
            OrderShippingDetail::class,
            'order_line_id',
            'id'
        );
    }

    // Status check methods
    public function isDelivered(): bool
    {
        return $this->delivery_status === 'delivered';
    }

    public function isShipped(): bool
    {
        return $this->delivery_status === 'shipped';
    }

    public function isDispatched(): bool
    {
        return $this->delivery_status === 'dispatched';
    }

    public function isConfirmed(): bool
    {
        return $this->delivery_status === 'confirmed';
    }

    public function isPending(): bool
    {
        return $this->delivery_status === 'pending';
    }

    public function isCancelled(): bool
    {
        return $this->delivery_status === 'cancelled';
    }

    public function isReturned(): bool
    {
        return $this->delivery_status === 'returned';
    }

    /**
     * Check if item can be dispatched (must be confirmed)
     */
    public function canBeDispatched(): bool
    {
        return $this->isConfirmed()
            && !$this->isDispatched()
            && !$this->isShipped()
            && !$this->isDelivered()
            && !$this->isCancelled()
            && !$this->isReturned();
    }

    /**
     * Check if item can be shipped (must be dispatched)
     */
    public function canBeShipped(): bool
    {
        return $this->isDispatched()
            && !$this->isShipped()
            && !$this->isDelivered()
            && !$this->isCancelled()
            && !$this->isReturned();
    }

    /**
     * Check if item can be delivered (must be shipped or dispatched)
     */
    public function canBeDelivered(): bool
    {
        return ($this->isShipped() || $this->isDispatched())
            && !$this->isDelivered()
            && !$this->isCancelled()
            && !$this->isReturned();
    }

    /**
     * Check if item is returnable
     */
    public function isReturnable(): bool
    {
        // Only delivered items can be returned
        if ($this->delivery_status !== 'delivered') {
            return false;
        }

        // Check if return window hasn't expired (30 days from delivery)
        $returnWindow = setting('return_window_days', 30);
        if ($this->delivered_at && now()->diffInDays($this->delivered_at) > $returnWindow) {
            return false;
        }

        // Check if item hasn't been returned already
        if ($this->return_status === 'returned') {
            return false;
        }

        // Check if there's no pending or approved return
        if (in_array($this->return_status, ['pending', 'approved'])) {
            return false;
        }

        // Check if there's any quantity available for return
        if ($this->getAvailableForReturnAttribute() <= 0) {
            return false;
        }

        // Check product returnability flag
        return (bool) $this->is_returnable;
    }

    /**
     * Get available quantity for return
     */
    public function getAvailableForReturnAttribute(): int
    {
        $purchased = (int) $this->quantity;
        $alreadyReturned = (int) ($this->returned_quantity ?? 0);

        // Only delivered items can be returned
        if ($this->delivery_status !== 'delivered') {
            return 0;
        }

        // If already fully returned
        if ($this->return_status === 'returned') {
            return 0;
        }

        return max(0, $purchased - $alreadyReturned);
    }

    /**
     * Get the next allowed status for this item
     */
    public function getNextAllowedStatus(): ?string
    {
        $statusFlow = [
            'pending' => 'confirmed',
            'confirmed' => 'dispatched',
            'dispatched' => 'shipped',
            'shipped' => 'delivered',
        ];

        return $statusFlow[$this->delivery_status] ?? null;
    }

    /**
     * Check if status transition is valid
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $validTransitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['dispatched', 'cancelled'],
            'dispatched' => ['shipped', 'cancelled'],
            'shipped' => ['delivered', 'cancelled'],
            'delivered' => ['returned'],
            'returned' => [],
            'cancelled' => [],
        ];

        return isset($validTransitions[$this->delivery_status])
            && in_array($newStatus, $validTransitions[$this->delivery_status]);
    }

    /**
     * Transition to a new status with validation
     */
    public function transitionTo(string $newStatus, array $data = []): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            throw new \Exception(
                "Cannot transition from '{$this->delivery_status}' to '{$newStatus}'"
            );
        }

        $updates = ['delivery_status' => $newStatus];

        // Set timestamps based on status
        switch ($newStatus) {
            case 'confirmed':
                $updates['confirmed_at'] = now();
                break;
            case 'dispatched':
                $updates['dispatched_at'] = now();
                break;
            case 'shipped':
                $updates['shipped_at'] = now();
                break;
            case 'delivered':
                $updates['delivered_at'] = now();
                break;
        }

        // Merge additional data
        $updates = array_merge($updates, $data);

        return $this->update($updates);
    }

    /**
     * Get status history for this order line
     */
    public function getStatusHistory(): array
    {
        $history = [];
        $statuses = ['confirmed_at', 'dispatched_at', 'shipped_at', 'delivered_at'];
        $statusMap = [
            'confirmed_at' => 'confirmed',
            'dispatched_at' => 'dispatched',
            'shipped_at' => 'shipped',
            'delivered_at' => 'delivered',
        ];

        foreach ($statuses as $field) {
            if ($this->$field) {
                $history[] = [
                    'status' => $statusMap[$field],
                    'timestamp' => $this->$field->toDateTimeString(),
                ];
            }
        }

        return $history;
    }

    /**
     * Get delivery timeline in human readable format
     */
    public function getDeliveryTimeline(): array
    {
        $timeline = [];

        if ($this->confirmed_at) {
            $timeline['confirmed'] = $this->confirmed_at->toDateTimeString();
        }

        if ($this->dispatched_at) {
            $timeline['dispatched'] = $this->dispatched_at->toDateTimeString();
        }

        if ($this->shipped_at) {
            $timeline['shipped'] = $this->shipped_at->toDateTimeString();
        }

        if ($this->delivered_at) {
            $timeline['delivered'] = $this->delivered_at->toDateTimeString();
        }

        return $timeline;
    }

    /**
     * Check if item is fully processed (delivered or returned or cancelled)
     */
    public function isFullyProcessed(): bool
    {
        return in_array($this->delivery_status, ['delivered', 'returned', 'cancelled']);
    }

    /**
     * Get status label for display
     */
    public function getStatusLabel(): string
    {
        $labels = [
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'dispatched' => 'Dispatched',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
            'returned' => 'Returned',
        ];

        return $labels[$this->delivery_status] ?? ucfirst($this->delivery_status);
    }

    /**
     * Get status badge color
     */
    public function getStatusBadgeColor(): string
    {
        $colors = [
            'pending' => 'warning',
            'confirmed' => 'info',
            'dispatched' => 'primary',
            'shipped' => 'info',
            'delivered' => 'success',
            'cancelled' => 'danger',
            'returned' => 'secondary',
        ];

        return $colors[$this->delivery_status] ?? 'secondary';
    }
}
