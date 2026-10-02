<?php

namespace App\Services\Auth;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;

/**
 * Writes to the Worker's internal revocation-denylist endpoint on token
 * revoke (spec 07). This is a POST to `{worker}/v1/internal/revocations`
 * authenticated with a shared secret separate from `orgToken`/`sessionJwt`
 * — `ARTFCT_REVOCATION_WRITE_SECRET`, matched against the same env var name
 * the Worker checks (`backend/src/lib.rs`'s `REVOCATION_WRITE_SECRET_ENV`).
 *
 * Laravel does not call the Cloudflare API directly (that would need a
 * Cloudflare API credential this project doesn't have and would only
 * relocate the mock-policy problem) — it calls the Worker's own endpoint,
 * which is itself an authorization boundary. Testable in Pest with
 * `Http::fake()`; no real Cloudflare credential is ever required.
 */
final class RevocationWriter
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $secret = null,
    ) {}

    public static function default(): self
    {
        return new self(
            config('services.org_jwt.worker_base_url') ?: null,
            config('services.org_jwt.revocation_write_secret') ?: null,
        );
    }

    /**
     * Writes a denylist entry for `$jti`, expiring at `$expiresAt` (the
     * token's own natural expiry, so the entry expires with it and the
     * denylist stays small). Returns whether the write succeeded.
     */
    public function revoke(string $jti, CarbonInterface $expiresAt): bool
    {
        if (! $this->baseUrl || ! $this->secret) {
            return false;
        }

        $response = Http::withToken($this->secret)
            ->post(rtrim($this->baseUrl, '/').'/v1/internal/revocations', [
                'jti' => $jti,
                'expires_at_unix' => $expiresAt->timestamp,
            ]);

        return $response->successful();
    }
}
