<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Billing\OrgLimitsWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * RUB-310: pushes each org's current limits and payment state to the
 * Worker. Reconciles a push that was dropped (the Worker fails open on a
 * lost push), so it also runs on a schedule to bound that window.
 */
#[Signature('billing:sync-limits {org? : The team slug; omit to sync every team}')]
#[Description('Pushes quota limits and payment state to the Worker')]
class BillingSyncLimitsCommand extends Command
{
    public function handle(OrgLimitsWriter $writer): int
    {
        $slug = $this->argument('org');
        $teams = Team::query()
            ->when(is_string($slug), fn ($query) => $query->where('slug', $slug))
            ->get();

        if ($teams->isEmpty()) {
            $this->components->error(is_string($slug) ? "No team with slug \"{$slug}\"." : 'No teams to sync.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($teams as $team) {
            if ($writer->push($team)) {
                $this->components->info("Pushed limits for {$team->slug}.");
            } else {
                $failed++;
                $this->components->error("Could not push limits for {$team->slug}.");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
