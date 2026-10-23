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
 * Anonymous free-tool previews (Worker KV `ephemeral` tier) are not addressed
 * here: they have no D1 row, so no app route can resolve them. The free tool
 * returns the Worker's own `/p/{id}` URL directly and never calls this helper
 * (RUB-452). The Worker ephemeral path remains intentional.
 */
final class ArtifactViewLink
{
    /**
     * The tier the Worker serves to anyone with no credential: a permanent
     * `public` artifact.
     */
    public const ANONYMOUS_TIERS = ['public'];

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
     * Every permanent artifact — public included — is handed out as the one
     * short link and resolved there. Anonymous KV previews never reach this
     * helper; see the class docblock.
     */
    public static function forArtifact(string $teamSlug, string $artifactId, ?string $tier, ?string $workerUrl = null, ?int $version = null): string
    {
        return self::appOpenUrl($teamSlug, $artifactId, $version);
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
