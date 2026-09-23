<?php

namespace App\Services\Governance;

/**
 * Mirrors `governance::RetentionPlan` on the Worker side — the retention
 * job's decision before anything executes. `dryRun` reports this without
 * touching storage.
 */
final readonly class RetentionPlan
{
    /**
     * @param  array<int, string>  $toDelete
     * @param  array<int, string>  $heldSurvivors
     */
    public function __construct(
        public array $toDelete,
        public array $heldSurvivors,
        public bool $dryRun,
    ) {}
}
