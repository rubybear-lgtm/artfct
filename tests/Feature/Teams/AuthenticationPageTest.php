<?php

use App\Enums\AuthMode;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function ssoTeam(Plan $plan = Plan::Enterprise): array
{
    config(['services.polis' => ['base_url' => 'https://polis.test', 'api_key' => 'k', 'client_secret_verifier' => 'v']]);
    $team = Team::factory()->create(['plan' => $plan, 'slug' => 'acme']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    return [$team, $admin];
}

test('admins_see_domains_modes_with_previews_and_connections', function () {
    [$team, $admin] = ssoTeam();
    Http::fake(['polis.test/api/v1/sso*' => Http::response([['oidcProvider' => null, 'idpMetadata' => ['provider' => 'MockSAML']]])]);

    test()->actingAs($admin)->get(route('teams.authentication.show', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/authentication')
            ->where('authMode', 'authkit')
            ->where('previews.dual.allowed', false)
            ->where('connections.0.type', 'SAML'));
});

test('the_preview_refuses_without_a_verified_domain_and_names_the_reason', function () {
    [$team, $admin] = ssoTeam();
    Http::fake(['polis.test/*' => Http::response([])]);

    test()->actingAs($admin)->get(route('teams.authentication.show', $team))
        ->assertInertia(fn (Assert $page) => $page->where('previews.polis.reason', fn ($reason) => str_contains($reason, 'no verified domain')));
});

test('the_preview_lists_members_who_would_lose_access_and_never_writes', function () {
    [$team, $admin] = ssoTeam();
    Http::fake(['polis.test/*' => Http::response([])]);
    $team->domains()->create(['domain' => 'acme.com', 'verified_at' => now()]);
    $admin->externalIdentities()->create(['provider' => 'polis', 'external_id' => 'p-1', 'email' => $admin->email, 'verified_at' => now()]);
    $other = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($admin)->get(route('teams.authentication.show', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('previews.polis.allowed', true)
            ->where('previews.polis.needsConfirmation', true)
            ->where('previews.polis.atRisk', [$other->email]));

    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit);
});

test('switching_back_to_standard_sign_in_is_always_allowed', function () {
    [$team, $admin] = ssoTeam(Plan::Team);
    $team->forceFill(['auth_mode' => AuthMode::Dual])->save();
    Http::fake(['polis.test/*' => Http::response([])]);

    test()->actingAs($admin)->patch(route('teams.auth-mode.update', $team), ['auth_mode' => 'authkit'])->assertRedirect(route('teams.authentication.show', $team));

    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit);
});

test('non_enterprise_teams_cannot_add_a_connection', function () {
    [$team, $admin] = ssoTeam(Plan::Team);
    Http::fake();

    test()->actingAs($admin)->post(route('teams.authentication.connection.store', $team), ['metadata_url' => 'https://idp.example.com/m'])->assertForbidden();

    Http::assertNothingSent();
});

test('an_enterprise_admin_adds_and_removes_a_connection_through_polis', function () {
    [$team, $admin] = ssoTeam();
    Http::fake(['polis.test/*' => Http::response(['clientID' => 'c'])]);

    test()->actingAs($admin)->post(route('teams.authentication.connection.store', $team), ['metadata_url' => 'https://idp.example.com/m'])->assertRedirect();
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['tenant'] === 'acme'
        && $request['metadataUrl'] === 'https://idp.example.com/m'
        && $request->hasHeader('Authorization', 'Api-Key k'));

    test()->actingAs($admin)->delete(route('teams.authentication.connection.destroy', $team))->assertRedirect();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

test('a_connection_cannot_be_removed_while_sso_only_is_enforced', function () {
    [$team, $admin] = ssoTeam();
    $team->forceFill(['auth_mode' => AuthMode::Polis])->save();
    Http::fake();

    test()->actingAs($admin)->delete(route('teams.authentication.connection.destroy', $team))->assertStatus(422);

    Http::assertNothingSent();
});

test('members_and_outsiders_cannot_open_the_page', function () {
    [$team] = ssoTeam();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get(route('teams.authentication.show', $team))->assertForbidden();
    test()->actingAs(User::factory()->create())->get(route('teams.authentication.show', $team))->assertForbidden();
});

test('a_non_https_metadata_url_is_rejected', function () {
    [$team, $admin] = ssoTeam();
    Http::fake();

    test()->actingAs($admin)->post(route('teams.authentication.connection.store', $team), ['metadata_url' => 'http://idp.example.com/m'])->assertSessionHasErrors('metadata_url');

    Http::assertNothingSent();
});
