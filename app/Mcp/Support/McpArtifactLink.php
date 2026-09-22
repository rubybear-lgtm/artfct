<?php

namespace App\Mcp\Support;

use App\Services\Artifacts\ArtifactAccessLink;
use Laravel\Mcp\Response;
use RuntimeException;

/**
 * The signed link an MCP tool hands back for an artifact, or the structured
 * error saying this environment cannot mint one.
 *
 * Every tool that returns a URL comes through here, and this goes through
 * `ArtifactAccessLink` — the same minter `ConsoleController::open` uses — so
 * the console's link and the MCP link cannot drift apart.
 *
 * Unset signing secret fails closed rather than falling back to the Worker's
 * raw `/p/{id}` URL: that URL carries no credential, and a browser cannot send
 * an `Authorization` header on a top-level navigation, so for a `secure`
 * artifact it can only 403. The caller gets `signed_link_unavailable` with
 * `retryable: false` instead, the same structured-error posture the tools
 * already use for an unconfigured `services.worker.base_url`
 * (`configuration_error`) and for an unavailable upstream. The console fails
 * closed the same way — HTTP 503 and no Open control — so both surfaces refuse
 * identically. The one code covers both causes of "there is no openable link
 * for this artifact": no secret, and an artifact whose id cannot form an
 * isolated hostname.
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
     * (`McpContext::team()`), never a caller-supplied value: the host is what
     * binds the link to the org the Worker will authorize.
     */
    public static function forArtifact(string $teamSlug, string $artifactId): string|Response
    {
        $links = ArtifactAccessLink::default();

        if (! $links->configured()) {
            return McpErrorResponse::error(self::NOT_CONFIGURED, self::ERROR_CODE);
        }

        try {
            $url = $links->forArtifact($teamSlug, $artifactId);
        } catch (RuntimeException $exception) {
            // A slug or id no isolated hostname can be built from is a boundary
            // error, not an MCP internal error: the artifact may exist, but no
            // link can honestly be offered for it.
            report($exception);

            return McpErrorResponse::error(self::UNFORMABLE, self::ERROR_CODE);
        }

        if ($url === null) {
            return McpErrorResponse::error(self::NOT_CONFIGURED, self::ERROR_CODE);
        }

        return $url;
    }
}
