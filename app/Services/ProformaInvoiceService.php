<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProformaInvoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ProformaInvoiceService
{
    /**
     * Generate proforma invoice for a distributor order
     */
    public function generateForOrder(Order $order): ?ProformaInvoice
    {
        $user = User::find($order->user_id);

        if (!$user || $user->account_type !== 'distributor') {
            return null;
        }

        $existing = ProformaInvoice::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $user) {
            $invoiceData = $this->buildInvoiceData($order, $user);
            $invoice     = ProformaInvoice::create($invoiceData);

            $pdf = $this->generatePdf($invoice);

            $invoice->update(['pdf_path' => $pdf['path']]);
            $this->sendProformaInvoiceEmail($invoice->fresh(), $user, $pdf['content']);

            return $invoice;
        });
    }

    /**
     * Build invoice data array
     */
    private function buildInvoiceData(Order $order, User $user): array
    {
        $lineItems = $this->buildLineItems($order);

        $subtotalBeforeRedemption = (float) ($order->subtotal ?? 0);
        $coinRedeemed             = (float) ($order->coin_redeemed_amount ?? 0);
        $couponDiscount           = (float) ($order->coupon_discount ?? 0);
        $shippingCharge           = (float) ($order->shipping_charge ?? 0);
        $totalTax                 = (float) ($order->total_gst ?? 0);

        // Taxable = subtotal - coupon - coins
        $totalTaxable = $subtotalBeforeRedemption - $couponDiscount - $coinRedeemed;

        // Total payable
        $totalPayable = (float) ($order->total_payable ?? ($totalTaxable + $totalTax + $shippingCharge));

        $billingAddress  = $order->billingAddress;
        $deliveryAddress = $order->deliveryAddress;

        return [
            'proforma_invoice_number' => $this->generateInvoiceNumber(),

            'order_id' => $order->id,

            'seller_name'    => config('app.company_name', 'IndieKonnect Enterprises Pvt Ltd'),
            'seller_gstin'   => config('app.company_gstin', '03XXXXX1234X1Z5'),
            'seller_address' => config('app.company_address', "5 New Lajpat Nagar, Ludhiana\nPunjab - 141001"),

            'buyer_name'    => $user->name ?? $billingAddress?->name ?? 'N/A',
            'buyer_gstin'   => $user->gstin ?? $billingAddress?->gstin ?? null,
            'buyer_address' => $this->formatAddress($billingAddress, $user),

            'delivery_state' => $deliveryAddress?->state ?? $billingAddress?->state ?? null,

            'line_items' => $lineItems,

            'subtotal_before_redemption' => $subtotalBeforeRedemption,
            'coin_redeemed'              => $coinRedeemed,
            'total_taxable'              => $totalTaxable,

            // GST breakdown fields — keep 0 since we show single GST
            'total_cgst' => 0,
            'total_sgst' => 0,
            'total_igst' => 0,
            'total_tax'  => $totalTax,

            'coupon_code'     => $order->coupon_code,
            'coupon_discount' => $couponDiscount,

            'shipping_charge' => $shippingCharge,

            'subtotal_after_discount' => $totalTaxable,
            'total'                   => $totalPayable,
            'total_payable'           => $totalPayable,

            'summary_snapshot' => [
                'order_reference' => $order->order_reference,
                'order_date'      => $order->created_at->toDateTimeString(),
                'order_type'      => $order->order_type,
                'tax_breakdown'   => $order->tax_breakdown,
            ],

            'issued_at' => now(),
        ];
    }

    /**
     * Build line items
     */
    private function buildLineItems(Order $order): array
    {
        $items = [];

        foreach ($order->lines as $line) {
            $product = $line->product;
            $variant = $line->variant;

            $items[] = [
                'product_id'      => $line->product_id,
                'variant_id'      => $line->variant_id,
                'product_code'    => $product?->product_code,
                'hsn_code'        => $product?->hsn_code,
                'uom'             => $product?->uom,
                'name'            => $product?->name . ($variant ? ' - ' . $variant->name : ''),
                'description'     => $product?->description,
                'quantity'        => $line->quantity,
                'unit_price'      => (float) $line->unit_price,
                'line_total'      => (float) $line->line_total,
                'shipping_charge' => (float) ($line->shipping_charge ?? 0),
                'gst_rate'        => (float) $line->gst_rate,
                'gst_amount'      => (float) $line->gst_amount,
                'tax_data'        => $line->tax_data,
            ];
        }

        return $items;
    }

    /**
     * Format address
     */
    private function formatAddress(?object $address, User $user): ?string
    {
        if (!$address) {
            return $user->name . "\n" . ($user->email ?? '');
        }

        $parts = array_filter([
            $address->name ?? $user->name ?? null,
            $address->address_line_1 ?? null,
            $address->address_line_2 ?? null,
            $address->city ?? null,
            $address->state ?? null,
            'Pincode: ' . ($address->pincode ?? ''),
            'Phone: ' . ($address->phone ?? $user->phone ?? ''),
        ]);

        return implode("\n", $parts);
    }

    /**
     * Generate unique invoice number
     */
    private function generateInvoiceNumber(): string
    {
        $year = now()->format('Y');
        $next = now()->addYear()->format('y');

        $last = ProformaInvoice::whereYear('created_at', now()->year)->count() + 1;

        return sprintf('PI/%s-%s/%04d', $year, $next, $last);
    }

    /**
     * Convert number to words (Indian format) — PUBLIC so blade/model can use it
     */
    public function numberToWords(float $number): string
    {
        $number = round($number, 2);
        $rupees = (int) floor($number);
        $paise  = (int) round(($number - $rupees) * 100);

        $words = $this->convertToWords($rupees) . ' Rupees';

        if ($paise > 0) {
            $words .= ' and ' . $this->convertToWords($paise) . ' Paise';
        }

        return $words . ' Only';
    }

    /**
     * Convert integer to Indian words
     */
    private function convertToWords(int $number): string
    {
        $ones = [
            0 => '',
            1 => 'One',
            2 => 'Two',
            3 => 'Three',
            4 => 'Four',
            5 => 'Five',
            6 => 'Six',
            7 => 'Seven',
            8 => 'Eight',
            9 => 'Nine',
            10 => 'Ten',
            11 => 'Eleven',
            12 => 'Twelve',
            13 => 'Thirteen',
            14 => 'Fourteen',
            15 => 'Fifteen',
            16 => 'Sixteen',
            17 => 'Seventeen',
            18 => 'Eighteen',
            19 => 'Nineteen',
        ];
        $tens = [
            2 => 'Twenty',
            3 => 'Thirty',
            4 => 'Forty',
            5 => 'Fifty',
            6 => 'Sixty',
            7 => 'Seventy',
            8 => 'Eighty',
            9 => 'Ninety',
        ];

        if ($number == 0) return 'Zero';

        $result = '';

        if ($number >= 10000000) {
            $result .= $this->convertToWords((int) ($number / 10000000)) . ' Crore ';
            $number %= 10000000;
        }
        if ($number >= 100000) {
            $result .= $this->convertToWords((int) ($number / 100000)) . ' Lakh ';
            $number %= 100000;
        }
        if ($number >= 1000) {
            $result .= $this->convertToWords((int) ($number / 1000)) . ' Thousand ';
            $number %= 1000;
        }
        if ($number >= 100) {
            $result .= $ones[(int) ($number / 100)] . ' Hundred ';
            $number %= 100;
        }
        if ($number > 0) {
            if ($result !== '') $result .= 'and ';
            if ($number < 20) {
                $result .= $ones[$number];
            } else {
                $result .= $tens[(int) ($number / 10)];
                if ($number % 10 > 0) {
                    $result .= ' ' . $ones[$number % 10];
                }
            }
        }

        return trim($result);
    }

    /**
     * Generate PDF — saves into proper year-wise folder structure
     */
    public function generatePdf(ProformaInvoice $invoice): array
    {
        // Eager load relationships so blade never hits null
        $invoice->loadMissing('order.user.addresses');

        $amountInWords = $this->numberToWords((float) $invoice->total_payable);

        // ---- Render blade to HTML first (so we can debug & validate) ----
        try {
            $html = view('invoices.proforma', [
                'invoice'       => $invoice,
                'amountInWords' => $amountInWords,
            ])->render();
        } catch (\Throwable $e) {
            Log::error('Proforma blade render failed', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);
            throw $e;
        }

        // Optional debug — check HTML actually contains amount words
        if (!str_contains($html, 'Amount in Words')) {
            Log::warning('Amount in Words block missing from rendered HTML', [
                'invoice_id' => $invoice->id,
                'html_tail'  => substr($html, -500),
            ]);
        }

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans');

        $pdfContent = $pdf->output();

        if (substr($pdfContent, 0, 5) !== '%PDF-') {
            throw new \Exception('Invalid PDF generated (missing %PDF- header)');
        }

        $safeNumber   = str_replace(['/', '\\'], '-', $invoice->proforma_invoice_number);
        $year         = now()->format('Y');
        $relativePath = "proforma_invoices/{$year}/{$safeNumber}.pdf";
        $fullPath     = Storage::disk('public')->path($relativePath);

        if (!file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        file_put_contents($fullPath, $pdfContent);

        return [
            'path'    => $relativePath,
            'content' => $pdfContent,
        ];
    }

    /**
     * Send proforma invoice email
     */
    private function sendProformaInvoiceEmail(
        ProformaInvoice $invoice,
        User $user,
        string $pdfContent
    ): void {
        try {
            if (substr($pdfContent, 0, 5) !== '%PDF-') {
                Log::error('Proforma PDF content invalid, email not sent', [
                    'invoice_id' => $invoice->id,
                    'header'     => substr($pdfContent, 0, 5),
                ]);
                return;
            }

            Mail::to($user->email)->send(
                new \App\Mail\ProformaInvoiceMail($invoice, $pdfContent)
            );
            Log::info('Proforma invoice email sent', [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->proforma_invoice_number,
                'user_email'     => $user->email,
                'pdf_size'       => strlen($pdfContent),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send proforma invoice email', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);
        }
    }
}
