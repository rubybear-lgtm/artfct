<?php

use App\Enums\AuthMode;
use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;
use App\Services\Identity\AuthModeTransitioner;
use App\Services\Identity\AuthModeTransitionException;
use App\Services\Identity\DnsResolverContract;
use App\Services\Identity\DomainVerifier;
use App\Services\Identity\FakeDnsResolver;

/**
 * Exchange a code with the fake AuthKit client, exactly as the real
 * /authenticate route does.
 */
function authenticateWithProfile(AuthKitProfile $profile): void
{
    $code = FakeAuthKitClient::codeFor($profile);

    $response = test()->get(route('authenticate', ['code' => $code]));

    $response->assertRedirect();
}

function googleProfile(string $email, string $name = 'Ada Lovelace'): AuthKitProfile
{
    return new AuthKitProfile(
        externalId: 'google-'.md5($email),
        provider: 'GoogleOAuth',
        email: $email,
        emailVerified: true,
        firstName: $name,
        lastName: null,
        avatar: null,
    );
}

function passkeyProfile(string $email, string $name = 'Ada Lovelace'): AuthKitProfile
{
    return new AuthKitProfile(
        externalId: 'passkey-'.md5($email),
        provider: 'Passkey',
        email: $email,
        emailVerified: true,
        firstName: $name,
        lastName: null,
        avatar: null,
    );
}

test('user_registers_and_gets_personal_team', function () {
    authenticateWithProfile(googleProfile('ada@example.com'));

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    expect($user->personalTeam())->not->toBeNull();
    expect($user->personalTeam()->is_personal)->toBeTrue();
    expect($user->teamRole($user->personalTeam()))->toBe(TeamRole::Admin);
    expect($user->current_team_id)->toBe($user->personalTeam()->id);
});

test('user_creates_and_switches_orgs', function () {
    authenticateWithProfile(googleProfile('bea@example.com'));
    $user = User::where('email', 'bea@example.com')->firstOrFail();

    $response = test()->actingAs($user)->post('/settings/teams', ['name' => 'Acme Corp']);

    $secondTeam = Team::where('name', 'Acme Corp')->firstOrFail();
    $response->assertRedirect(route('teams.edit', ['team' => $secondTeam->slug]));

    $user->refresh();
    expect($user->current_team_id)->toBe($secondTeam->id);
    expect($user->teams()->count())->toBe(2);

    $personalTeam = $user->personalTeam();
    test()->actingAs($user)->post("/settings/teams/{$personalTeam->slug}/switch")->assertRedirect();

    $user->refresh();
    expect($user->current_team_id)->toBe($personalTeam->id);
});

test('invitation_flow_completes', function () {
    authenticateWithProfile(googleProfile('carl@example.com'));
    $inviter = User::where('email', 'carl@example.com')->firstOrFail();

    $team = test()->actingAs($inviter)
        ->post('/settings/teams', ['name' => 'Widgets Inc'])
        ->assertRedirect();

    $team = Team::where('name', 'Widgets Inc')->firstOrFail();

    test()->actingAs($inviter)
        ->post("/settings/teams/{$team->slug}/invitations", [
            'email' => 'dana@example.com',
            'role' => 'member',
        ])
        ->assertRedirect();

    $invitation = $team->invitations()->where('email', 'dana@example.com')->firstOrFail();

    test()->post('/logout');
    authenticateWithProfile(googleProfile('dana@example.com', 'Dana'));
    $invitee = User::where('email', 'dana@example.com')->firstOrFail();

    test()->actingAs($invitee)
        ->post("/invitations/{$invitation->code}/accept")
        ->assertRedirect(route('dashboard'));

    expect($invitee->fresh()->belongsToTeam($team))->toBeTrue();
    expect($invitation->fresh()->isAccepted())->toBeTrue();
});

test('google_and_passkey_resolve_to_one_user', function () {
    authenticateWithProfile(googleProfile('eve@example.com'));
    $viaGoogle = User::where('email', 'eve@example.com')->firstOrFail();

    test()->post('/logout');

    authenticateWithProfile(passkeyProfile('eve@example.com'));
    $viaPasskey = User::where('email', 'eve@example.com')->firstOrFail();

    expect($viaGoogle->id)->toBe($viaPasskey->id);
    expect(ExternalIdentity::where('user_id', $viaGoogle->id)->count())->toBe(2);
});

test('second_provider_creates_second_external_identity', function () {
    authenticateWithProfile(googleProfile('frank@example.com'));
    $user = User::where('email', 'frank@example.com')->firstOrFail();

    expect($user->externalIdentities()->count())->toBe(1);

    test()->post('/logout');
    authenticateWithProfile(passkeyProfile('frank@example.com'));

    expect($user->externalIdentities()->count())->toBe(2);
    expect($user->externalIdentities()->pluck('provider')->sort()->values()->all())
        ->toBe(['GoogleOAuth', 'Passkey']);
});

test('org_slug_with_double_hyphen_rejected', function () {
    authenticateWithProfile(googleProfile('greta@example.com'));
    $user = User::where('email', 'greta@example.com')->firstOrFail();

    $response = test()->actingAs($user)->post('/settings/teams', [
        'name' => 'Acme',
        'slug' => 'acme--corp',
    ]);

    $response->assertSessionHasErrors('slug');
    expect(Team::where('slug', 'acme--corp')->exists())->toBeFalse();
});

test('org_slug_non_ascii_rejected', function () {
    authenticateWithProfile(googleProfile('harun@example.com'));
    $user = User::where('email', 'harun@example.com')->firstOrFail();

    $response = test()->actingAs($user)->post('/settings/teams', [
        'name' => 'Acme',
        'slug' => 'acmé',
    ]);

    $response->assertSessionHasErrors('slug');
    expect(Team::where('slug', 'acmé')->exists())->toBeFalse();
});

test('org_slug_over_length_rejected', function () {
    authenticateWithProfile(googleProfile('ivy@example.com'));
    $user = User::where('email', 'ivy@example.com')->firstOrFail();

    $tooLong = str_repeat('a', 25);

    $response = test()->actingAs($user)->post('/settings/teams', [
        'name' => 'Acme',
        'slug' => $tooLong,
    ]);

    $response->assertSessionHasErrors('slug');
    expect(Team::where('slug', $tooLong)->exists())->toBeFalse();
});

test('auth_mode_defaults_to_authkit', function () {
    $team = Team::factory()->create();

    expect($team->auth_mode)->toBe(AuthMode::AuthKit);
});

test('dual_requires_verified_domain', function () {
    $team = Team::factory()->create();
    $transitioner = app(AuthModeTransitioner::class);

    expect(fn () => $transitioner->transition($team, AuthMode::Dual))
        ->toThrow(AuthModeTransitionException::class);

    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit);

    $team->domains()->create([
        'domain' => 'acme.com',
        'verified_at' => now(),
    ]);

    $transitioner->transition($team, AuthMode::Dual);

    expect($team->fresh()->auth_mode)->toBe(AuthMode::Dual);
});

test('polis_requires_admin_with_polis_identity', function () {
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $team->domains()->create(['domain' => 'acme.com', 'verified_at' => now()]);

    $transitioner = app(AuthModeTransitioner::class);

    try {
        $transitioner->transition($team, AuthMode::Polis);
        test()->fail('Expected AuthModeTransitionException.');
    } catch (AuthModeTransitionException $exception) {
        expect($exception->getMessage())->toContain('admin')->toContain('Polis');
    }

    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit);

    ExternalIdentity::factory()->for($admin)->polis()->create(['verified_at' => now()]);

    $transitioner->transition($team, AuthMode::Polis);

    expect($team->fresh()->auth_mode)->toBe(AuthMode::Polis);
});

test('domain_verification_requires_dns_txt', function () {
    $team = Team::factory()->create();
    $domain = $team->domains()->create(['domain' => 'acme.com']);

    /** @var FakeDnsResolver $resolver */
    $resolver = app(DnsResolverContract::class);
    $verifier = new DomainVerifier($resolver);

    expect($verifier->verify($domain))->toBeFalse();
    expect($domain->fresh()->verified_at)->toBeNull();

    $resolver->seed($domain->txtRecordName(), $domain->verification_token);

    expect($verifier->verify($domain))->toBeTrue();
    expect($domain->fresh()->verified_at)->not->toBeNull();

    // Re-verification after the record is removed.
    $resolver->clear($domain->txtRecordName());
    expect($verifier->verify($domain))->toBeFalse();
    expect($domain->fresh()->verified_at)->toBeNull();

    $resolver->seed($domain->txtRecordName(), $domain->verification_token);
    expect($verifier->verify($domain))->toBeTrue();
});
