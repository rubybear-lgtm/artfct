<?php

namespace App\Services\Governance;

/**
 * Mirrors `governance::ErasurePlan` — either every referencing artifact is
 * clear to hard-delete, or the whole request is refused naming the first
 * held artifact. Never partial.
 */
final readonly class ErasurePlan
{
    /**
     * @param  array<int, string>  $artifactIds
     */
    private function __construct(
        public bool $refused,
        public array $artifactIds,
        public ?string $heldArtifactId,
        public bool $dryRun,
    ) {}

    /**
     * @param  array<int, string>  $artifactIds
     */
    public static function proceed(array $artifactIds, bool $dryRun): self
    {
        return new self(false, $artifactIds, null, $dryRun);
    }

    public static function refused(string $heldArtifactId, bool $dryRun): self
    {
        return new self(true, [], $heldArtifactId, $dryRun);
    }
}
