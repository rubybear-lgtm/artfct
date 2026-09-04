<?php

namespace App\Console\Commands;

use App\Jobs\IndexArtifactJob;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Spec 12: queues one artifact for indexing. The Worker has no live
 * webhook wired to call this automatically on `artifact.created` in this
 * environment (see DOCUMENTATION.md) — this command is the manual/ops
 * entry point in the meantime, and exercises the exact same queued job a
 * webhook would dispatch.
 */
#[Signature('indexing:index {org : The team slug} {artifact : The artifact id} {html-file : Path to the artifact\'s HTML} {--agent=} {--repo-url=} {--commit-sha=}')]
#[Description('Queues one artifact for headless-render indexing')]
class IndexArtifactCommand extends Command
{
    public function handle(): int
    {
        $slug = (string) $this->argument('org');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->components->error("No team with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $path = (string) $this->argument('html-file');
        if (! is_readable($path)) {
            $this->components->error("Cannot read \"{$path}\".");

            return self::FAILURE;
        }

        IndexArtifactJob::dispatch(
            $team->id,
            (string) $this->argument('artifact'),
            (string) file_get_contents($path),
            [
                'agent' => $this->option('agent'),
                'repo_url' => $this->option('repo-url'),
                'commit_sha' => $this->option('commit-sha'),
            ],
        );

        $this->components->info('Queued for indexing.');

        return self::SUCCESS;
    }
}
