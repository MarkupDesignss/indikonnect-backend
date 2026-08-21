<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\GenealogyPlacement;
use Illuminate\Console\Command;

class PopulateGenealogy extends Command
{
    protected $signature = 'genealogy:populate';
    protected $description = 'Populate genealogy_placements from users';

    public function handle()
    {
        $users = User::where('account_type', 'distributor')->whereNotNull('sponsor_id')->get();
        $bar = $this->output->createProgressBar($users->count());

        foreach ($users as $user) {
            GenealogyPlacement::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'sponsor_id' => $user->sponsor_id,
                    'level' => $this->calculateLevel($user->sponsor_id),
                    'position' => $user->placement_leg ?? 'left',
                    'status' => 'active',
                ]
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info('Genealogy placements populated!');
    }

    private function calculateLevel($sponsorId): int
    {
        $level = 0;
        $current = $sponsorId;
        while ($current) {
            $sponsor = User::find($current);
            if (!$sponsor || !$sponsor->sponsor_id) break;
            $current = $sponsor->sponsor_id;
            $level++;
        }
        return $level;
    }
}