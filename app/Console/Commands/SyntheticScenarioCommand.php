<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Synthetic\SyntheticScenario;
use App\Services\Synthetic\SyntheticSeeder;
use App\Services\Synthetic\WorkerTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('synthetic:scenario {org} {scenario : healthy|near-quota|over-quota|past-due} {--target=local}')]
#[Description('Puts a synthetic org into a quota or payment state')]
class SyntheticScenarioCommand extends Command
{
    public function handle(SyntheticScenario $scenario): int
    {
        $slug = (string) $this->argument('org');

        try {
            SyntheticSeeder::assertSafe($slug);
            $team = Team::query()->where('slug', $slug)->firstOrFail();
            $which = str_ends_with($slug, '-b') ? 'b' : 'a';
            $result = $scenario->apply($team, (string) $this->argument('scenario'), WorkerTarget::for((string) $this->option('target'), $which, $slug));
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s is now %s (storage limit %d bytes, payment %s, limits %s).',
            $slug,
            $this->argument('scenario'),
            $result['limits']->storageBytes,
            $result['payment_status']->value,
            $result['pushed'] ? 'pushed' : 'NOT pushed',
        ));

        return $result['pushed'] ? self::SUCCESS : self::FAILURE;
    }
}
