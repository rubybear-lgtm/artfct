<?php

namespace App\Http\Controllers\Teams;

use App\Contracts\ArtifactContentSource;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Sharing\ArtifactVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Renders one artifact's entrypoint as an inert page inside the app, so a
 * member can look at a secure artifact without minting a link, waiting on an
 * expiry, or needing the Worker's signing secret.
 *
 * The body is untrusted HTML the Worker stored, so it is served under the
 * response's `sandbox` directive and a `default-src 'none'` policy: scripts
 * and external resources are blocked, the document cannot keep the app's
 * origin, and it cannot navigate its parent. The preview is
 * session-authenticated and per-viewer, never a shareable URL: a found
 * artifact is cacheable by the viewer's own browser only (`private`, briefly),
 * so a stack of cards showing the same artifact does not refetch it from the
 * Worker, while a refusal is never cached.
 *
 * Membership is resolved from the caller's own teams before the Worker is
 * asked for anything. A missing, revoked or foreign artifact is one generic
 * 404, so the endpoint is never an existence oracle for another org's ids,
 * and an upstream failure is a generic 503 that carries none of the
 * exception.
 */
class ArtifactPreviewController extends Controller
{
    /**
     * Not a variation of the Worker's artifact policy: this page is the app's
     * own origin, so every source is refused rather than narrowed. Inline
     * styles stay allowed because the stored HTML is self-contained.
     */
    /** Seconds a viewer's browser may reuse a found artifact's preview. */
    private const CACHE_SECONDS = 120;

    private const CONTENT_SECURITY_POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; base-uri 'none'; form-action 'none'; sandbox; frame-ancestors 'self'";

    /**
     * One static page for every refusal, so a 404 cannot be told apart from a
     * foreign org's artifact and a 503 cannot leak the upstream error. Styled
     * inline to match the app without loading anything.
     */
    private const UNAVAILABLE_HTML = <<<'HTML'
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Preview unavailable</title>
        <style>
        html { background: #fdf6e3; color: #586e75; font: 16px/1.6 system-ui, sans-serif; }
        body { margin: 0; display: grid; min-height: 100vh; place-items: center; padding: 1.5rem; }
        main { max-width: 32rem; text-align: center; }
        h1 { color: #073642; font-size: 1.25rem; }
        </style>
        </head>
        <body>
        <main>
        <h1>Preview unavailable</h1>
        <p>This artifact could not be shown.</p>
        </main>
        </body>
        </html>
        HTML;

    public function __invoke(Request $request, string $teamSlug, string $artifactId, ArtifactContentSource $content): Response
    {
        $user = $request->user();
        $team = $this->memberTeam($request, $teamSlug);

        if ($user === null || $team === null) {
            return $this->unavailable();
        }

        try {
            $artifact = $content->fetch($team->slug, $artifactId);
        } catch (Throwable $exception) {
            // The failure is logged, never rendered: the body is shown in the
            // browser and may be embedded by the page that linked here.
            report($exception);

            return $this->unavailable(503);
        }

        if ($artifact === null) {
            return $this->unavailable();
        }

        // The content read uses a system credential that can see private
        // artifacts, so it can never be the thing that decides what this
        // member may view: apply the Private rule itself. A non-owner member
        // gets the same generic 404 as a missing artifact.
        if (! ArtifactVisibility::canView($artifact['sharing'] ?? null, $artifact['owner_user_id'] ?? null, $user, $team)) {
            return $this->unavailable();
        }

        return $this->html($artifact['html']);
    }

    /**
     * The caller's own membership, never a route-bound model: a team the
     * caller is not in must read as one that does not exist, and the Worker
     * must not be asked about an org the caller has no claim to.
     */
    private function memberTeam(Request $request, string $slug): ?Team
    {
        return $request->user()?->teams()->where('slug', $slug)->first();
    }

    private function unavailable(int $status = 404): Response
    {
        return $this->html(self::UNAVAILABLE_HTML, $status);
    }

    private function html(string $body, int $status = 200): Response
    {
        return response($body, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => $status === 200 ? 'private, max-age='.self::CACHE_SECONDS : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
        ]);
    }
}
