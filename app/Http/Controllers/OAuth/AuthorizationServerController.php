<?php

namespace App\Http\Controllers\OAuth;

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\McpConnection;
use App\Models\OAuthClient;
use App\Models\OAuthRefreshToken;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use App\Services\Auth\RevocationWriter;
use App\Services\Governance\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class AuthorizationServerController extends Controller
{
    /** @var list<string> */
    private const SUPPORTED_SCOPES = ['artifacts:read', 'artifacts:deploy', 'artifacts:delete', 'collections:read', 'collections:write', 'usage:read'];

    private const REFRESH_TOKEN_TTL_DAYS = 30;

    public function authorizationServerMetadata(): JsonResponse
    {
        return response()->json([
            'issuer' => $this->issuer(),
            'authorization_endpoint' => route('oauth.authorize'),
            'token_endpoint' => route('oauth.token'),
            'revocation_endpoint' => route('oauth.revoke'),
            'registration_endpoint' => route('oauth.register'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => self::SUPPORTED_SCOPES,
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => false,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_name' => ['required', 'string', 'max:128'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string', 'max:2048', 'distinct'],
            'grant_types' => ['nullable', 'array', 'min:1', 'max:2'],
            'grant_types.*' => ['string', 'in:authorization_code,refresh_token'],
            'response_types' => ['nullable', 'array', 'min:1', 'max:1'],
            'response_types.*' => ['string', 'in:code'],
            'token_endpoint_auth_method' => ['nullable', 'string', 'in:none'],
        ]);
        if ($validator->fails()) {
            return $this->registrationError('The client metadata is invalid.');
        }
        $validated = $validator->validated();

        foreach ($validated['redirect_uris'] as $redirectUri) {
            if (! $this->isAllowedRedirectUri($redirectUri)) {
                return $this->registrationError(
                    'Every redirect URI must use HTTPS or a loopback HTTP address.',
                );
            }
        }

        $client = OAuthClient::query()->create([
            'client_id' => 'artfct_'.Str::lower(Str::random(32)),
            'client_name' => $validated['client_name'],
            'redirect_uris' => array_values($validated['redirect_uris']),
            'grant_types' => $validated['grant_types'] ?? ['authorization_code', 'refresh_token'],
            'response_types' => $validated['response_types'] ?? ['code'],
            'token_endpoint_auth_method' => 'none',
            'client_id_issued_at' => now()->timestamp,
        ]);

        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->client_id_issued_at,
            'client_name' => $client->client_name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
            'response_types' => $client->response_types,
            'token_endpoint_auth_method' => $client->token_endpoint_auth_method,
        ], 201);
    }

    public function protectedResourceMetadata(): JsonResponse
    {
        return response()->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => self::SUPPORTED_SCOPES,
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /**
     * Return the organizations visible to the OAuth user so CLI clients can
     * offer explicit context switching without asking users to paste tokens.
     */
    public function organizations(Request $request): JsonResponse
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        try {
            $claims = OrgJwtService::default()->verify(substr($header, 7));
        } catch (\Throwable) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $orgToken = OrgToken::query()->where('jti', $claims['jti'])->first();
        $user = User::query()->find($claims['user_id']);
        if ($orgToken?->revoked_at !== null || ! $user instanceof User) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        return response()->json([
            'organizations' => $user->teams()->get(['teams.id', 'teams.name', 'teams.slug'])->map(function (Team $team) use ($user, $claims): array {
                return [
                    'name' => $team->name,
                    'slug' => $team->slug,
                    'role' => $user->teamRole($team)?->value,
                    'selected' => $team->slug === $claims['org_id'],
                ];
            })->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function authorize(Request $request): Response|RedirectResponse
    {
        $parameters = $this->authorizationParameters($request);

        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }

        /** @var User $user */
        $user = $request->user();
        $team = $this->teamFor($user, $parameters['team']);

        abort_if($team === null, 403, 'You must belong to a team before connecting an MCP client.');

        return Inertia::render('oauth/authorize', [
            'clientId' => $parameters['client_id'],
            'userName' => $user->name,
            'redirectUri' => $parameters['redirect_uri'],
            'scope' => $parameters['scope'],
            'state' => $parameters['state'],
            'codeChallenge' => $parameters['code_challenge'],
            'codeChallengeMethod' => $parameters['code_challenge_method'],
            'consentToken' => $this->consentToken($parameters),
            'team' => ['id' => $team->slug, 'name' => $team->name, 'slug' => $team->slug],
            'teams' => $user->teams()->get(['teams.id', 'teams.name', 'teams.slug'])->map(fn (Team $candidate): array => [
                'id' => $candidate->slug,
                'name' => $candidate->name,
                'slug' => $candidate->slug,
            ])->values(),
        ]);
    }

    public function approve(Request $request): SymfonyResponse
    {
        $parameters = $this->authorizationParameters($request);

        $consentToken = $request->string('consent_token')->toString();
        if ($consentToken === '' || ! hash_equals($this->consentToken($parameters), $consentToken)) {
            throw ValidationException::withMessages([
                'consent_token' => 'This authorization request is stale or has been modified. Start the connection again.',
            ]);
        }

        $decision = $request->string('decision')->toString();

        abort_unless(in_array($decision, ['approve', 'deny'], true), 422);

        if ($decision === 'deny') {
            return $this->redirectWith($parameters['redirect_uri'], [
                'error' => 'access_denied',
                'error_description' => 'The resource owner denied the request.',
                'iss' => $this->issuer(),
                ...($parameters['state'] !== null ? ['state' => $parameters['state']] : []),
            ]);
        }

        /** @var User $user */
        $user = $request->user();
        $team = $this->teamFor($user, $request->input('team'));

        abort_if($team === null, 403, 'The selected team is not available to this account.');

        $code = Str::random(96);
        Cache::put($this->codeKey($code), [
            'user_id' => $user->id,
            'team_id' => $team->id,
            'client_id' => $parameters['client_id'],
            'redirect_uri' => $parameters['redirect_uri'],
            'code_challenge' => $parameters['code_challenge'],
            'scope' => $parameters['scope'],
        ], now()->addMinutes(5));

        return $this->redirectWith($parameters['redirect_uri'], [
            'code' => $code,
            'iss' => $this->issuer(),
            ...($parameters['state'] !== null ? ['state' => $parameters['state']] : []),
        ]);
    }

    public function token(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $rules = [
            'grant_type' => ['required', 'string', 'in:authorization_code,refresh_token'],
            'client_id' => ['required', 'string', 'max:200'],
        ];
        if ($request->input('grant_type') === 'refresh_token') {
            $rules['refresh_token'] = ['required', 'string', 'max:200'];
        } else {
            $rules['code'] = ['required', 'string', 'max:200'];
            $rules['redirect_uri'] = ['required', 'string', 'max:2048'];
            $rules['code_verifier'] = ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->tokenError('invalid_request', 'The token request is invalid.');
        }
        $validated = $validator->validated();

        if ($validated['grant_type'] === 'refresh_token') {
            return $this->refresh($validated, $request, $auditLogger);
        }

        $authorization = Cache::pull($this->codeKey($validated['code']));
        if (! is_array($authorization)) {
            return $this->tokenError('invalid_grant', 'The authorization code is invalid or expired.');
        }

        if ($authorization['client_id'] !== $validated['client_id'] || $authorization['redirect_uri'] !== $validated['redirect_uri']) {
            return $this->tokenError('invalid_grant', 'The authorization code is not valid for this client.');
        }

        if (! hash_equals($authorization['code_challenge'], $this->pkceChallenge($validated['code_verifier']))) {
            return $this->tokenError('invalid_grant', 'The code verifier is invalid.');
        }

        $user = User::query()->find($authorization['user_id']);
        $team = Team::query()->find($authorization['team_id']);
        $role = $user instanceof User && $team instanceof Team ? $user->teamRole($team) : null;

        if (! $user instanceof User || ! $team instanceof Team || ! $role instanceof TeamRole) {
            return $this->tokenError('invalid_grant', 'The authorization is no longer valid.');
        }

        $scopes = $this->scopesForRole($role);
        $requestedScopes = array_values(array_filter(explode(' ', (string) $authorization['scope'])));
        if (array_diff($requestedScopes, $scopes) !== []) {
            return $this->tokenError('invalid_scope', 'The requested scope is not available to this account.');
        }

        try {
            $minted = OrgJwtService::default()->mint($team, $user, $role, 300, $requestedScopes);
        } catch (\Throwable) {
            return $this->tokenError('server_error', 'The authorization server is not configured.');
        }

        $orgToken = OrgToken::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => 'MCP OAuth ('.$authorization['client_id'].')',
            'jti' => $minted['jti'],
            'role' => $role,
            'last_four' => Str::substr($minted['token'], -4),
            'expires_at' => $minted['expires_at'],
        ]);
        $connection = $this->createOAuthConnection($team, $user, $validated['client_id'], $minted['jti'], $requestedScopes);
        $orgToken->forceFill(['mcp_connection_id' => $connection->id])->saveQuietly();
        $refreshToken = $this->createRefreshToken($team, $user, $validated['client_id'], $requestedScopes, $connection);
        $auditLogger->recordForRequest(
            $request,
            AuditEventType::TokenCreated,
            $team,
            (string) $user->id,
            "token:{$orgToken->id}",
        );

        return response()->json([
            'access_token' => $minted['token'],
            'token_type' => 'Bearer',
            'expires_in' => max(0, $minted['expires_at']->timestamp - now()->timestamp),
            'scope' => implode(' ', $requestedScopes),
            'organization' => $team->slug,
            'refresh_token' => $refreshToken,
            'refresh_token_expires_in' => self::REFRESH_TOKEN_TTL_DAYS * 86400,
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /**
     * Revoke an OAuth access or refresh token without revealing whether the
     * supplied credential was known. This is intentionally idempotent per
     * RFC 7009 so clients can safely use it during logout and recovery.
     */
    public function revoke(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'token_type_hint' => ['nullable', 'string', 'in:access_token,refresh_token'],
            'client_id' => ['nullable', 'string', 'max:200'],
        ]);

        $rawToken = $validated['token'];
        $refreshToken = OAuthRefreshToken::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if ($refreshToken instanceof OAuthRefreshToken) {
            $connection = $refreshToken->mcpConnection;
            if ($refreshToken->revoked_at === null) {
                $refreshToken->forceFill(['revoked_at' => now()])->save();
                if ($connection instanceof McpConnection) {
                    $this->revokeConnection($connection);
                }
                $auditLogger->recordForRequest(
                    $request,
                    AuditEventType::TokenRevoked,
                    $refreshToken->team,
                    (string) $refreshToken->user_id,
                    "oauth_refresh_token:{$refreshToken->id}",
                    'oauth.revoke',
                );
            }

            return response()->json([], 200)->header('Cache-Control', 'no-store');
        }

        try {
            $claims = OrgJwtService::default()->verify($rawToken);
            $orgToken = OrgToken::query()->where('jti', $claims['jti'])->first();

            if ($orgToken instanceof OrgToken && $orgToken->revoked_at === null) {
                $orgToken->forceFill(['revoked_at' => now()])->save();
                RevocationWriter::default()->revoke($orgToken->jti, $orgToken->expires_at);
                if ($orgToken->mcpConnection instanceof McpConnection) {
                    $this->revokeConnection($orgToken->mcpConnection);
                } else {
                    $orgToken->team->mcpConnections()
                        ->where('credential_jti', $orgToken->jti)
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => now()]);
                }
                $auditLogger->recordForRequest(
                    $request,
                    AuditEventType::TokenRevoked,
                    $orgToken->team,
                    (string) $orgToken->user_id,
                    "token:{$orgToken->id}",
                    'oauth.revoke',
                );
            }
        } catch (\Throwable) {
            // RFC 7009 intentionally avoids credential enumeration.
        }

        return response()->json([], 200)->header('Cache-Control', 'no-store');
    }

    /** @param array{grant_type: string, refresh_token?: string, client_id: string} $validated */
    private function refresh(array $validated, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $rawRefreshToken = $validated['refresh_token'] ?? '';
        if ($rawRefreshToken === '') {
            return $this->tokenError('invalid_request', 'A refresh token is required.');
        }

        $refreshToken = DB::transaction(function () use ($rawRefreshToken, $validated): ?OAuthRefreshToken {
            $stored = OAuthRefreshToken::query()
                ->where('token_hash', hash('sha256', $rawRefreshToken))
                ->lockForUpdate()
                ->first();

            if ($stored === null || $stored->client_id !== $validated['client_id']) {
                return null;
            }

            if ($stored->revoked_at !== null) {
                $connection = $stored->mcpConnection()->lockForUpdate()->first();
                if ($connection instanceof McpConnection) {
                    $this->revokeConnection($connection);
                }

                return null;
            }

            if ($stored->isExpired()) {
                return null;
            }

            $connection = $stored->mcpConnection()->lockForUpdate()->first();
            if ($connection instanceof McpConnection && ($connection->isRevoked() || $connection->isExpired())) {
                return null;
            }

            $stored->forceFill(['revoked_at' => now(), 'last_used_at' => now()])->save();

            return $stored;
        });

        if (! $refreshToken instanceof OAuthRefreshToken) {
            return $this->tokenError('invalid_grant', 'The refresh token is invalid, expired, revoked, or issued to another client.');
        }

        $user = $refreshToken->user;
        $team = $refreshToken->team;
        $role = $user->teamRole($team);
        if (! $role instanceof TeamRole) {
            return $this->tokenError('invalid_grant', 'The authorization is no longer valid.');
        }

        $scopes = array_values(array_intersect($refreshToken->scopes, $this->scopesForRole($role)));
        if ($scopes === []) {
            return $this->tokenError('invalid_scope', 'The requested scope is no longer available to this account.');
        }

        try {
            $minted = OrgJwtService::default()->mint($team, $user, $role, 300, $scopes);
        } catch (\Throwable) {
            return $this->tokenError('server_error', 'The authorization server is not configured.');
        }

        $orgToken = OrgToken::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => 'MCP OAuth ('.$validated['client_id'].')',
            'jti' => $minted['jti'],
            'role' => $role,
            'last_four' => Str::substr($minted['token'], -4),
            'expires_at' => $minted['expires_at'],
        ]);
        $connection = $refreshToken->mcpConnection;
        if ($connection instanceof McpConnection) {
            $connection->forceFill([
                'credential_jti' => $minted['jti'],
                'scopes' => $scopes,
                'last_used_at' => now(),
            ])->saveQuietly();
        } else {
            $connection = $this->createOAuthConnection($team, $user, $validated['client_id'], $minted['jti'], $scopes);
        }
        $orgToken->forceFill(['mcp_connection_id' => $connection->id])->saveQuietly();
        $newRefreshToken = $this->createRefreshToken($team, $user, $validated['client_id'], $scopes, $connection);

        $auditLogger->recordForRequest(
            $request,
            AuditEventType::TokenCreated,
            $team,
            (string) $user->id,
            "token:{$orgToken->id}",
        );

        return response()->json([
            'access_token' => $minted['token'],
            'token_type' => 'Bearer',
            'expires_in' => max(0, $minted['expires_at']->timestamp - now()->timestamp),
            'scope' => implode(' ', $scopes),
            'organization' => $team->slug,
            'refresh_token' => $newRefreshToken,
            'refresh_token_expires_in' => self::REFRESH_TOKEN_TTL_DAYS * 86400,
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /** @param list<string> $scopes */
    private function createRefreshToken(Team $team, User $user, string $clientId, array $scopes, ?McpConnection $connection = null): string
    {
        $rawToken = Str::random(96);

        OAuthRefreshToken::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'mcp_connection_id' => $connection?->id,
            'client_id' => $clientId,
            'token_hash' => hash('sha256', $rawToken),
            'scopes' => $scopes,
            'expires_at' => now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        ]);

        return $rawToken;
    }

    /** @param list<string> $scopes */
    private function createOAuthConnection(Team $team, User $user, string $clientId, string $credentialJti, array $scopes): McpConnection
    {
        $clientName = OAuthClient::query()
            ->where('client_id', $clientId)
            ->value('client_name') ?? $clientId;
        $connection = new McpConnection;
        $connection->forceFill([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => $clientName.' MCP',
            'client_name' => $clientName,
            'transport' => 'streamable-http',
            'credential_jti' => $credentialJti,
            'scopes' => $scopes,
            'last_used_at' => now(),
        ])->save();

        return $connection;
    }

    private function revokeConnection(McpConnection $connection): void
    {
        $connection->forceFill(['revoked_at' => $connection->revoked_at ?? now()])->saveQuietly();
        $connection->oauthRefreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $connection->orgTokens()->whereNull('revoked_at')->get()->each(function (OrgToken $orgToken): void {
            $orgToken->forceFill(['revoked_at' => now()])->saveQuietly();
            RevocationWriter::default()->revoke($orgToken->jti, $orgToken->expires_at);
        });
    }

    /** @return array{client_id: string, redirect_uri: string, scope: string, state: string|null, code_challenge: string, code_challenge_method: string, team: string|null} */
    private function authorizationParameters(Request $request): array
    {
        $parameters = [
            'client_id' => $request->string('client_id')->toString(),
            'redirect_uri' => $request->string('redirect_uri')->toString(),
            'scope' => trim($request->string('scope')->toString()),
            'state' => $request->string('state')->toString() ?: null,
            'code_challenge' => $request->string('code_challenge')->toString(),
            'code_challenge_method' => $request->string('code_challenge_method')->toString(),
            'team' => $request->has('team') ? $request->string('team')->toString() : null,
        ];

        if ($request->string('response_type')->toString() !== 'code') {
            throw ValidationException::withMessages(['response_type' => 'Only the authorization code flow is supported.']);
        }

        if ($parameters['client_id'] === '' || $parameters['code_challenge'] === '' || $parameters['code_challenge_method'] !== 'S256') {
            throw ValidationException::withMessages(['code_challenge' => 'A S256 PKCE challenge is required.']);
        }

        $client = OAuthClient::query()->where('client_id', $parameters['client_id'])->first();
        $isBuiltInCli = $parameters['client_id'] === 'artfct-cli';

        if (! $isBuiltInCli && (! $client instanceof OAuthClient || ! in_array($parameters['redirect_uri'], $client->redirect_uris, true))) {
            throw ValidationException::withMessages(['redirect_uri' => 'The redirect URI is not allowed.']);
        }

        if (! $this->isAllowedRedirectUri($parameters['redirect_uri'])) {
            throw ValidationException::withMessages(['redirect_uri' => 'The redirect URI is not allowed.']);
        }

        $scopes = array_values(array_filter(explode(' ', $parameters['scope'])));
        if ($scopes === [] || array_diff($scopes, self::SUPPORTED_SCOPES) !== []) {
            throw ValidationException::withMessages(['scope' => 'The requested scope is not supported.']);
        }

        return $parameters;
    }

    private function teamFor(User $user, ?string $team): ?Team
    {
        if ($team !== null && $team !== '') {
            return $user->teams()->where('teams.slug', $team)->first();
        }

        return $user->currentTeam ?? $user->personalTeam() ?? $user->teams()->first();
    }

    private function isAllowedRedirectUri(string $redirectUri): bool
    {
        $parts = parse_url($redirectUri);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            return false;
        }

        if ($parts['scheme'] === 'https') {
            return true;
        }

        return $parts['scheme'] === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private function codeKey(string $code): string
    {
        return 'oauth:authorization-code:'.hash('sha256', $code);
    }

    /**
     * Bind the approval form to the exact OAuth request shown on the consent
     * screen, preventing client, redirect, scope, or PKCE parameter tampering.
     * The token contains no request data and is safe to render as a form value.
     *
     * @param  array{client_id: string, redirect_uri: string, scope: string, state: string|null, code_challenge: string, code_challenge_method: string, team: string|null}  $parameters
     */
    private function consentToken(array $parameters): string
    {
        $payload = json_encode([
            'client_id' => $parameters['client_id'],
            'redirect_uri' => $parameters['redirect_uri'],
            'scope' => $parameters['scope'],
            'state' => $parameters['state'],
            'code_challenge' => $parameters['code_challenge'],
            'code_challenge_method' => $parameters['code_challenge_method'],
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @return list<string> */
    private function scopesForRole(TeamRole $role): array
    {
        return match ($role) {
            TeamRole::Admin, TeamRole::Member => self::SUPPORTED_SCOPES,
            TeamRole::Viewer => ['artifacts:read', 'collections:read', 'usage:read'],
        };
    }

    /** @param array<string, string> $parameters */
    /**
     * Send the browser to the client's redirect URI. The consent page submits
     * through Inertia's XHR, and an XHR cannot follow a redirect to another
     * origin (CORS), so Inertia requests get a 409 location visit instead.
     */
    private function redirectWith(string $redirectUri, array $parameters): SymfonyResponse
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return Inertia::location($redirectUri.$separator.http_build_query($parameters));
    }

    private function tokenError(string $error, string $description): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    private function registrationError(string $description): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_client_metadata',
            'error_description' => $description,
        ], 400)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    private function issuer(): string
    {
        $issuer = rtrim((string) (config('services.oauth.issuer') ?: config('services.org_jwt.issuer')), '/');

        return $issuer !== '' ? $issuer : url('/');
    }
}
