<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\HttpArtifactDirectory;
use App\Services\Auth\OrgJwtService;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;

function signedDirectoryFor(User $user, Team $team): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config(['services.org_jwt.private_key' => $pem, 'services.org_jwt.kid' => 'k1', 'services.worker.org_token' => 'legacy-static-token']);
    Http::fake(['worker.test/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);
    test()->actingAs($user);

    return [new HttpArtifactDirectory('https://worker.test'), new OrgJwtService($pem, 'k1')];
}

test('directory_calls_the_worker_with_a_token_scoped_to_the_team_and_role', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $member = memberOfTeam($team, TeamRole::Member);
    [$directory, $service] = signedDirectoryFor($member, $team);

    $directory->listArtifacts('acme');

    Http::assertSent(function (Request $request) use ($service) {
        $token = str_replace('Bearer ', '', $request->header('Authorization')[0]);
        $claims = JWT::decode($token, JWK::parseKeySet(['keys' => [$service->jwk()]]));

        return $claims->org_id === 'acme' && $claims->role === 'member' && $token !== 'legacy-static-token';
    });
});

test('a_user_of_another_team_gets_no_credential_for_this_team', function () {
    $acme = Team::factory()->create(['slug' => 'acme']);
    Team::factory()->create(['slug' => 'other']);
    $outsider = memberOfTeam(Team::query()->where('slug', 'other')->first(), TeamRole::Admin);
    [$directory] = signedDirectoryFor($outsider, $acme);

    expect(fn () => $directory->listArtifacts('acme'))->toThrow(HttpResponseException::class);

    Http::assertNothingSent();
});

test('the_static_org_token_is_never_used_as_a_fallback', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $member = memberOfTeam($team, TeamRole::Member);
    [$directory] = signedDirectoryFor($member, $team);

    $directory->listArtifacts('acme');

    Http::assertNotSent(fn (Request $request) => str_contains($request->header('Authorization')[0], 'legacy-static-token'));
});

test('an_explicit_bearer_token_from_an_api_caller_is_forwarded', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $member = memberOfTeam($team, TeamRole::Member);
    [$directory] = signedDirectoryFor($member, $team);
    request()->headers->set('Authorization', 'Bearer caller-token');

    $directory->listArtifacts('acme');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer caller-token'));
});

test('no_signing_key_fails_closed_instead_of_falling_back', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $member = memberOfTeam($team, TeamRole::Member);
    config(['services.org_jwt.private_key' => null, 'services.org_jwt.kid' => null, 'services.worker.org_token' => 'legacy-static-token']);
    Http::fake();
    test()->actingAs($member);

    expect(fn () => (new HttpArtifactDirectory('https://worker.test'))->listArtifacts('acme'))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});
