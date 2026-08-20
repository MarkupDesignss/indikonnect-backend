<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\GenealogyPlacement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateGenealogy extends Command
{
    protected $signature = 'genealogy:populate';
    protected $description = 'Populate genealogy_placements table from existing users';

    public function handle()
    {
        $this->info('Starting genealogy population...');

        $users = User::where('account_type', 'distributor')
            ->whereNotNull('sponsor_id')
            ->get();

        $count = 0;
        $bar = $this->output->createProgressBar($users->count());

        foreach ($users as $user) {
            // Check if already exists
            $exists = GenealogyPlacement::where('user_id', $user->id)->exists();

            if (!$exists) {
                // Calculate level (you may need to compute this)
                $level = $this->calculateLevel($user->sponsor_id);

                GenealogyPlacement::create([
                    'user_id' => $user->id,
                    'sponsor_id' => $user->sponsor_id,
                    'level' => $level,
                    'position' => $user->placement_leg ?? 'left', // default to left
                    'status' => 'active',
                ]);
                $count++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Population complete! {$count} new records created.");
    }

    private function calculateLevel($sponsorId): int
    {
        $level = 0;
        $current = $sponsorId;

        while ($current) {
            $sponsor = User::find($current);
            if (!$sponsor || !$sponsor->sponsor_id) {
                break;
            }
            $current = $sponsor->sponsor_id;
            $level++;
        }

        return $level;
    }
}