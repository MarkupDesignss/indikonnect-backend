<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FinanceCommissionExportController extends Controller
{
    protected IgniteClient $ignite;

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    /**
     * GET /api/finance/commission-export?week=42&year=2026
     * Saare distributors ka commission data JSON mein.
     */
    public function download(Request $request)
    {
        $request->validate([
            'week' => 'required|integer|min:1|max:53',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $week = (int) $request->week;
        $year = (int) $request->year;

        $distributors = User::query()
            ->where('account_type', 'distributor')
            ->whereNotNull('distributor_id')
            ->where('distributor_id', '!=', '')
            ->select('id', 'full_name', 'email', 'distributor_id')
            ->orderBy('id')
            ->get();

        $rows = [];
        $failed = [];

        foreach ($distributors as $dist) {
            try {
                $history = $this->fetchHistoryForWeek($dist->distributor_id, $week, $year);

                foreach ($history as $tx) {
                    $rows[] = [
                        'distributor_id'   => $dist->distributor_id,
                        'distributor_name' => $dist->full_name,
                        'email'            => $dist->email,
                        'week'             => $week,
                        'year'             => $year,
                        'tx_id'            => $tx['id'],
                        'date'             => $tx['date_created'],
                        'category'         => $tx['category'],
                        'point_type'       => $tx['point_type'],
                        'description'      => $tx['description'],
                        'amount_usd'       => $tx['amount_usd'],
                        'amount_inr'       => $tx['amount_inr'],
                        'amount_display'   => $tx['amount'],
                        'related_order'    => $tx['related_orderid'],
                        'related_member'   => $tx['related_member'],
                    ];
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'distributor_id' => $dist->distributor_id,
                    'error'          => $e->getMessage(),
                ];
                Log::warning('Commission export failed', [
                    'distributor_id' => $dist->distributor_id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')->value('value') ?: 0;

        return response()->json([
            'success'  => true,
            'message'  => empty($rows)
                ? 'Is week/year ke liye koi commission data nahi mila.'
                : 'Commission data fetched successfully.',
            'currency' => [
                'base'          => 'USD',
                'display'       => 'INR',
                'exchange_rate' => $usdRate,
            ],
            'filters' => [
                'week' => $week,
                'year' => $year,
            ],
            'summary' => [
                'total_distributors' => $distributors->count(),
                'total_rows'         => count($rows),
                'failed_count'       => count($failed),
            ],
            'data'   => $rows,
            'failed' => $failed,
        ]);
    }

    private function fetchHistoryForWeek(string $baId, int $week, int $year): array
    {
        $afterId = 0;
        $limit = 500;
        $guard = 0;
        $collected = [];

        do {
            $resp = $this->ignite->pointTxs($baId, $afterId, $limit);

            if (($resp['code'] ?? null) !== ResultCode::OK->value) {
                break;
            }

            $rawRows = $resp['data']['rows'] ?? [];
            if (empty($rawRows)) {
                break;
            }

            foreach ($rawRows as $row) {
                $created = $row['created_at'] ?? null;
                if ($created) {
                    $dt = \Carbon\Carbon::parse($created);
                    if ((int) $dt->weekOfYear !== $week || (int) $dt->year !== $year) {
                        continue;
                    }
                }
                $collected[] = $this->normalizeRow($row);
            }

            $afterId = (int) end($rawRows)['id'];
            $guard++;
        } while (($resp['data']['has_more'] ?? false) && $guard < 100);

        return $collected;
    }

    private function normalizeRow(array $row): array
    {
        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')->value('value') ?: 0;

        $category = match ($row['tx_type']) {
            'comm'  => 'Commission',
            'cr'    => 'RedeemCoin',
            'wd'    => 'Withdrawal',
            'adj'   => 'Adjustment',
            'pv'    => 'CV',
            default => ucfirst($row['tx_type'] ?? 'Other'),
        };

        $amount    = (float) ($row['amount'] ?? 0);
        $pointType = $row['point_type'] ?? '';
        $isCash    = $pointType === 'cash_bal';
        $isCoin    = in_array($pointType, ['green_bal', 'yellow_bal', 'blue_bal'], true);
        $isCv      = in_array($pointType, ['pv1_bal', 'pv2_bal'], true);

        $amountInr = $isCash ? round($amount * $usdRate, 2) : null;

        $sign = $amount < 0 ? '-' : ($amount > 0 ? '+' : '');

        if ($isCash) {
            $display = $sign . '₹' . number_format(abs($amount * $usdRate), 2);
        } elseif ($isCoin) {
            $unit = match ($pointType) {
                'green_bal'  => 'GC',
                'yellow_bal' => 'YC',
                'blue_bal'   => 'BC',
                default      => '',
            };
            $display = $sign . number_format(abs($amount), 2) . ' ' . $unit;
        } elseif ($isCv) {
            $display = $sign . number_format(abs($amount), 2) . ' CV';
        } else {
            $display = $sign . number_format(abs($amount), 2);
        }

        $dateCreated = $row['created_at']
            ? \Carbon\Carbon::parse($row['created_at'])->format('d/m/Y')
            : '-';

        return [
            'id'             => $row['id'],
            'category'       => $category,
            'point_type'     => $pointType,
            'date_created'   => $dateCreated,
            'description'    => $row['description'] ?? '-',
            'amount_usd'     => $amount,
            'amount_inr'     => $amountInr,
            'amount'         => $display,
            'related_orderid'=> $row['order_no'] ?: 'N/A',
            'related_member' => $row['rel_ba_id'] ?? $row['rel_member_id'] ?? 'N/A',
        ];
    }
}