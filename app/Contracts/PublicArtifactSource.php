<?php

namespace App\Contracts;

/**
 * Reads a public permanent artifact's metadata with no credential, from the
 * Worker's unauthenticated `GET /v1/public/artifacts/{id}` endpoint. It is what
 * lets `/a/{id}` render for a signed-out visitor, or a visitor from another
 * team, without ever naming a team they are not in.
 *
 * Null is the Worker's only other answer: missing, team, private, revoked,
 * pending and ephemeral artifacts are one and the same 404, so the viewer can
 * never use this to confirm that a non-public id exists. Anything else is a
 * failure and is thrown rather than read as "not public".
 */
interface PublicArtifactSource
{
    /**
     * @return array{
     *     id: string,
     *     org: string,
     *     title: ?string,
     *     description: ?string,
     *     version: int,
     *     version_count: int,
     *     updated_at: ?string,
     *     sharing: 'public',
     *     edit_access: ?string
     * }|null
     */
    public function find(string $artifactId): ?array;
}
