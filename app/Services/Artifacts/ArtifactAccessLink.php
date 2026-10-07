<?php

namespace App\Services\Artifacts;

use App\Rules\TeamSlug;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Mints the short-lived signed link that opens one artifact on its isolated
 * origin (spec 05). Two wire forms travel as `?token=`, because a top-level
 * browser navigation cannot carry an `Authorization` header:
 *
 * - The viewer form
 *   `<artifact_id>.<expires_at_unix>.<viewer>.<scope>.<hmac_sha256_hex>`,
 *   minted when the caller names the Laravel user the link is for (`$viewer`)
 *   and whether that viewer may see private artifacts in the team (`p` for a
 *   team admin or the server-side `system` renderer, `m` otherwise). The
 *   Worker accepts it for a private artifact only when the scope is `p` or the
 *   viewer is the artifact's owner, so a link minted while an artifact was
 *   team-visible stops opening it as soon as the owner makes it private.
 * - The legacy `<artifact_id>.<expires_at_unix>.<hmac_sha256_hex>`, kept for
 *   callers that cannot name a viewer. The Worker accepts it for non-private
 *   artifacts only.
 *
 * Either way the HMAC is over every field before it, signed with the secret the
 * Worker reads from `ARTFCT_ARTIFACT_TOKEN_SECRET`. The token is a bearer
 * credential for the artifact: it belongs in the redirect response and nowhere
 * else. Do not log it, persist it, or put it in page props.
 */
final class ArtifactAccessLink
{
    /**
     * The Worker's `store::hostname_label` ceiling — a longer label could
     * never serve under the wildcard certificate.
     */
    private const MAX_HOSTNAME_LABEL_LENGTH = 63;

    public function __construct(
        private readonly ?string $secret = null,
        private readonly string $originSuffix = '.artfct.dev',
        private readonly int $ttlMinutes = 60,
    ) {}

    public static function default(): self
    {
        return new self(
            config('services.artifact_access.token_secret') ?: null,
            (string) config('services.artifact_access.origin_suffix', '.artfct.dev'),
            (int) config('services.artifact_access.token_ttl_minutes', 60),
        );
    }

    /**
     * Whether this environment can mint links at all. Unset secret fails
     * closed, the same posture as the Worker, which never authorizes a token
     * without one.
     */
    public function configured(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /**
     * How long a link this minter hands out stays valid. Callers that need to
     * name the expiry they are about to get — the mint audit event does — read
     * it here rather than re-deriving the default, so the audited expiry and
     * the minted expiry are the same value by construction.
     */
    public function ttlMinutes(): int
    {
        return $this->ttlMinutes;
    }

    /**
     * Refuses a pair no isolated origin can be built from. A caller that hands
     * out a link without minting it itself (the MCP tools hand out the app's
     * open route) still needs this: an id no isolated hostname can be built
     * from can only ever 404 or 403 at the artifact origin, so refusing it up
     * front is the honest answer.
     *
     * @throws RuntimeException when no link can be built for this pair.
     */
    public function assertLinkable(string $tenantSlug, string $artifactId): void
    {
        $this->isolatedHostname($tenantSlug, $artifactId);
    }

    /**
     * The isolated-origin URL for `$artifactId`, or null when no signing
     * secret is configured. The host mirrors the Worker's
     * `isolated_artifact_hostname` — `<tenant-slug>--<artifact-id><suffix>` —
     * which is also what binds the link to the org that owns the artifact:
     * the Worker only authorizes a token presented on the owning org's host.
     *
     * Passing `$viewer` mints the viewer-bound token; omitting it mints the
     * legacy token the Worker accepts only for non-private artifacts. The
     * scope bit is only meaningful with a viewer.
     */
    public function forArtifact(
        string $tenantSlug,
        string $artifactId,
        ?CarbonInterface $expiresAt = null,
        ?int $version = null,
        ?string $viewer = null,
        bool $viewerSeesPrivate = false,
    ): ?string {
        if (! $this->configured()) {
            return null;
        }

        $hostname = $this->isolatedHostname($tenantSlug, $artifactId);
        $expiresAtUnix = ($expiresAt ?? now()->addMinutes($this->ttlMinutes))->getTimestamp();

        $path = $version === null ? "/p/{$artifactId}/" : "/p/{$artifactId}/v:{$version}/";

        $token = $viewer === null
            ? $this->mintToken($artifactId, $expiresAtUnix)
            : $this->mintViewerToken($artifactId, $expiresAtUnix, $viewer, $viewerSeesPrivate);

        return "https://{$hostname}{$path}?token={$token}";
    }

    /**
     * The same isolated-origin URL without a token. A `public` artifact is
     * served by the Worker to anyone, so it needs no credential and no
     * signing secret is required to build this. The hostname rules are the
     * same as `forArtifact`, so the tokened and untokened links for one
     * artifact can never point at different hosts.
     */
    public function publicArtifactUrl(string $tenantSlug, string $artifactId, ?int $version = null): string
    {
        $hostname = $this->isolatedHostname($tenantSlug, $artifactId);
        $path = $version === null ? "/p/{$artifactId}/" : "/p/{$artifactId}/v:{$version}/";

        return "https://{$hostname}{$path}";
    }

    /**
     * `<artifact_id>.<expires_at_unix>.<hmac_hex>` — the legacy wire form
     * `verify_access_token` parses and re-derives the signature over. The
     * Worker accepts it for non-private artifacts only.
     */
    private function mintToken(string $artifactId, int $expiresAtUnix): string
    {
        $message = "{$artifactId}.{$expiresAtUnix}";

        return $message.'.'.hash_hmac('sha256', $message, (string) $this->secret);
    }

    /**
     * `<artifact_id>.<expires_at_unix>.<viewer>.<scope>.<hmac_hex>` — the
     * viewer-bound form. `$viewerSeesPrivate` becomes scope `p` (a team admin
     * or the `system` renderer) or `m`; the HMAC is over every field before it.
     */
    private function mintViewerToken(string $artifactId, int $expiresAtUnix, string $viewer, bool $viewerSeesPrivate): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $viewer) !== 1) {
            throw new RuntimeException("Viewer [{$viewer}] is not a valid access-token viewer.");
        }

        $scope = $viewerSeesPrivate ? 'p' : 'm';
        $message = "{$artifactId}.{$expiresAtUnix}.{$viewer}.{$scope}";

        return $message.'.'.hash_hmac('sha256', $message, (string) $this->secret);
    }

    /**
     * Mirrors `store::hostname_label`: a slug the Worker accepts, a permanent
     * artifact id, and a label that fits one DNS label. Anything else is a
     * boundary error rather than a link to hand a browser — a link built
     * anyway would only ever 403 at the artifact origin.
     */
    private function isolatedHostname(string $tenantSlug, string $artifactId): string
    {
        if (! TeamSlug::isValid($tenantSlug)) {
            throw new RuntimeException("Team slug [{$tenantSlug}] cannot form an artifact hostname.");
        }

        if (! ArtifactIdShape::isPermanent($artifactId)) {
            throw new RuntimeException("Artifact id [{$artifactId}] is not a permanent artifact id.");
        }

        $label = "{$tenantSlug}--{$artifactId}";

        if (strlen($label) > self::MAX_HOSTNAME_LABEL_LENGTH) {
            throw new RuntimeException("Artifact hostname label [{$label}] exceeds 63 characters.");
        }

        return $label.$this->originSuffix;
    }
}
