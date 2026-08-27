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

    /**
     * Get only active (non-returned, non-cancelled) order lines
     */
    public function activeLines()
    {
        return $this->hasMany(OrderLine::class)
            ->whereNotIn('delivery_status', ['returned', 'cancelled']);
    }

    /**
     * Get returned order lines
     */
    public function returnedLines()
    {
        return $this->hasMany(OrderLine::class)
            ->where('delivery_status', 'returned');
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

    public function shippingDetails()
    {
        return $this->hasMany(OrderShippingDetail::class);
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

    public function scopeDispatched($query)
    {
        return $query->whereIn('status', ['dispatched', 'partial_dispatched']);
    }

    public function scopeShipped($query)
    {
        return $query->whereIn('status', ['shipped', 'partial_shipped']);
    }

    public function scopeDelivered($query)
    {
        return $query->whereIn('status', ['delivered', 'partial_delivered']);
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

    /**
     * Update order status based on all order lines
     * Excludes returned and cancelled items from active status calculation
     */
    public function updateOrderStatus(): void
    {
        $allLines = $this->lines;
        $totalLines = $allLines->count();

        if ($totalLines === 0) {
            $this->update(['status' => 'pending']);
            return;
        }

        // Get active lines (excluding returned and cancelled)
        $activeLines = $allLines->filter(function ($line) {
            return !in_array($line->delivery_status, ['returned', 'cancelled']);
        });

        $activeCount = $activeLines->count();
        $returnedCount = $allLines->where('delivery_status', 'returned')->count();
        $cancelledCount = $allLines->where('delivery_status', 'cancelled')->count();

        // If all items are returned or cancelled
        if ($activeCount === 0) {
            if ($returnedCount > 0) {
                $this->update(['status' => 'returned']);
            } elseif ($cancelledCount > 0) {
                $this->update(['status' => 'cancelled']);
            }
            return;
        }

        // Count delivery statuses for active lines only
        $deliveryCounts = [
            'pending' => 0,
            'confirmed' => 0,
            'dispatched' => 0,
            'shipped' => 0,
            'delivered' => 0,
        ];

        foreach ($activeLines as $line) {
            $status = $line->delivery_status ?? 'pending';
            if (isset($deliveryCounts[$status])) {
                $deliveryCounts[$status]++;
            }
        }

        // Determine status based on delivery counts of active lines
        $status = $this->determineOrderStatus($deliveryCounts, $activeCount);

        $this->update(['status' => $status]);
    }

    /**
     * Determine order status based on delivery counts of active lines
     */
    private function determineOrderStatus(array $counts, int $total): string
    {
        // All items delivered
        if ($counts['delivered'] === $total) {
            return 'delivered';
        }

        // Check for partial delivered
        if ($counts['delivered'] > 0 && $counts['delivered'] < $total) {
            return 'partial_delivered';
        }

        // All items shipped
        if ($counts['shipped'] === $total) {
            return 'shipped';
        }

        // Check for partial shipped
        if ($counts['shipped'] > 0 && $counts['shipped'] < $total) {
            return 'partial_shipped';
        }

        // All items dispatched
        if ($counts['dispatched'] === $total) {
            return 'dispatched';
        }

        // Check for partial dispatched
        if ($counts['dispatched'] > 0 && $counts['dispatched'] < $total) {
            return 'partial_dispatched';
        }

        // All items confirmed
        if ($counts['confirmed'] === $total) {
            return 'confirmed';
        }

        // Check for partial confirmed
        if ($counts['confirmed'] > 0 && $counts['confirmed'] < $total) {
            return 'partial_confirmed';
        }

        // Default to pending
        return 'pending';
    }

    /**
     * Update order delivery status (for backward compatibility)
     */
    public function updateOrderDeliveryStatus(): void
    {
        $allLines = $this->lines;
        $totalLines = $allLines->count();

        if ($totalLines === 0) {
            $this->update(['delivery_status' => 'pending']);
            return;
        }

        // Get active lines (excluding returned and cancelled)
        $activeLines = $allLines->filter(function ($line) {
            return !in_array($line->delivery_status, ['returned', 'cancelled']);
        });

        $activeCount = $activeLines->count();

        if ($activeCount === 0) {
            $this->update(['delivery_status' => 'returned']);
            return;
        }

        $deliveryCounts = [
            'pending' => 0,
            'confirmed' => 0,
            'dispatched' => 0,
            'shipped' => 0,
            'delivered' => 0,
        ];

        foreach ($activeLines as $line) {
            $status = $line->delivery_status ?? 'pending';
            if (isset($deliveryCounts[$status])) {
                $deliveryCounts[$status]++;
            }
        }

        $deliveryStatus = 'pending';

        if ($deliveryCounts['delivered'] === $activeCount) {
            $deliveryStatus = 'delivered';
        } elseif ($deliveryCounts['delivered'] > 0) {
            $deliveryStatus = 'partial_delivered';
        } elseif ($deliveryCounts['shipped'] === $activeCount) {
            $deliveryStatus = 'shipped';
        } elseif ($deliveryCounts['shipped'] > 0) {
            $deliveryStatus = 'partial_shipped';
        } elseif ($deliveryCounts['dispatched'] === $activeCount) {
            $deliveryStatus = 'dispatched';
        } elseif ($deliveryCounts['dispatched'] > 0) {
            $deliveryStatus = 'partial_dispatched';
        } elseif ($deliveryCounts['confirmed'] === $activeCount) {
            $deliveryStatus = 'confirmed';
        } elseif ($deliveryCounts['confirmed'] > 0) {
            $deliveryStatus = 'partial_confirmed';
        }

        $this->update(['delivery_status' => $deliveryStatus]);
    }

    /**
     * Update order return status
     */
    public function updateOrderReturnStatus(): void
    {
        $lines = $this->lines;
        $totalLines = $lines->count();

        // Count returned items
        $returnedCount = $lines->where('delivery_status', 'returned')->count();
        $pendingReturns = $lines->where('return_status', 'pending')->count();
        $approvedReturns = $lines->where('return_status', 'approved')->count();
        $rejectedReturns = $lines->where('return_status', 'rejected')->count();

        // Count delivered items (active + returned)
        $deliveredCount = $lines->where('delivery_status', 'delivered')->count();
        $returnedDeliveredCount = $lines->where('delivery_status', 'returned')->count();
        $totalDelivered = $deliveredCount + $returnedDeliveredCount;

        if ($totalLines === 0 || $totalDelivered === 0) {
            $this->update(['return_status' => 'none']);
            return;
        }

        $returnStatus = 'none';

        // If all delivered items are returned
        if ($returnedCount === $totalDelivered && $totalDelivered > 0) {
            $returnStatus = 'fully_returned';
        }
        // If some delivered items are returned
        elseif ($returnedCount > 0) {
            if ($pendingReturns > 0) {
                $returnStatus = 'partial_pending';
            } elseif ($approvedReturns > 0) {
                $returnStatus = 'partial_approved';
            } elseif ($rejectedReturns > 0) {
                $returnStatus = 'partial_rejected';
            } else {
                $returnStatus = 'partial_return';
            }
        }
        // If no items are returned but some are pending/approved/rejected
        elseif ($pendingReturns > 0) {
            $returnStatus = 'pending';
        } elseif ($approvedReturns > 0) {
            $returnStatus = 'approved';
        } elseif ($rejectedReturns > 0) {
            $returnStatus = 'rejected';
        }

        $this->update(['return_status' => $returnStatus]);
    }

    /**
     * Get active items count (excluding returned and cancelled)
     */
    public function getActiveItemsCount(): int
    {
        return $this->lines()
            ->whereNotIn('delivery_status', ['returned', 'cancelled'])
            ->count();
    }

    /**
     * Get returned items count
     */
    public function getReturnedItemsCount(): int
    {
        return $this->lines()
            ->where('delivery_status', 'returned')
            ->count();
    }

    /**
     * Check if all active items are in a specific status
     */
    public function allActiveItemsHaveStatus(string $status): bool
    {
        $activeCount = $this->getActiveItemsCount();
        if ($activeCount === 0) {
            return false;
        }

        $matchingCount = $this->lines()
            ->whereNotIn('delivery_status', ['returned', 'cancelled'])
            ->where('delivery_status', $status)
            ->count();

        return $matchingCount === $activeCount;
    }

    /**
     * Get status summary of all order lines
     */
    public function getStatusSummary(): array
    {
        $allLines = $this->lines;
        $statuses = ['pending', 'confirmed', 'dispatched', 'shipped', 'delivered', 'cancelled', 'returned'];
        $summary = [];

        foreach ($statuses as $status) {
            $summary[$status] = $allLines->where('delivery_status', $status)->count();
        }

        $summary['active'] = $this->getActiveItemsCount();
        $summary['total'] = $allLines->count();

        return $summary;
    }
}
