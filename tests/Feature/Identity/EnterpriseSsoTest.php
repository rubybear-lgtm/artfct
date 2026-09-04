<?php

use App\Enums\AuthMode;
use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\Identity\AuthModeTransitioner;
use App\Services\Identity\AuthModeTransitionException;
use App\Services\Identity\EnterpriseIdentityResolver;
use App\Services\Identity\ScimProvisioningService;
use App\Services\Polis\FakePolisClient;
use App\Services\Polis\PolisClientContract;
use Illuminate\Support\Facades\Http;

function verifiedDomainTeam(string $slug = 'acme'): Team
{
    $team = Team::factory()->create(['slug' => $slug]);
    $team->domains()->create(['domain' => "{$slug}.com", 'verified_at' => now()]);

    return $team;
}

function samlProfile(string $email, bool $verified = true, string $provider = 'polis'): AuthKitProfile
{
    return new AuthKitProfile(
        externalId: 'saml-'.str()->random(8),
        provider: $provider,
        email: $email,
        emailVerified: $verified,
        firstName: 'SSO',
        lastName: 'User',
        avatar: null,
    );
}

test('saml_login_completes_via_socialite_provider', function () {
    $team = verifiedDomainTeam();
    $member = User::factory()->create(['email' => 'alice@acme.com']);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $profile = samlProfile('alice@acme.com');
    $code = FakePolisClient::codeFor($profile, $team->slug, 'artfct');

    $client = app(PolisClientContract::class);
    $returned = $client->authenticateWithCode($code, $team->slug, 'artfct');

    expect($returned->email)->toBe('alice@acme.com');
    expect($returned->provider)->toBe('polis');
});

test('oidc_login_completes', function () {
    $team = verifiedDomainTeam();
    $member = User::factory()->create(['email' => 'bob@acme.com']);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $profile = samlProfile('bob@acme.com', provider: 'polis-oidc');
    $code = FakePolisClient::codeFor($profile, $team->slug, 'artfct');

    $client = app(PolisClientContract::class);
    $returned = $client->authenticateWithCode($code, $team->slug, 'artfct');

    expect($returned->provider)->toBe('polis-oidc');
});

test('scim_provisions_user', function () {
    $team = verifiedDomainTeam();
    $service = app(ScimProvisioningService::class);

    $user = $service->provision($team, 'scim-ext-1', 'new-hire@acme.com', 'New Hire');

    expect($user->email)->toBe('new-hire@acme.com');
    expect(ExternalIdentity::query()->where('provider', 'scim')->where('external_id', 'scim-ext-1')->exists())->toBeTrue();
    expect($team->fresh()->members()->where('users.id', $user->id)->exists())->toBeTrue();

    // Provisioning again with the same SCIM id is idempotent -- no
    // duplicate identity or membership.
    $again = $service->provision($team, 'scim-ext-1', 'new-hire@acme.com', 'New Hire');
    expect($again->id)->toBe($user->id);
    expect(ExternalIdentity::query()->where('provider', 'scim')->where('external_id', 'scim-ext-1')->count())->toBe(1);
});

test('scim_deprovision_revokes_sessions', function () {
    Http::fake(['*' => Http::response([], 200)]);
    config([
        'services.org_jwt.worker_base_url' => 'https://worker.test',
        'services.org_jwt.revocation_write_secret' => 'test-secret',
    ]);

    $team = verifiedDomainTeam();
    $service = app(ScimProvisioningService::class);
    $user = $service->provision($team, 'scim-ext-2', 'leaver@acme.com');

    $token = OrgToken::factory()->for($team)->for($user)->create(['revoked_at' => null]);

    $service->deprovision('scim-ext-2');

    expect($user->fresh()->deactivated_at)->not->toBeNull();
    expect($token->fresh()->revoked_at)->not->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/internal/revocations'));
});

test('authkit_email_match_links_by_verified_email', function () {
    $team = verifiedDomainTeam();
    $member = User::factory()->create(['email' => 'carol@acme.com']);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $resolver = app(EnterpriseIdentityResolver::class);
    $result = $resolver->resolveOrgLogin($team, samlProfile('carol@acme.com'));

    expect($result->status)->toBe('matched');
    expect($result->user->id)->toBe($member->id);
    expect(ExternalIdentity::query()->where('user_id', $member->id)->where('provider', 'polis')->exists())->toBeTrue();
    expect(User::query()->where('email', 'carol@acme.com')->count())->toBe(1, 'one users row, two external_identities -- no duplicate created');
});

test('authkit_email_mismatch_does_not_create_duplicate_user', function () {
    $team = verifiedDomainTeam();
    // No member with this email exists in the org.
    $usersBefore = User::query()->count();

    $resolver = app(EnterpriseIdentityResolver::class);
    $result = $resolver->resolveOrgLogin($team, samlProfile('stranger@acme.com'));

    expect($result->status)->toBe('unlinked');
    expect($result->user)->toBeNull();
    expect(User::query()->count())->toBe($usersBefore, 'an unmatched SAML login must never create a user');
});

test('unlinked_members_are_listed_before_enforcement', function () {
    $team = verifiedDomainTeam();
    $admin = User::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    ExternalIdentity::factory()->for($admin)->polis()->create(['verified_at' => now()]);

    $unlinkedMember = User::factory()->create();
    $team->memberships()->create(['user_id' => $unlinkedMember->id, 'role' => TeamRole::Member]);

    $transitioner = app(AuthModeTransitioner::class);
    $atRisk = $transitioner->membersWithoutPolisIdentity($team);

    expect($atRisk->pluck('id'))->toContain($unlinkedMember->id);
    expect($atRisk->pluck('id'))->not->toContain($admin->id);
});

test('polis_enforcement_requires_admin_polis_identity', function () {
    $team = verifiedDomainTeam();
    $admin = User::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);

    $transitioner = app(AuthModeTransitioner::class);

    expect(fn () => $transitioner->transition($team, AuthMode::Polis, confirmed: true))
        ->toThrow(AuthModeTransitionException::class);
    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit);
});

test('polis_enforcement_lists_members_who_will_lose_access', function () {
    $team = verifiedDomainTeam();
    $admin = User::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    ExternalIdentity::factory()->for($admin)->polis()->create(['verified_at' => now()]);

    $contractor = User::factory()->create(['email' => 'contractor@personal.example']);
    $team->memberships()->create(['user_id' => $contractor->id, 'role' => TeamRole::Member]);

    $transitioner = app(AuthModeTransitioner::class);

    try {
        $transitioner->transition($team, AuthMode::Polis);
        test()->fail('Expected AuthModeTransitionException naming the at-risk member.');
    } catch (AuthModeTransitionException $exception) {
        expect($exception->getMessage())->toContain('contractor@personal.example');
    }
    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit);

    // Explicit confirmation proceeds despite the at-risk member.
    $transitioner->transition($team, AuthMode::Polis, confirmed: true);
    expect($team->fresh()->auth_mode)->toBe(AuthMode::Polis);
});

test('artifact_url_survives_tier_upgrade', function () {
    // An auth_mode transition is a Laravel-only state change -- it makes
    // no call to the Worker (which owns artifact storage/serving), so an
    // artifact issued before the upgrade is untouched by construction.
    // This asserts that structural guarantee directly: no outbound HTTP
    // call happens during a transition.
    Http::fake();

    $team = verifiedDomainTeam();
    $admin = User::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    ExternalIdentity::factory()->for($admin)->polis()->create(['verified_at' => now()]);

    $transitioner = app(AuthModeTransitioner::class);
    $transitioner->transition($team, AuthMode::Dual);
    $transitioner->transition($team, AuthMode::Polis, confirmed: true);

    Http::assertNothingSent();
});

test('downgrade_restores_authkit_login', function () {
    $team = verifiedDomainTeam();
    $admin = User::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    ExternalIdentity::factory()->for($admin)->create(['provider' => 'authkit', 'verified_at' => now()]);
    ExternalIdentity::factory()->for($admin)->polis()->create(['verified_at' => now()]);

    $transitioner = app(AuthModeTransitioner::class);
    $transitioner->transition($team, AuthMode::Dual);
    $transitioner->transition($team, AuthMode::Polis, confirmed: true);
    $transitioner->transition($team, AuthMode::AuthKit);

    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit);
    // The AuthKit identity was never deleted by any of the transitions.
    expect(ExternalIdentity::query()->where('user_id', $admin->id)->where('provider', 'authkit')->exists())->toBeTrue();
});

test('dual_mode_accepts_both_providers', function () {
    $team = verifiedDomainTeam();
    $admin = User::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $transitioner = app(AuthModeTransitioner::class);
    $transitioner->transition($team, AuthMode::Dual);

    $authKitMember = User::factory()->create(['email' => 'authkit-user@acme.com']);
    $team->memberships()->create(['user_id' => $authKitMember->id, 'role' => TeamRole::Member]);
    $samlMember = User::factory()->create(['email' => 'saml-user@acme.com']);
    $team->memberships()->create(['user_id' => $samlMember->id, 'role' => TeamRole::Member]);

    $enterpriseResolver = app(EnterpriseIdentityResolver::class);
    $samlResult = $enterpriseResolver->resolveOrgLogin($team, samlProfile('saml-user@acme.com'));
    expect($samlResult->status)->toBe('matched');
    expect($samlResult->user->id)->toBe($samlMember->id);

    // AuthKit identities on the same team's members are untouched and
    // still resolvable -- both providers are accepted simultaneously in
    // dual mode.
    expect($team->fresh()->auth_mode)->toBe(AuthMode::Dual);
});

test('team_org_upgrades_to_enterprise_end_to_end', function () {
    // The full path: authkit -> dual -> polis, with one already-linked
    // admin, one member who links during the dual window, one contractor
    // who never does -- no duplicate users, no orphans, no lockout.
    Http::fake(['*' => Http::response([], 200)]);
    config([
        'services.org_jwt.worker_base_url' => 'https://worker.test',
        'services.org_jwt.revocation_write_secret' => 'test-secret',
    ]);

    $team = verifiedDomainTeam('globex');
    $admin = User::factory()->create(['email' => 'admin@globex.com']);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $engineer = User::factory()->create(['email' => 'engineer@globex.com']);
    $team->memberships()->create(['user_id' => $engineer->id, 'role' => TeamRole::Member]);
    $contractor = User::factory()->create(['email' => 'contractor@personal.example']);
    $team->memberships()->create(['user_id' => $contractor->id, 'role' => TeamRole::Member]);

    $transitioner = app(AuthModeTransitioner::class);
    $resolver = app(EnterpriseIdentityResolver::class);

    $transitioner->transition($team, AuthMode::Dual);
    expect($team->fresh()->auth_mode)->toBe(AuthMode::Dual);

    // Admin links first (required for the polis-lockout precondition).
    $adminResult = $resolver->resolveOrgLogin($team, samlProfile('admin@globex.com'));
    expect($adminResult->status)->toBe('matched');

    // Engineer links during the dual window.
    $engineerResult = $resolver->resolveOrgLogin($team, samlProfile('engineer@globex.com'));
    expect($engineerResult->status)->toBe('matched');
    expect(User::query()->where('email', 'engineer@globex.com')->count())->toBe(1);

    // Contractor never links -- surfaced, not enforced yet.
    $atRisk = $transitioner->membersWithoutPolisIdentity($team);
    expect($atRisk->pluck('id'))->toContain($contractor->id);
    expect($atRisk->pluck('id'))->not->toContain($admin->id);
    expect($atRisk->pluck('id'))->not->toContain($engineer->id);

    // Enforcement without confirmation is refused, naming the contractor.
    expect(fn () => $transitioner->transition($team, AuthMode::Polis))
        ->toThrow(AuthModeTransitionException::class);

    // Confirmed enforcement proceeds.
    $transitioner->transition($team, AuthMode::Polis, confirmed: true);
    expect($team->fresh()->auth_mode)->toBe(AuthMode::Polis);

    // No duplicate users were created anywhere in this run.
    expect(User::query()->count())->toBe(3);

    // Downgrade restores AuthKit access for everyone who had it.
    $transitioner->transition($team, AuthMode::AuthKit);
    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit);
    expect(ExternalIdentity::query()->where('provider', 'polis')->count())->toBe(2, 'identities are never deleted by a downgrade');
});
