<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CommissionLedger;
use App\Models\User;
use Carbon\Carbon;

class CommissionLedgerSeeder extends Seeder
{
    public function run(): void
    {
        $distributors = User::where('account_type', 'distributor')->get();

        if ($distributors->isEmpty()) {
            $this->command->warn('No distributors found. Please create some users with account_type="distributor" first.');
            return;
        }

        foreach ($distributors as $distributor) {
            // Generate data for last 6 months
            for ($i = 0; $i < 6; $i++) {
                $period = Carbon::now()->subMonths($i)->format('Y-m');
                $entriesCount = rand(3, 7);

                for ($j = 0; $j < $entriesCount; $j++) {
                    $gross = rand(500, 15000);
                    $tdsRate = setting('tds_rate_percent', 2) / 100;
                    $tds = round($gross * $tdsRate, 2);
                    $net = $gross - $tds;

                    $statuses = ['pending', 'released', 'released', 'released'];
                    $types = ['commission', 'commission', 'bonus', 'coin_award'];

                    CommissionLedger::create([
                        'distributor_id' => $distributor->id,
                        'period' => $period,
                        'entry_type' => $types[array_rand($types)],
                        'gross_amount' => $gross,
                        'tds_amount' => $tds,
                        'net_amount' => $net,
                        'order_reference' => 'ORD-' . strtoupper(uniqid()),
                        'status' => $statuses[array_rand($statuses)],
                        'created_at' => Carbon::now()->subDays(rand(1, 30)),
                    ]);
                }

                // Add a reversal entry every alternate month
                if ($i % 2 == 0 && $i > 0) {
                    $revAmount = rand(500, 2000);
                    CommissionLedger::create([
                        'distributor_id' => $distributor->id,
                        'period' => $period,
                        'entry_type' => 'reversal',
                        'gross_amount' => -$revAmount,
                        'tds_amount' => 0,
                        'net_amount' => -$revAmount,
                        'order_reference' => 'ORD-REV-' . strtoupper(uniqid()),
                        'status' => 'reversed',
                        'created_at' => Carbon::now()->subDays(rand(1, 15)),
                    ]);
                }
            }
        }

        $this->command->info('Commission ledger seeded successfully for all distributors.');
    }
}