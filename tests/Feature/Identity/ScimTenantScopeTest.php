<?php

use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Identity\ScimProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function scopedTeam(string $slug, string $domain): Team
{
    $team = Team::factory()->create(['slug' => $slug]);
    $team->domains()->create(['domain' => $domain, 'verified_at' => now()]);

    return $team;
}

function directoryEvent(string $tenant, array $payload, string $secret = 'whsec'): TestResponse
{
    $body = json_encode(['event' => $payload['event'], 'tenant' => $tenant, 'product' => 'artfct', 'data' => $payload['data']]);
    $timestampMs = (int) (microtime(true) * 1000);
    $signature = hash_hmac('sha256', $timestampMs.'.'.$body, $secret);

    return test()->call('POST', '/webhooks/polis', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_BOXYHQ_SIGNATURE' => "t={$timestampMs},s={$signature}",
    ], $body);
}

beforeEach(function () {
    config([
        'services.polis.webhook_secret' => 'whsec',
        'services.org_jwt.worker_base_url' => 'https://worker.test',
        'services.org_jwt.revocation_write_secret' => 'test-revocation-secret',
    ]);
    Http::fake(['*' => Http::response([], 200)]);
});

test('a_tenant_cannot_link_a_user_who_is_only_in_another_tenant', function () {
    $tenantA = scopedTeam('alpha', 'alpha.com');
    $tenantB = scopedTeam('beta', 'beta.com');
    $victim = User::factory()->create(['email' => 'victim@beta.com']);
    $tenantB->memberships()->create(['user_id' => $victim->id, 'role' => TeamRole::Member]);

    $linked = app(ScimProvisioningService::class)->provision($tenantA, 'a-claim', 'victim@beta.com');

    expect($linked)->toBeNull()
        ->and($tenantA->members()->where('users.id', $victim->id)->exists())->toBeFalse()
        ->and(ExternalIdentity::query()->where('user_id', $victim->id)->where('provider', 'scim:'.$tenantA->id)->exists())->toBeFalse();
});

test('a_directory_event_from_one_tenant_cannot_link_another_tenants_user', function () {
    $tenantA = scopedTeam('alpha', 'alpha.com');
    $tenantB = scopedTeam('beta', 'beta.com');
    $victim = User::factory()->create(['email' => 'victim@beta.com']);
    $tenantB->memberships()->create(['user_id' => $victim->id, 'role' => TeamRole::Member]);

    directoryEvent('alpha', ['event' => 'user.created', 'data' => ['id' => 'a-claim', 'email' => 'victim@beta.com', 'active' => true]])->assertOk();

    expect($tenantA->members()->where('users.id', $victim->id)->exists())->toBeFalse()
        ->and(ExternalIdentity::query()->where('user_id', $victim->id)->where('provider', 'scim:'.$tenantA->id)->exists())->toBeFalse()
        ->and($victim->fresh()->deactivated_at)->toBeNull();
});

test('a_tenant_cannot_deactivate_a_user_whose_identity_belongs_to_another_tenant', function () {
    $tenantA = scopedTeam('alpha', 'alpha.com');
    $tenantB = scopedTeam('beta', 'beta.com');
    $victim = User::factory()->create(['email' => 'victim@beta.com']);
    $tenantB->memberships()->create(['user_id' => $victim->id, 'role' => TeamRole::Member]);
    ExternalIdentity::query()->create([
        'user_id' => $victim->id,
        'provider' => 'scim:'.$tenantB->id,
        'external_id' => 'b-7',
        'email' => 'victim@beta.com',
        'verified_at' => now(),
    ]);
    OrgToken::factory()->for($tenantB)->for($victim)->create(['revoked_at' => null]);

    directoryEvent('alpha', ['event' => 'user.updated', 'data' => ['id' => 'b-7', 'email' => 'victim@beta.com', 'active' => false]])->assertOk();

    expect($victim->fresh()->deactivated_at)->toBeNull()
        ->and($tenantB->members()->where('users.id', $victim->id)->exists())->toBeTrue()
        ->and(OrgToken::query()->where('user_id', $victim->id)->whereNull('revoked_at')->exists())->toBeTrue();
});

test('a_tenant_deprovisioning_its_own_identity_leaves_the_same_user_in_another_tenant_active', function () {
    $tenantA = scopedTeam('alpha', 'shared.com');
    $tenantB = scopedTeam('beta', 'shared.com');
    $person = User::factory()->create(['email' => 'pat@shared.com']);
    $tenantB->memberships()->create(['user_id' => $person->id, 'role' => TeamRole::Member]);

    app(ScimProvisioningService::class)->provision($tenantA, 'a-pat', 'pat@shared.com');
    expect(app(ScimProvisioningService::class)->deprovision($tenantA, 'a-pat'))->toBeTrue();

    expect($person->fresh()->deactivated_at)->toBeNull()
        ->and($tenantB->members()->where('users.id', $person->id)->exists())->toBeTrue()
        ->and($tenantA->members()->where('users.id', $person->id)->exists())->toBeFalse();
});

test('deprovisioning_one_of_two_teams_removes_only_that_membership', function () {
    $teamOne = scopedTeam('one', 'two-teams.com');
    $teamTwo = scopedTeam('two', 'two-teams.com');
    $service = app(ScimProvisioningService::class);
    $user = $service->provision($teamOne, 'scim-both', 'both@two-teams.com');
    $teamTwo->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    OrgToken::factory()->for($teamTwo)->for($user)->create(['revoked_at' => null]);

    expect($service->deprovision($teamOne, 'scim-both'))->toBeTrue();

    expect($user->fresh()->deactivated_at)->toBeNull()
        ->and($teamOne->members()->where('users.id', $user->id)->exists())->toBeFalse()
        ->and($teamTwo->members()->where('users.id', $user->id)->exists())->toBeTrue()
        ->and(OrgToken::query()->where('user_id', $user->id)->where('team_id', $teamTwo->id)->whereNull('revoked_at')->exists())->toBeTrue();
});

test('deprovisioning_the_only_membership_deactivates_ends_sessions_and_clears_staff_access', function () {
    $team = scopedTeam('solo', 'solo.com');
    $service = app(ScimProvisioningService::class);
    $user = $service->provision($team, 'scim-solo', 'solo@solo.com');
    $user->forceFill(['staff_at' => now(), 'case_manager_at' => now()])->save();
    DB::table('sessions')->insert([
        ['id' => 'session-one', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()],
        ['id' => 'session-two', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()],
    ]);

    expect($service->deprovision($team, 'scim-solo'))->toBeTrue();

    $user->refresh();
    expect($user->deactivated_at)->not->toBeNull()
        ->and($user->staff_at)->toBeNull()
        ->and($user->case_manager_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('a_legacy_scim_identity_is_honoured_for_a_member_of_the_event_team', function () {
    $team = scopedTeam('legacy', 'legacy.com');
    $member = User::factory()->create(['email' => 'member@legacy.com']);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    ExternalIdentity::query()->create([
        'user_id' => $member->id,
        'provider' => 'scim',
        'external_id' => 'legacy-1',
        'email' => 'member@legacy.com',
        'verified_at' => now(),
    ]);

    expect(app(ScimProvisioningService::class)->deprovision($team, 'legacy-1'))->toBeTrue();

    expect($member->fresh()->deactivated_at)->not->toBeNull()
        ->and(ExternalIdentity::query()->where('user_id', $member->id)->where('provider', 'scim')->exists())->toBeFalse()
        ->and(ExternalIdentity::query()->where('user_id', $member->id)->where('provider', 'scim:'.$team->id)->where('external_id', 'legacy-1')->exists())->toBeTrue();
});

test('a_legacy_scim_identity_is_ignored_for_a_user_who_is_not_in_the_event_team', function () {
    $team = scopedTeam('legacy', 'legacy.com');
    $outsider = User::factory()->create(['email' => 'outsider@elsewhere.com']);
    ExternalIdentity::query()->create([
        'user_id' => $outsider->id,
        'provider' => 'scim',
        'external_id' => 'legacy-2',
        'email' => 'outsider@elsewhere.com',
        'verified_at' => now(),
    ]);

    expect(app(ScimProvisioningService::class)->deprovision($team, 'legacy-2'))->toBeTrue();

    expect($outsider->fresh()->deactivated_at)->toBeNull();
});
