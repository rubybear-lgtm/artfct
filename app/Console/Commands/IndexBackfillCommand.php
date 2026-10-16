<?php

namespace App\Console\Commands;

use App\Enums\AuditEventType;
use App\Models\Team;
use App\Services\Indexing\IndexBackfillService;
use App\Services\Operator\OperatorAuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('indexing:backfill {org : The team slug} {--reason= : Why an operator is running this (5 to 500 characters); records the same audit event as the console\'s reindex action}')]
#[Description('Queues every live, not-yet-indexed artifact of one org for indexing, the same way the console reindex action does')]
class IndexBackfillCommand extends Command
{
    public function handle(IndexBackfillService $service, OperatorAuditLogger $audit): int
    {
        $reason = $this->option('reason');

        if ($reason !== null && ! OperatorAuditLogger::isValidReason((string) $reason)) {
            $this->components->error('--reason must be 5 to 500 characters.');

            return self::FAILURE;
        }

        $slug = (string) $this->argument('org');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->components->error("No team with slug \"{$slug}\".");

            return self::FAILURE;
        }

        // A refusal is recorded like the console records one, so the two
        // paths leave the same rows.
        $refuse = function (string $message) use ($audit, $reason, $team): int {
            $this->components->error($message);

            if ($reason !== null) {
                $audit->recordForCli(AuditEventType::OperatorReindexRequested, $team, $team->slug, trim((string) $reason), 'failure');
            }

            return self::FAILURE;
        };

        if (! config('indexing.enabled')) {
            return $refuse('Indexing is turned off.');
        }

        if ($service->actingAdmin($team) === null) {
            return $refuse("Team \"{$slug}\" has no admin to act as.");
        }

        ['queued' => $queued, 'skipped' => $skipped] = $service->backfill($team);

        $this->components->info("{$queued} artifact(s) queued for indexing, {$skipped} skipped (already indexed, revoked, unreadable, or already queued).");

        if ($reason !== null) {
            $audit->recordForCli(AuditEventType::OperatorReindexRequested, $team, $team->slug, trim((string) $reason));
        }

        return self::SUCCESS;
    }
}
