<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use App\Services\WorkerEvents\WorkerEventSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Creates a user with the given role on the given team. Shared across
 * Identity/Teams/Console feature tests (specs 06-08).
 */
function memberOfTeam(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);

    return $user;
}

/**
 * POSTs a Slack slash-command payload to the Slack route, signed the way
 * Slack signs it (spec 15). Sets the signing secret in config unless told
 * not to, so callers can exercise the unset-secret path.
 *
 * @param  array<string, string>  $payload
 * @param  array<string, string|null>  $headerOverrides  A null value drops that header.
 */
function postSlackCommand(array $payload, ?int $timestamp = null, string $signingSecret = 'slack-test-secret', array $headerOverrides = [], bool $configureSecret = true): TestResponse
{
    if ($configureSecret) {
        config(['services.slack.signing_secret' => 'slack-test-secret']);
    }

    $body = json_encode($payload);
    $timestamp ??= now()->timestamp;
    $headers = array_merge([
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SLACK_SIGNATURE' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $signingSecret),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $headerOverrides);

    return test()->call('POST', '/api/slack/commands', [], [], [], array_filter($headers, fn ($value) => $value !== null), $body);
}

/**
 * POSTs a correctly signed Worker event to `/internal/worker-events`.
 *
 * @param  array<string, mixed>  $overrides
 */
function postWorkerEvent(array $overrides = [], ?int $timestamp = null, ?string $secret = 'test-secret'): TestResponse
{
    $body = json_encode(array_merge([
        'id' => (string) Str::uuid(),
        'type' => 'artifact.created',
        'org_id' => 'acme',
        'occurred_at' => now()->toRfc3339String(),
        'data' => [],
    ], $overrides));
    $timestamp ??= time();

    return test()->call('POST', '/internal/worker-events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ARTFCT_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_ARTFCT_SIGNATURE' => WorkerEventSignature::sign($secret ?? '', $timestamp, $body),
    ], $body);
}

/**
 * Shared helpers for tests that need the org-token signing key.
 */
function testSigningKey(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return $pem;
}

function configureSigning(string $pem, string $kid = 'staging-2026-09'): void
{
    config([
        'services.org_jwt.private_key' => $pem,
        'services.org_jwt.kid' => $kid,
        'services.org_jwt.issuer' => 'https://artfct.dev',
        'services.org_jwt.audience' => 'artfct-engine',
        'services.org_jwt.worker_base_url' => 'https://worker.test',
        'services.org_jwt.jwks_write_secret' => 'jwks-secret',
    ]);
}

function oauthParameters(string $challenge, array $overrides = []): array
{
    $parameters = array_merge([
        'response_type' => 'code',
        'client_id' => 'artfct-cli',
        'redirect_uri' => 'http://127.0.0.1:43123/callback',
        'scope' => 'artifacts:read artifacts:deploy',
        'state' => 'state-123',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ], $overrides);

    $parameters['consent_token'] = oauthConsentToken($parameters);

    return $parameters;
}

function oauthConsentToken(array $parameters): string
{
    $payload = json_encode([
        'client_id' => $parameters['client_id'],
        'redirect_uri' => $parameters['redirect_uri'],
        'scope' => $parameters['scope'],
        'state' => $parameters['state'] ?? null,
        'code_challenge' => $parameters['code_challenge'],
        'code_challenge_method' => $parameters['code_challenge_method'],
    ], JSON_THROW_ON_ERROR);

    return hash_hmac('sha256', $payload, (string) config('app.key'));
}

function oauthChallenge(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

function remoteMcpToken(Team $team, TeamRole $role = TeamRole::Admin): string
{
    configureSigning(testSigningKey());

    $user = memberOfTeam($team, $role);

    return OrgJwtService::default()->mint($team, $user, $role)['token'];
}

/*
 * The isolated-origin link fixtures live here rather than in `ConsoleTest.php`
 * for the same reason `configureOrgJwt()` does: the MCP tool tests mint through
 * the same `ArtifactAccessLink` the console does, so a verifier defined in one
 * of those files would leave it unrunnable on its own and could quietly become
 * two verifiers that disagree.
 */

/** Shared signing secret for the isolated-origin link tests. */
const ARTIFACT_LINK_SECRET = 'artifact-link-test-secret';

/** A 32-character lowercase hex public artifact id, the only shape the isolated hostname accepts. */
const ARTIFACT_LINK_ID = '0123456789abcdef0123456789abcdef';

function configureArtifactLinks(string $secret = ARTIFACT_LINK_SECRET, string $suffix = '.artfct.dev'): void
{
    config([
        'services.artifact_access.token_secret' => $secret,
        'services.artifact_access.origin_suffix' => $suffix,
        'services.artifact_access.token_ttl_minutes' => 60,
    ]);
}

/**
 * Stands in for the Worker's `verify_access_token` (`backend/src/lib.rs`),
 * re-deriving the HMAC from the documented wire form
 * `<artifact_id>.<expires_at_unix>.<hmac_hex>` instead of trusting the
 * minter — a disagreement about the token has to fail here, not pass because
 * one side asserted its own output.
 */
function artifactTokenVerifies(string $token, string $artifactId, string $secret, int $now): bool
{
    $parts = explode('.', $token, 3);

    if (count($parts) !== 3 || ! hash_equals($parts[0], $artifactId)) {
        return false;
    }

    $expiresAt = $parts[1];

    if (! ctype_digit($expiresAt) || $now >= (int) $expiresAt) {
        return false;
    }

    return hash_equals($parts[2], hash_hmac('sha256', "{$parts[0]}.{$expiresAt}", $secret));
}

/*
 * The RSA-2048 fixture keypair and `configureOrgJwt()` live here rather than in
 * one test file: `configureOrgJwt()` is used by both `Teams/OrgTokenTest.php`
 * and `ConsoleTest.php`, so a file-scoped definition made `ConsoleTest.php`
 * unrunnable on its own (the function only existed when the whole suite was
 * loaded).
 */

/**
 * Test-only RSA-2048 keypair, same one used by the backend's Rust test
 * fixtures (`backend/src/lib.rs`'s `TEST_KEY_A_DER_B64`, PEM form here).
 * Generated with `openssl genrsa 2048`; used only to sign fixture JWTs.
 */
const ORG_JWT_TEST_PRIVATE_KEY = <<<'PEM'
-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEAuJKelmXQyzS9BeaUdOIEfv1TSowF0uWjK9tw05G5/9eFWx/s
b4RNFKXk4ERWK91DStR1UKy1VeYiyd/w/Bj/bNylKQL0sox4iZpQH8ZN6oBK0qPO
Np5WSJvAIW5VEdQCDh/nDV1rV2Zg6E0W1gXoOaRgT0Dqu+Qd0vURFNqWBmK9gWN7
BkgU963RvMYHao5apdWn1mcWP3+E6eXaoXmp0V7MpRLTphJz8mlsyr6U/NdPjVgw
Zg5jzttouNJtcFLlvwNaBXuMNlivBnjsbBp5nkmOSzeT9a7m3OvKbinWO+ffL3xW
LWQmHGPOyBnfk2o3tx9n6GMch0KrAg5S7luEKwIDAQABAoIBADCqeCYvsl3iCfUE
VyB6d7UEFnIReXeiFOP7eERQqDpNGVxtjmnY+Hn5Q9/eJNpr/NI+MrCS2T1M8N9J
rMDL1o1doC6wGNT7NM0TYwz9vI2YRiJEDptYJGgAqSgnb0bEH8aZotJjT2o8FFEs
AllsNU79iGddNodUHokBFP/qoqQL8iW4pEmvjDRWvZhLJ+9/yj8cgMMwEwCmmjgM
w5T+Ag2+A0LN5JKYrs/Lk5sk1P0Bj008NxzN5DOmDHli4DBbSgBS08UQz/vzFKSd
BXfrvAv3n33JCJt+r3OW1WI13kajtWocErAjmxbwxMn85+/A3eqN6sjpCSrLvqDg
b4mCcAECgYEA5IWGFOsNypnTUlqM9uUCT2uIo0av6VocHW+SXe8Z/EiOs12jFJSB
NPU+jY4QYkn5DNKUhqn0Gdj/xJjTNrQZAL2SkIWdOnc3u3rbwrLg6JVUzmoVyInb
so/EnDM4u2t3BHgxN6cwLFNhf104KBxrty+2XO3r5mHSCtdLwi+3SFECgYEAzsQ9
A3bnejDF2MLN65+BtPZohBeGfbcGFm5e7fl2F42Nefph45Co7cvyaCUWursRR0BI
VuAO5W8Rrwl88EmRNTBrv6n+Ppg1kJZBhG99EDWzobISh4+/IYqwh3WPcDME0u0U
LJDGxbfERRDIUz9lpc/EsnZhIuO1Og3a+V4jYbsCgYB/WVGxUpRq9XJokIHCDTlO
XRTWOMxLdKX6WXTt2BNZHm430tTQ4Tln88uaQzMqMyMRXEDdEtUvmlhejPQXpiHQ
4dRNqchHDq0GU58oT1s7Ag0ywrfE+95tEeV1Tq4s8+RtnzV+WDNmYEkTGzXyVHRK
r9Im04gE6TqORBC59LFlIQKBgHdUfB4GvpsfkN+DthI5UUNePn2VkjH1sha6BiFz
qnr3X+I45cvPDh+HZ9RBK3gDRHqJl/ZDg3VYf600XZ3T53D6DAVml2wKrkdO4GsN
aPE0/QHh4p3IETfLcgwLhgfr+em9l7oMqBst7qEpiWO6H/DtEwkoVvFq14m0u17V
vLfHAoGBAMj9sq9lMfFzTk+kthAickPoej53srNcHbg/byzejo94ZltdqhU9FbKS
Dorfdqh91T//TrDCCfClLk/AnptCzE0NlevQOwLsJwvBuaTIGZ3rlxa9NFgPLtU+
e/YuZBI1/353WWyP8mQ5W+vevP4t5RnZdnUd3+iqg/cjJrmdzm/R
-----END RSA PRIVATE KEY-----
PEM;

const ORG_JWT_TEST_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuJKelmXQyzS9BeaUdOIE
fv1TSowF0uWjK9tw05G5/9eFWx/sb4RNFKXk4ERWK91DStR1UKy1VeYiyd/w/Bj/
bNylKQL0sox4iZpQH8ZN6oBK0qPONp5WSJvAIW5VEdQCDh/nDV1rV2Zg6E0W1gXo
OaRgT0Dqu+Qd0vURFNqWBmK9gWN7BkgU963RvMYHao5apdWn1mcWP3+E6eXaoXmp
0V7MpRLTphJz8mlsyr6U/NdPjVgwZg5jzttouNJtcFLlvwNaBXuMNlivBnjsbBp5
nkmOSzeT9a7m3OvKbinWO+ffL3xWLWQmHGPOyBnfk2o3tx9n6GMch0KrAg5S7luE
KwIDAQAB
-----END PUBLIC KEY-----
PEM;

function configureOrgJwt(): void
{
    config([
        'services.org_jwt.private_key' => ORG_JWT_TEST_PRIVATE_KEY,
        'services.org_jwt.kid' => 'test-kid',
        'services.org_jwt.worker_base_url' => 'https://worker.test',
        'services.org_jwt.revocation_write_secret' => 'test-revocation-secret',
    ]);
}
