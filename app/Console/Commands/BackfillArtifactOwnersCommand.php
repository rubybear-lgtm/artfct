<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Sharing\OrgSettingsWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * RUB-438: gives every ownerless artifact an owner in the Worker, so the
 * Private sharing tier has someone to resolve to. The owner is the team's
 * `owner_user_id`, falling back to the earliest-joined admin when the team has
 * none. Idempotent — the Worker only fills artifacts that have no owner yet.
 */
#[Signature('artifacts:backfill-owners {--team= : Backfill only this team slug} {--dry-run : Print what would happen without calling the Worker}')]
#[Description('Give ownerless artifacts an owner in the Worker')]
class BackfillArtifactOwnersCommand extends Command
{
    public function handle(OrgSettingsWriter $writer): int
    {
        $slug = $this->option('team');
        $dryRun = (bool) $this->option('dry-run');
        $hasSlug = is_string($slug) && $slug !== '';

        $teams = Team::query()
            ->when($hasSlug, fn ($query) => $query->where('slug', $slug))
            ->get();

        if ($teams->isEmpty()) {
            $this->components->error($hasSlug ? "No team with slug \"{$slug}\"." : 'No teams to backfill.');

            return self::FAILURE;
        }

        foreach ($teams as $team) {
            $ownerId = $team->owner_user_id ?? $team->firstAdmin()?->id;

            if ($ownerId === null) {
                $this->components->warn("Skipping {$team->slug}: it has no owner and no admin to fall back to.");

                continue;
            }

            if ($dryRun) {
                $this->components->info("Would set owner {$ownerId} on ownerless artifacts of {$team->slug}.");

                continue;
            }

            $updated = $writer->backfillOwners($team->slug, (string) $ownerId);
            $this->components->info("Updated {$updated} artifact(s) for {$team->slug} (owner {$ownerId}).");
        }

        return self::SUCCESS;
    }
}
