<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;

return new class extends Migration
{
    public function up(): void
    {
        try {
            $ignite = app(IgniteClient::class);
            $res    = $ignite->catalog();

            if (($res['code'] ?? null) !== ResultCode::OK->value) {
                logger()->warning('Backfill skipped: catalog() refused', ['msg' => $res['msg'] ?? null]);
                return;
            }

            $items = $res['data']['items'] ?? [];
            $count = 0;

            DB::transaction(function () use ($items, &$count) {
                foreach ($items as $item) {
                    if (empty($item['partner_product_ref']) || empty($item['product_id'])) {
                        continue;
                    }

                    $updated = DB::table('products')
                        ->where('ignite_product_id', $item['product_id'])
                        ->whereNull('partner_product_ref')
                        ->update(['partner_product_ref' => $item['partner_product_ref']]);

                    $count += $updated;
                }
            });

            logger()->info("Backfill done: {$count} products updated.");
        } catch (\Throwable $e) {
            logger()->error('Backfill failed', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        //
    }
};