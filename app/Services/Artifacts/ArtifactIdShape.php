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
 * An `ephemeral` artifact — the anonymous, expiring preview `deploy_to_canvas`
 * publishes — has a 10-character alphanumeric id. Ephemeral artifacts have no
 * D1 row, so nothing that resolves content through the app can address them.
 */
final class ArtifactIdShape
{
    /** The 13-character lowercase base36 id every new permanent artifact gets. */
    private const STABLE = '/\A[a-z0-9]{13}\z/';

    /** The 32-character lowercase hex id permanent artifacts used before versioning. */
    private const LEGACY = '/\A[0-9a-f]{32}\z/';

    /** The 10-character alphanumeric id of an anonymous expiring preview. */
    private const EPHEMERAL = '/\A[A-Za-z0-9]{10}\z/';

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

    public static function isEphemeral(string $artifactId): bool
    {
        return preg_match(self::EPHEMERAL, $artifactId) === 1;
    }
}
