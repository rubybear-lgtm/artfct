<?php

namespace App\Mcp\Support;

use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\ArtifactViewLink;
use Laravel\Mcp\Response;
use RuntimeException;

/**
 * The `view_url` an MCP tool hands back for an artifact, or the structured
 * error saying this environment cannot offer an openable one.
 *
 * Every tool that returns a link comes through here, and this defers to
 * `ArtifactViewLink` — the same rule the console's open route now redirects
 * to — so an agent's link and the console's link cannot drift apart. Every
 * permanent artifact, `public` included, gets the app's short link: a public
 * one renders for anyone and needs no credential; a secure one is authorized
 * and its token minted there, per click, never handed to the agent. Only the
 * anonymous `ephemeral` preview `deploy_to_canvas` publishes gets the Worker's
 * own `/p/{id}` URL, because no app route can resolve a KV record.
 *
 * A secure artifact is still refused here, before any link is handed out, in
 * the two cases the app's route could only turn into a dead end:
 *
 * - No signing secret. The open route fails closed with 503, so the caller gets
 *   `signed_link_unavailable` with `retryable: false` — the same structured-error
 *   posture the tools already use for an unconfigured `services.worker.base_url`
 *   (`configuration_error`) and for an unavailable upstream. The console hides
 *   its Open control for the same reason, so both surfaces refuse identically.
 * - An artifact whose id cannot form an isolated hostname. A link built anyway
 *   could only ever 403 or 404 at the artifact origin, so the artifact is
 *   refused rather than linked.
 */
final class McpArtifactLink
{
    /** Stable machine-readable code for every "no openable link" outcome. */
    public const ERROR_CODE = 'signed_link_unavailable';

    private const NOT_CONFIGURED = 'Signed artifact links are not configured on this environment, so no openable URL is available.';

    private const UNFORMABLE = 'That artifact cannot form an isolated-origin link, so no openable URL is available.';

    /**
     * The link for `$artifactId` in the calling organization, or the error to
     * return instead. `$teamSlug` is the credential's workspace slug
     * (`McpContext::team()`), never a caller-supplied value: it is the org the
     * app re-authorizes the viewer against when the link is followed, and it is
     * the tenant the isolated host is built from.
     *
     * `$tier` is the Worker's tier for the artifact when the response carried
     * one. Omit it when the surface cannot read a tier: an unreadable tier
     * resolves to the app's open route, which is the one link that is safe
     * either way.
     *
     * `$workerUrl` is the URL the Worker published for the artifact, when the
     * response carried one. It is used only for an anonymous artifact, where it
     * is the artifact's real address — see `ArtifactViewLink::forArtifact`.
     *
     * `$version` names one published version; omitted, the link resolves to the
     * artifact's current version exactly as before.
     */
    public static function forArtifact(string $teamSlug, string $artifactId, ?string $tier = null, ?string $workerUrl = null, ?int $version = null): string|Response
    {
        // Public and ephemeral are the tiers the Worker can serve without a
        // credential, so no signing secret is required to hand out a link for
        // one: `forArtifact` gives a public artifact the app's short route and
        // the ephemeral preview the Worker's own URL.
        if (ArtifactViewLink::isAnonymous($tier)) {
            return ArtifactViewLink::forArtifact($teamSlug, $artifactId, $tier, $workerUrl, $version);
        }

        $links = ArtifactAccessLink::default();

        if (! $links->configured()) {
            return McpErrorResponse::error(self::NOT_CONFIGURED, self::ERROR_CODE);
        }

        try {
            $links->assertLinkable($teamSlug, $artifactId);
        } catch (RuntimeException $exception) {
            // A slug or id no isolated hostname can be built from is a boundary
            // error, not an MCP internal error: the artifact may exist, but no
            // link can honestly be offered for it.
            report($exception);

            return McpErrorResponse::error(self::UNFORMABLE, self::ERROR_CODE);
        }

        return ArtifactViewLink::appOpenUrl($teamSlug, $artifactId, $version);
    }
}
