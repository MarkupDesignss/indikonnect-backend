<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Class CreditNote
 *
 * @property int $id
 * @property string $credit_note_number
 * @property int $order_id
 * @property string $original_invoice_number
 * @property int|null $refund_id
 * @property string $buyer_name
 * @property string|null $buyer_email
 * @property string|null $buyer_address
 * @property string|null $buyer_state
 * @property string|null $buyer_gstin
 * @property float $amount
 * @property float $taxable_value
 * @property float $cgst_amount
 * @property float $sgst_amount
 * @property float $igst_amount
 * @property float $total_gst
 * @property array $items
 * @property string $buyer_type  // 'customer' or 'distributor'
 * @property string|null $reason
 * @property \Carbon\Carbon $issued_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * @property-read Order $order
 * @property-read Refund|null $refund
 */
class CreditNote extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'credit_note_number',
        'order_id',
        'original_invoice_number',
        'refund_id',
        'buyer_name',
        'buyer_email',
        'buyer_address',
        'buyer_state',
        'buyer_gstin',
        'amount',
        'taxable_value',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'total_gst',
        'items',
        'buyer_type',
        'reason',
        'issued_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount'        => 'decimal:2',
        'taxable_value' => 'decimal:2',
        'cgst_amount'   => 'decimal:2',
        'sgst_amount'   => 'decimal:2',
        'igst_amount'   => 'decimal:2',
        'total_gst'     => 'decimal:2',
        'items'         => 'array',
        'issued_at'     => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'issued_at',
    ];

    /**
     * The "booting" method of the model.
     * Auto-generate the credit note number on creation.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $creditNote) {
            if (empty($creditNote->credit_note_number)) {
                $creditNote->credit_note_number = self::generateCreditNoteNumber();
            }
            if (empty($creditNote->issued_at)) {
                $creditNote->issued_at = now();
            }
        });
    }

    /**
     * Generate a sequential, gapless credit note number.
     * Format: CN-YYYYMMDD-XXXX (e.g., CN-20260902-0001)
     *
     * @return string
     */
    public static function generateCreditNoteNumber(): string
    {
        $prefix = 'CN-' . now()->format('Ymd') . '-';
        
        // Atomic database operation to get the next sequence number for today
        $lastNumber = DB::table('credit_notes')
            ->where('credit_note_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('credit_note_number');

        if ($lastNumber) {
            $sequence = (int) Str::substr($lastNumber, -4) + 1;
        } else {
            $sequence = 1;
        }

        return $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the order that this credit note belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the refund that this credit note belongs to.
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * Get the buyer's full display name.
     */
    public function getBuyerDisplayNameAttribute(): string
    {
        return $this->buyer_name . ($this->buyer_email ? " ({$this->buyer_email})" : '');
    }

    /**
     * Check if this credit note is for a distributor.
     */
    public function isForDistributor(): bool
    {
        return $this->buyer_type === 'distributor';
    }

    /**
     * Check if this credit note is for a customer.
     */
    public function isForCustomer(): bool
    {
        return $this->buyer_type === 'customer';
    }

    /**
     * Recalculate totals from the items JSON.
     * Useful when generating or updating the credit note.
     *
     * @return array
     */
    public function recalculateTotals(): array
    {
        $items = $this->items ?? [];
        $taxable = 0;
        $cgst = 0;
        $sgst = 0;
        $igst = 0;
        $total = 0;

        foreach ($items as $item) {
            $taxable += $item['taxable_value'] ?? 0;
            $cgst += $item['cgst'] ?? 0;
            $sgst += $item['sgst'] ?? 0;
            $igst += $item['igst'] ?? 0;
            $total += $item['line_total'] ?? 0;
        }

        return [
            'taxable_value' => round($taxable, 2),
            'cgst_amount'   => round($cgst, 2),
            'sgst_amount'   => round($sgst, 2),
            'igst_amount'   => round($igst, 2),
            'total_gst'     => round($cgst + $sgst + $igst, 2),
            'amount'        => round($total, 2),
        ];
    }

    /**
     * Update the credit note with recalculated totals.
     */
    public function updateTotals(): bool
    {
        $totals = $this->recalculateTotals();
        $this->fill($totals);
        return $this->save();
    }

    /**
     * Scope a query to only include credit notes issued within a date range.
     */
    public function scopeIssuedBetween($query, $from, $to)
    {
        return $query->whereBetween('issued_at', [$from, $to]);
    }

    /**
     * Scope a query to only include credit notes for a given buyer type.
     */
    public function scopeForBuyerType($query, string $type)
    {
        return $query->where('buyer_type', $type);
    }

    /**
     * Scope a query to only include credit notes referencing a specific original invoice.
     */
    public function scopeByOriginalInvoice($query, string $invoiceNumber)
    {
        return $query->where('original_invoice_number', $invoiceNumber);
    }
}