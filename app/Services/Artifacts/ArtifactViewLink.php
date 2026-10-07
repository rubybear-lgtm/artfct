<?php

namespace App\Services\Artifacts;

/**
 * The one place that decides which URL a human is handed for an artifact,
 * so the console, the search page, the collections page and every MCP tool
 * cannot drift apart about it.
 *
 * The rule: every permanent artifact — `public`, `team`, `private` or a tier
 * the caller could not read — is handed out as the app's session-aware short
 * link, `/a/{id}` (or `/a/{id}/v/{n}` for one version). The viewer authorizes
 * the visitor, resolves the sharing level, and only then mints for an artifact
 * that needs it; a public one is framed untokened. The app's own open route is
 * what makes a link an agent returns work when a member clicks it while signed
 * in, and it is the link that is correct for a public artifact either way.
 *
 * `deploy_to_canvas` is the exception that proves the rule: its artifacts are
 * anonymous KV records with no D1 row, so the open route can only 404 for
 * them. That tool asks for `forAnonymousArtifact()` explicitly — the Worker's
 * own `/p/{id}` URL, which the KV path serves — rather than for the tier rule
 * above, because no tier of a KV artifact is addressable through the app.
 */
final class ArtifactViewLink
{
    /**
     * The tiers the Worker serves to anyone, with no credential involved: a
     * permanent `public` artifact and an anonymous `ephemeral` preview.
     */
    public const ANONYMOUS_TIERS = ['public', 'ephemeral'];

    /**
     * The Worker's own public URL for `$artifactId`, the same base
     * `SearchService` renders: the Worker serves `/p/{id}` for a public
     * artifact to anyone, so this URL is the whole link and carries no
     * credential.
     */
    public static function publicUrl(string $artifactId): string
    {
        $baseUrl = config('services.worker.public_base_url')
            ?: config('app.public_base_url')
            ?: config('services.worker.base_url')
            ?: 'https://artfct.dev';

        return rtrim((string) $baseUrl, '/')."/p/{$artifactId}";
    }

    public static function publicPermanentUrl(string $artifactId, ?int $version = null): string
    {
        return self::withPermanentEntrypointSlash($artifactId, self::publicUrl($artifactId), $version);
    }

    /**
     * The app's viewer route for one artifact. Session authentication lives
     * here, never in the URL. A `$version` names which published version the
     * viewer should show, in the path as `/a/{id}/v/{n}`. The owning team is
     * resolved from the signed-in member's memberships (or the Worker's public
     * read for a public artifact), so the route carries no team slug: an
     * artifact id is not a handle anyone can use to probe whether a team
     * exists. The slug parameter is kept for callers that already hold one.
     */
    public static function appOpenUrl(string $teamSlug, string $artifactId, ?int $version = null): string
    {
        if ($version !== null) {
            return route('artifacts.version', ['artifactId' => $artifactId, 'version' => $version]);
        }

        return route('artifacts.show', ['artifactId' => $artifactId]);
    }

    /**
     * Whether `$tier` is the anonymous KV preview `deploy_to_canvas` publishes:
     * the only Worker-served tier no app route can resolve from D1.
     */
    public static function isEphemeral(?string $tier): bool
    {
        return is_string($tier) && strtolower($tier) === 'ephemeral';
    }

    /**
     * Whether `$tier` is a tier the Worker serves without a credential.
     * Anything else — including a tier the caller could not read — is treated
     * as secure.
     */
    public static function isAnonymous(?string $tier): bool
    {
        return is_string($tier) && in_array(strtolower($tier), self::ANONYMOUS_TIERS, true);
    }

    /**
     * The URL for `$artifactId` in `$teamSlug` under the rule above.
     *
     * `$workerUrl` is the URL the Worker itself published for an anonymous
     * artifact (`ARTFCT_PUBLIC_BASE_URL`), when the caller's response carried
     * one. It wins over this environment's `app.public_base_url` because it is
     * the artifact's real address: on a staging deployment whose Worker public
     * base is not the app's, reconstructing from config would hand out a link
     * to the wrong host.
     */
    public static function forArtifact(string $teamSlug, string $artifactId, ?string $tier, ?string $workerUrl = null, ?int $version = null): string
    {
        // Only the anonymous KV preview has no D1 row and so no app route that
        // could resolve it: every permanent artifact, public included, is
        // handed out as the one short link and resolved there.
        if (self::isEphemeral($tier)) {
            return self::forAnonymousArtifact($artifactId, $workerUrl);
        }

        return self::appOpenUrl($teamSlug, $artifactId, $version);
    }

    /**
     * The link for an artifact only the Worker can serve: the anonymous KV
     * record with no D1 row, which is what `deploy_to_canvas` publishes at
     * every tier it accepts. The app's open route resolves content from D1, so
     * for such an artifact it is a guaranteed 404 — this URL is the whole link,
     * and no token is ever minted for it.
     */
    public static function forAnonymousArtifact(string $artifactId, ?string $workerUrl = null): string
    {
        return is_string($workerUrl) && $workerUrl !== '' ? $workerUrl : self::publicUrl($artifactId);
    }

    private static function withPermanentEntrypointSlash(string $artifactId, string $url, ?int $version = null): string
    {
        if (! ArtifactIdShape::isPermanent($artifactId) || ! str_ends_with($url, "/p/{$artifactId}")) {
            return $url;
        }

        if ($version !== null) {
            return $url."/v:{$version}/";
        }

        return $url.'/';
    }
}
