<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Governance\SiemExportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Spec 11: on-demand SIEM export. Writes JSON Lines to stdout (or
 * `--output`), scheduled runs use the same service — see
 * `bootstrap/app.php`'s `withSchedule`.
 */
#[Signature('governance:export {org : The team slug} {--output= : File path to write; defaults to stdout}')]
#[Description('Exports one org\'s audit log as JSON Lines for SIEM ingestion')]
class GovernanceExportCommand extends Command
{
    public function handle(SiemExportService $service): int
    {
        $slug = (string) $this->argument('org');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->components->error("No team with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $jsonl = $service->export($team, actor: 'cli');

        $output = $this->option('output');
        if ($output) {
            file_put_contents($output, $jsonl);
            $this->components->info("Exported to {$output}.");
        } else {
            $this->line($jsonl);
        }

        return self::SUCCESS;
    }
}
