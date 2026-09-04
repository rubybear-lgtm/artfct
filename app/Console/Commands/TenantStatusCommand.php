<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Spec 09: `php artisan tenant:status`. Shows the schema-version
 * distribution across the fleet so version skew is visible rather than
 * inferred.
 */
#[Signature('tenant:status')]
#[Description('Shows the schema-version distribution across provisioned tenants')]
class TenantStatusCommand extends Command
{
    public function handle(): int
    {
        $teams = Team::query()->whereNotNull('provisioned_at')->get(['slug', 'schema_version']);

        if ($teams->isEmpty()) {
            $this->components->info('No provisioned tenants.');

            return self::SUCCESS;
        }

        $distribution = $teams->groupBy('schema_version')->map->count()->sortKeys();

        $this->table(
            ['Schema version', 'Tenant count'],
            $distribution->map(fn (int $count, int $version) => [$version, $count])->values()->all(),
        );

        return self::SUCCESS;
    }
}
