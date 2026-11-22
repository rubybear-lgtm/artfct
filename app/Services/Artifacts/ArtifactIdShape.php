<?php

namespace App\Services\Artifacts;

/**
 * The one place that decides what an artifact id looks like, so the console,
 * the MCP tools and the isolated-origin minter cannot drift apart about it.
 *
 * A permanent artifact has one of two shapes:
 *
 * - `stable`: a 13-character lowercase base36 id. Every permanent artifact
 *   published since versioning landed gets this shape, and versioning keeps it
 *   for the artifact's whole life (RUB-437).
 * - `legacy`: the 32-character lowercase hex id permanent artifacts were
 *   published under before versioning.
 *
 * Only permanent ids exist now. The 10-character alphanumeric ids the removed
 * free tool minted are not artifact ids any more, so they fail every check here.
 */
final class ArtifactIdShape
{
    /** The 13-character lowercase base36 id every new permanent artifact gets. */
    private const STABLE = '/\A[a-z0-9]{13}\z/';

    /** The 32-character lowercase hex id permanent artifacts used before versioning. */
    private const LEGACY = '/\A[0-9a-f]{32}\z/';

    public static function isStable(string $artifactId): bool
    {
        return preg_match(self::STABLE, $artifactId) === 1;
    }

    public static function isLegacy(string $artifactId): bool
    {
        return preg_match(self::LEGACY, $artifactId) === 1;
    }

    /** Whether `$artifactId` is a permanent artifact id, either shape. */
    public static function isPermanent(string $artifactId): bool
    {
        return self::isStable($artifactId) || self::isLegacy($artifactId);
    }
}
