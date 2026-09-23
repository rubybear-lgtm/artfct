<?php

namespace App\Services\Artifacts;

use App\Rules\TeamSlug;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Mints the short-lived signed link that opens one artifact on its isolated
 * origin (spec 05). The token is
 * `<artifact_id>.<expires_at_unix>.<hmac_sha256_hex>` over
 * `<artifact_id>.<expires_at_unix>`, signed with the secret the Worker reads
 * from `ARTFCT_ARTIFACT_TOKEN_SECRET`, and travels as `?token=` because a
 * top-level browser navigation cannot carry an `Authorization` header.
 *
 * The token is a bearer credential for the artifact: it belongs in the
 * redirect response and nowhere else. Do not log it, persist it, or put it in
 * page props.
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
     */
    public function forArtifact(string $tenantSlug, string $artifactId, ?CarbonInterface $expiresAt = null): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $hostname = $this->isolatedHostname($tenantSlug, $artifactId);
        $expiresAtUnix = ($expiresAt ?? now()->addMinutes($this->ttlMinutes))->getTimestamp();

        return "https://{$hostname}/p/{$artifactId}?token={$this->mintToken($artifactId, $expiresAtUnix)}";
    }

    /**
     * `<artifact_id>.<expires_at_unix>.<hmac_hex>` — the exact wire form
     * `verify_access_token` parses and re-derives the signature over.
     */
    private function mintToken(string $artifactId, int $expiresAtUnix): string
    {
        $message = "{$artifactId}.{$expiresAtUnix}";

        return $message.'.'.hash_hmac('sha256', $message, (string) $this->secret);
    }

    /**
     * Mirrors `store::hostname_label`: a slug the Worker accepts, a public
     * artifact id, and a label that fits one DNS label. Anything else is a
     * boundary error rather than a link to hand a browser — a link built
     * anyway would only ever 403 at the artifact origin.
     */
    private function isolatedHostname(string $tenantSlug, string $artifactId): string
    {
        if (! TeamSlug::isValid($tenantSlug)) {
            throw new RuntimeException("Team slug [{$tenantSlug}] cannot form an artifact hostname.");
        }

        if (preg_match('/^[0-9a-f]{32}$/', $artifactId) !== 1) {
            throw new RuntimeException("Artifact id [{$artifactId}] is not a public artifact id.");
        }

        $label = "{$tenantSlug}--{$artifactId}";

        if (strlen($label) > self::MAX_HOSTNAME_LABEL_LENGTH) {
            throw new RuntimeException("Artifact hostname label [{$label}] exceeds 63 characters.");
        }

        return $label.$this->originSuffix;
    }
}
