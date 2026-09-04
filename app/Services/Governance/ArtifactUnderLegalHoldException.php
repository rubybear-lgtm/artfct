<?php

namespace App\Services\Governance;

use RuntimeException;

/**
 * Thrown when a destructive path (hard delete, GDPR erasure) is attempted
 * against an artifact under legal hold. Carries the artifact id so the
 * refusal can name it — spec 11 DoD: "refused with both the hold and the
 * artifact named."
 */
final class ArtifactUnderLegalHoldException extends RuntimeException
{
    public function __construct(public readonly string $artifactId)
    {
        parent::__construct("Artifact [{$artifactId}] is under legal hold and cannot be hard-deleted.");
    }
}
