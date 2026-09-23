<?php

namespace App\Services\Artifacts;

/**
 * The one place that decides which URL a human is handed for an artifact,
 * so the console, the search page, the collections page and every MCP tool
 * cannot drift apart about it.
 *
 * The rule: an artifact the Worker serves without a credential — a `public`
 * permanent artifact, or the anonymous `ephemeral` preview `deploy_to_canvas`
 * publishes — is handed out as the Worker's own `/p/{id}` URL, and no token is
 * minted for it. Every other artifact (secure, or a tier the caller could not
 * read) is handed out as the app's session-authenticated open route, which
 * authorizes the viewer, resolves the tier, and only then mints. A link an
 * agent returns therefore works when a member clicks it while signed in, and an
 * unreadable tier fails toward the authenticated route rather than toward a
 * credential-less URL that can only 403 in a browser.
 *
 * The open route is addressed relative to the request the link is minted in,
 * so it works for the hosted MCP endpoint and for the console alike.
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
        return rtrim((string) config('app.public_base_url', 'https://artfct.dev'), '/')."/p/{$artifactId}";
    }

    /**
     * The app's open route for one artifact in `$teamSlug`. Session
     * authentication lives here, never in the URL.
     */
    public static function appOpenUrl(string $teamSlug, string $artifactId): string
    {
        return route('console.open', ['team' => $teamSlug, 'artifactId' => $artifactId]);
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
    public static function forArtifact(string $teamSlug, string $artifactId, ?string $tier, ?string $workerUrl = null): string
    {
        if (self::isAnonymous($tier)) {
            return self::forAnonymousArtifact($artifactId, $workerUrl);
        }

        return self::appOpenUrl($teamSlug, $artifactId);
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
}
