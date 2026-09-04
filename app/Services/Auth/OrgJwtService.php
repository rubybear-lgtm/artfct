<?php

namespace App\Services\Auth;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mints the RS256 JWTs the Worker verifies at the edge (spec 07). Laravel
 * signs; it never verifies its own tokens on the request path — that
 * happens entirely in the Worker against the published JWKS.
 *
 * Claims: `org_id`, `user_id`, `role`, `exp`, `jti` — the exact shape
 * `backend/src/lib.rs`'s `OrgJwtClaims` decodes.
 */
final class OrgJwtService
{
    public function __construct(
        private readonly string $privateKeyPem,
        private readonly string $kid,
    ) {}

    /**
     * Builds the signer from configuration. `services.org_jwt.private_key`
     * and `services.org_jwt.kid` are not currently defined in
     * `config/services.php` — that file is outside this spec's owned-files
     * list (see partition.md); wiring the real production key material is
     * flagged as a follow-up rather than done here. Fails closed: no key
     * configured means no tokens can be minted, the same fail-closed
     * pattern as `ARTFCT_ORG_TOKEN`/`ARTFCT_ARTIFACT_TOKEN_SECRET` on the
     * Worker side.
     */
    public static function default(): self
    {
        $privateKey = config('services.org_jwt.private_key');
        $kid = config('services.org_jwt.kid');

        if (! is_string($privateKey) || $privateKey === '' || ! is_string($kid) || $kid === '') {
            throw new RuntimeException(
                'services.org_jwt.private_key and services.org_jwt.kid must be configured to mint org tokens.',
            );
        }

        return new self($privateKey, $kid);
    }

    /**
     * Mints a token for the given team/user/role, expiring `$ttlSeconds`
     * from now (default: 5 minutes, matching `sessionJwt`'s lifetime;
     * callers minting a longer-lived `orgToken` pass a larger value).
     *
     * @return array{token: string, jti: string, expires_at: Carbon}
     */
    public function mint(Team $team, User $user, TeamRole $role, int $ttlSeconds = 300): array
    {
        $jti = (string) Str::uuid();
        $expiresAt = Carbon::now()->addSeconds($ttlSeconds);

        $token = JWT::encode(
            [
                'org_id' => $team->slug,
                'user_id' => (string) $user->id,
                'role' => $role->value,
                'exp' => $expiresAt->timestamp,
                'jti' => $jti,
            ],
            $this->privateKeyPem,
            'RS256',
            $this->kid,
        );

        return [
            'token' => $token,
            'jti' => $jti,
            'expires_at' => $expiresAt,
        ];
    }
}
