<?php

namespace App\Console\Commands;

use App\Services\Synthetic\SyntheticSeeder;
use App\Services\Synthetic\WorkerTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * RUB-325: builds a fictional org (`zz-` prefix, `@northwind.example` emails)
 * with users, tokens, collections, usage, audit history and an artifact
 * corpus deployed through the real Worker API, plus a small second org with
 * overlapping content for isolation tests. Deterministic for a given seed;
 * refuses in production; `--purge` removes only `zz-` orgs.
 */
#[Signature('synthetic:seed {--slug=zz-northwind} {--artifacts=60} {--target=local : local|staging} {--seed=1} {--purge} {--laravel-only : Seed users, teams and tokens only; skip the Worker corpus}')]
#[Description('Seeds (or purges) the synthetic test org and its artifact corpus')]
class SyntheticSeedCommand extends Command
{
    public function handle(SyntheticSeeder $seeder): int
    {
        $slug = (string) $this->option('slug');
        $target = (string) $this->option('target');

        try {
            SyntheticSeeder::assertSafe($slug);

            if (! in_array($target, ['local', 'staging'], true)) {
                $this->components->error('--target must be local or staging.');

                return self::FAILURE;
            }

            $laravelOnly = (bool) $this->option('laravel-only');
            $workerA = $laravelOnly && ! $this->option('purge') ? null : WorkerTarget::for($target, 'a', $slug);
            $workerB = $laravelOnly && ! $this->option('purge') ? null : WorkerTarget::for($target, 'b', $slug.'-b');

            if ($this->option('purge')) {
                $result = $seeder->purge([$slug => $workerA, $slug.'-b' => $workerB]);
                $this->components->info("Purged {$result['teams']} synthetic org(s) and {$result['artifacts']} artifact(s).");

                return self::SUCCESS;
            }

            $count = (int) $this->option('artifacts');
            $seed = (int) $this->option('seed');

            $a = $seeder->seedOrg($slug, 'Northwind Analytics', $workerA, $count, $seed);
            $b = $seeder->seedOrg($slug.'-b', 'Northwind Analytics B', $workerB, 8, $seed, primary: false);
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('%s: %d artifact(s) tracked; %s: %d.', $slug, count($a['ids']), $slug.'-b', count($b['ids'])));
        foreach ($a['refused'] as $key => $code) {
            $this->components->twoColumnDetail($key, "refused: {$code}");
        }

        if ($seeder->adminToken !== null) {
            $this->components->warn('Admin org token (shown once, not stored):');
            $this->line($seeder->adminToken);
        }

        return self::SUCCESS;
    }
}
