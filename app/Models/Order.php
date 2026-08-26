<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'tax_breakdown' => 'array',
        'confirmed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'total_gst' => 'decimal:2',
        'shipping_charge' => 'decimal:2',
        'coin_redeemed' => 'decimal:2',
        'total_payable' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function lines()
    {
        return $this->hasMany(OrderLine::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function billingAddress()
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    public function deliveryAddress()
    {
        return $this->belongsTo(Address::class, 'delivery_address_id');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function returns()
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function coinRedemptions()
    {
        return $this->hasMany(CoinRedemption::class);
    }

    public function commissionEvents()
    {
        return $this->hasMany(CommissionApiEvent::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['delivered', 'confirmed']);
    }

    // Helpers
    public function isCancellable(): bool
    {
        return in_array($this->status, ['pending', 'confirmed', 'processing']);
    }

    public function isReturnable(): bool
    {
        $returnWindow = setting('return_window_days', 30);
        return $this->status === 'delivered'
            && $this->delivered_at
            && $this->delivered_at->diffInDays(now()) <= $returnWindow;
    }

    public function hasPendingReturn(): bool
    {
        return $this->returns()
            ->where('status', 'pending')
            ->exists();
    }

    public function hasApprovedReturn(): bool
    {
        return $this->returns()
            ->whereIn('status', ['approved', 'received', 'completed'])
            ->exists();
    }

    /**
     * Check if order is within cooling-off period (30 days from purchase date).
     * FR-CO-013
     */
    public function isWithinCoolingOff(): bool
    {
        $coolingOffDays = (int) setting('cooling_off_days', 30);
        return $this->created_at->diffInDays(now()) <= $coolingOffDays;
    }

    /**
     * Check if order is within buy-back window (30 days from purchase date).
     */

    public function isWithinBuybackWindow(): bool
    {
        $buybackWindow = (int) setting('buyback_window_days', 30);
        return $this->created_at->diffInDays(now()) <= $buybackWindow;
    }

    /**
     * Get remaining days for cooling-off.
     */
    public function getRemainingCoolingOffDays(): int
    {
        $coolingOffDays = (int) setting('cooling_off_days', 30);
        $daysPassed = $this->created_at->diffInDays(now());
        return max(0, $coolingOffDays - $daysPassed);
    }


    /**
     * Get remaining days for buy-back.
     */
    public function getRemainingBuybackDays(): int
    {
        $buybackWindow = (int) setting('buyback_window_days', 30);
        $daysPassed = $this->created_at->diffInDays(now());
        return max(0, $buybackWindow - $daysPassed);
    }
    public function updateOrderStatus(): void
    {
        $lines = $this->lines;
        $totalLines = $lines->count();

        if ($totalLines === 0) {
            $this->update(['status' => 'pending']);
            return;
        }

        $deliveryCounts = [
            'pending' => 0,
            'confirmed' => 0,
            'shipped' => 0,
            'delivered' => 0,
            'cancelled' => 0,
            'returned' => 0,
        ];

        foreach ($lines as $line) {
            $status = $line->delivery_status ?? 'pending';
            if (isset($deliveryCounts[$status])) {
                $deliveryCounts[$status]++;
            }
        }

        // Determine delivery status
        $deliveryStatus = $this->calculateDeliveryStatus($deliveryCounts, $totalLines);

        // Check return status for delivered items
        $returnStatus = $this->calculateReturnStatus($lines);

        // Combine delivery and return status
        $finalStatus = $this->combineStatuses($deliveryStatus, $returnStatus, $deliveryCounts, $totalLines);

        $this->update(['status' => $finalStatus]);
    }

    private function calculateDeliveryStatus(array $counts, int $total): string
    {
        if ($counts['delivered'] === $total) {
            return 'delivered';
        }

        if ($counts['delivered'] > 0) {
            return 'partial_delivered';
        }

        if ($counts['shipped'] === $total) {
            return 'shipped';
        }

        if ($counts['shipped'] > 0) {
            return 'partial_shipped';
        }

        if ($counts['confirmed'] === $total) {
            return 'confirmed';
        }

        if ($counts['confirmed'] > 0) {
            return 'partial_confirmed';
        }

        if ($counts['returned'] > 0) {
            // All items returned
            if ($counts['returned'] === $total) {
                return 'returned';
            }
            return 'partial_returned';
        }

        return 'pending';
    }

    private function calculateReturnStatus($lines): array
    {
        $deliveredLines = $lines->where('delivery_status', 'delivered');
        $deliveredCount = $deliveredLines->count();

        if ($deliveredCount === 0) {
            return ['has_returns' => false, 'all_returned' => false];
        }

        $returnedCount = $deliveredLines->where('return_status', 'returned')->count();
        $pendingReturns = $deliveredLines->whereIn('return_status', ['pending', 'approved'])->count();

        return [
            'has_returns' => $returnedCount > 0 || $pendingReturns > 0,
            'all_returned' => $returnedCount === $deliveredCount && $deliveredCount > 0
        ];
    }

    private function combineStatuses(string $deliveryStatus, array $returnStatus, array $counts, int $total): string
    {
        // If all delivered items are returned and all items are delivered
        if ($returnStatus['all_returned'] && $counts['delivered'] === $total) {
            return 'returned';
        }

        // If all delivered items are returned but some items not delivered
        if ($returnStatus['all_returned'] && $counts['delivered'] > 0) {
            return 'partial_return';
        }

        // If some delivered items are returned
        if ($returnStatus['has_returns'] && $counts['delivered'] > 0) {
            if ($deliveryStatus === 'delivered') {
                return 'partial_returned';
            }
            return $deliveryStatus;
        }

        return $deliveryStatus;
    }
}
