<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
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

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

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

function something()
{
    // ..
}

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
        'services.org_jwt.worker_base_url' => 'https://worker.test',
        'services.org_jwt.jwks_write_secret' => 'jwks-secret',
    ]);
}
