<?php

use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\Polis\FakePolisClient;
use App\Services\Polis\RealPolisClient;
use Illuminate\Support\Facades\Http;

function polisOrg(): array
{
    $team = Team::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['email' => 'ada@acme.com']);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return [$team, $user];
}

test('starting_sso_stores_a_state_and_redirects_to_polis', function () {
    [$team] = polisOrg();

    $response = test()->get(route('sso.login', $team->slug))->assertRedirect();
    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($location)->toStartWith('https://polis.fake/api/oauth/authorize')
        ->and($query['tenant'])->toBe('acme')
        ->and(session('polis_state'))->toBe($query['state']);
});

test('the_callback_rejects_a_missing_or_wrong_state', function () {
    [$team, $user] = polisOrg();
    $profile = new AuthKitProfile('ext-1', 'polis', 'ada@acme.com', true, 'Ada', null, null);
    $code = FakePolisClient::codeFor($profile, 'acme', 'artfct');

    test()->get(route('sso.authenticate', ['team' => 'acme', 'code' => $code]))->assertForbidden();
    test()->withSession(['polis_state' => 'good'])->get(route('sso.authenticate', ['team' => 'acme', 'code' => $code, 'state' => 'evil']))->assertForbidden();
    test()->assertGuest();
});

test('a_matching_state_signs_in_the_linked_member_without_a_duplicate_user', function () {
    [$team, $user] = polisOrg();
    $profile = new AuthKitProfile('ext-1', 'polis', 'ada@acme.com', true, 'Ada', null, null);
    $code = FakePolisClient::codeFor($profile, 'acme', 'artfct');

    test()->withSession(['polis_state' => 'good'])
        ->get(route('sso.authenticate', ['team' => 'acme', 'code' => $code, 'state' => 'good']))
        ->assertRedirect();

    test()->assertAuthenticatedAs($user);
    expect(User::query()->where('email', 'ada@acme.com')->count())->toBe(1)
        ->and(ExternalIdentity::query()->where('user_id', $user->id)->where('provider', 'polis')->exists())->toBeTrue();
});

test('the_state_is_single_use', function () {
    [$team] = polisOrg();
    $profile = new AuthKitProfile('ext-1', 'polis', 'ada@acme.com', true, 'Ada', null, null);
    $code = FakePolisClient::codeFor($profile, 'acme', 'artfct');

    test()->withSession(['polis_state' => 'once'])->get(route('sso.authenticate', ['team' => 'acme', 'code' => $code, 'state' => 'once']));
    auth()->logout();

    test()->get(route('sso.authenticate', ['team' => 'acme', 'code' => $code, 'state' => 'once']))->assertForbidden();
});

test('the_real_client_exchanges_the_code_and_reads_the_profile', function () {
    config(['services.polis' => ['base_url' => 'https://polis.test', 'api_key' => 'k', 'client_secret_verifier' => 'verifier']]);
    Http::fake([
        'polis.test/api/oauth/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer']),
        'polis.test/api/oauth/userinfo' => Http::response(['id' => 'u-1', 'email' => 'Ada@Acme.com', 'firstName' => 'Ada', 'lastName' => 'L', 'requested' => ['tenant' => 'acme']]),
    ]);

    $profile = (new RealPolisClient)->authenticateWithCode('the-code', 'acme', 'artfct');

    expect($profile->email)->toBe('ada@acme.com')->and($profile->externalId)->toBe('u-1')->and($profile->provider)->toBe('polis');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/oauth/token')
        && $request['client_id'] === 'tenant=acme&product=artfct'
        && $request['client_secret'] === 'verifier'
        && $request['code'] === 'the-code');
});

test('the_real_client_refuses_a_code_issued_for_another_tenant', function () {
    config(['services.polis' => ['base_url' => 'https://polis.test', 'client_secret_verifier' => 'v']]);
    Http::fake([
        'polis.test/api/oauth/token' => Http::response(['access_token' => 'tok']),
        'polis.test/api/oauth/userinfo' => Http::response(['id' => 'u-1', 'email' => 'x@evil.com', 'requested' => ['tenant' => 'globex']]),
    ]);

    expect(fn () => (new RealPolisClient)->authenticateWithCode('c', 'acme', 'artfct'))->toThrow(RuntimeException::class, 'different tenant');
});

test('the_real_client_fails_closed_when_polis_rejects_the_code_or_is_unconfigured', function () {
    config(['services.polis' => ['base_url' => 'https://polis.test', 'client_secret_verifier' => 'v']]);
    Http::fake(['polis.test/api/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => (new RealPolisClient)->authenticateWithCode('bad', 'acme', 'artfct'))->toThrow(RuntimeException::class);

    config(['services.polis' => []]);
    expect(fn () => (new RealPolisClient)->authorizationUrl('acme', 'artfct', 'https://x/cb', 's'))->toThrow(RuntimeException::class);
});
