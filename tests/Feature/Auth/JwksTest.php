<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

test('jwks_endpoint_serves_the_public_key_only', function () {
    configureSigning(testSigningKey());

    $response = test()->getJson('/.well-known/jwks.json')->assertOk();

    $key = $response->json('keys.0');
    expect($key)->toHaveKeys(['kty', 'alg', 'kid', 'n', 'e'])
        ->and($key['kid'])->toBe('staging-2026-09')
        ->and(array_keys($key))->not->toContain('d', 'p', 'q', 'dp', 'dq', 'qi');
    expect($response->getContent())->not->toContain('PRIVATE KEY');
});

test('jwks_endpoint_is_404_when_no_key_is_configured', function () {
    config(['services.org_jwt.private_key' => null, 'services.org_jwt.kid' => null]);

    test()->getJson('/.well-known/jwks.json')->assertNotFound();
});

test('minted_token_verifies_against_the_published_jwk', function () {
    configureSigning(testSigningKey());
    $team = Team::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $token = OrgJwtService::default()->mint($team, $user, TeamRole::Admin, 300)['token'];

    $keys = JWK::parseKeySet(['keys' => [OrgJwtService::default()->jwk()]]);
    $claims = JWT::decode($token, $keys);

    expect($claims->org_id)->toBe('acme')->and($claims->role)->toBe('admin');
});

test('publish_posts_the_jwks_with_the_secret', function () {
    configureSigning(testSigningKey());
    Http::fake(['worker.test/*' => Http::response(['keys' => 1])]);

    test()->artisan('auth:publish-jwks')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://worker.test/v1/internal/jwks'
        && $request->hasHeader('Authorization', 'Bearer jwks-secret')
        && $request['keys'][0]['kid'] === 'staging-2026-09');
});

test('publish_keeps_a_previous_key_live_during_rotation', function () {
    configureSigning(testSigningKey());
    Http::fake(['worker.test/*' => Http::response(['keys' => 2])]);
    $old = ['kty' => 'RSA', 'kid' => 'old-key', 'n' => 'abc', 'e' => 'AQAB'];
    $path = tempnam(sys_get_temp_dir(), 'jwks');
    file_put_contents($path, json_encode([$old]));

    test()->artisan('auth:publish-jwks', ['--extra-jwks' => $path])->assertSuccessful();

    Http::assertSent(fn (Request $request) => collect($request['keys'])->pluck('kid')->all() === ['staging-2026-09', 'old-key']);
    unlink($path);
});

test('publish_skips_quietly_when_not_configured', function () {
    config(['services.org_jwt.private_key' => null, 'services.org_jwt.worker_base_url' => null]);
    Http::fake();

    test()->artisan('auth:publish-jwks')->assertSuccessful();

    Http::assertNothingSent();
});

test('publish_fails_when_the_worker_refuses', function () {
    configureSigning(testSigningKey());
    Http::fake(['worker.test/*' => Http::response('no', 401)]);

    test()->artisan('auth:publish-jwks')->assertFailed();
});

test('mint_fails_closed_without_a_key', function () {
    config(['services.org_jwt.private_key' => null, 'services.org_jwt.kid' => null]);

    expect(fn () => OrgJwtService::default())->toThrow(RuntimeException::class);
});
