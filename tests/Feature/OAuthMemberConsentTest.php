<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\OAuthClient;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A Member who also has a personal team — the shape that failed in Cursor's
 * OAuth window (RUB-510).
 *
 * @return array{0: User, 1: Team, 2: Team}
 */
function memberWithPersonalAndWorkTeam(): array
{
    $member = User::factory()->create(['name' => 'Hoku Member']);
    $personal = app(CreateTeam::class)->handle($member, "Hoku's Team");
    $work = Team::factory()->create(['name' => 'artfct-dev', 'slug' => 'artfct-dev']);
    $work->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    $member->switchTeam($work);

    return [$member, $personal, $work];
}

/**
 * @return array<string, mixed>
 */
function cursorConsentParameters(Team $team, string $clientId = 'artfct_cursor_test_client'): array
{
    OAuthClient::factory()->create([
        'client_id' => $clientId,
        'client_name' => 'Cursor',
        'redirect_uris' => ['https://www.cursor.com/agents/mcp/oauth/callback'],
    ]);

    return oauthParameters(oauthChallenge(str_repeat('c', 64)), [
        'client_id' => $clientId,
        'redirect_uri' => 'https://www.cursor.com/agents/mcp/oauth/callback',
        'team' => $team->slug,
    ]);
}

test('a team member can open consent for a work team while also belonging to a personal team', function () {
    [$member, $personal, $work] = memberWithPersonalAndWorkTeam();
    $parameters = cursorConsentParameters($work, 'artfct_cursor_list_client');

    $this->actingAs($member)
        ->get('/oauth/authorize?'.http_build_query($parameters))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oauth/authorize')
            ->where('team.slug', $work->slug)
            ->where('csrfToken', fn ($token): bool => is_string($token) && $token !== '')
            ->has('teams', 2)
            ->where('teams', fn ($teams): bool => $teams->contains('slug', $personal->slug)
                && $teams->contains('slug', $work->slug)));
});

test('a team member approving consent without inertia is redirected to the client callback', function () {
    [$member, , $work] = memberWithPersonalAndWorkTeam();
    $parameters = cursorConsentParameters($work, 'artfct_cursor_redirect_client');

    $response = $this->actingAs($member)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'approve',
        'team' => $work->slug,
    ]);

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toStartWith('https://www.cursor.com/agents/mcp/oauth/callback?')
        ->toContain('code=')
        ->toContain('state='.$parameters['state']);
});

test('a team member approving via inertia still receives a location visit header', function () {
    [$member, , $work] = memberWithPersonalAndWorkTeam();
    $parameters = cursorConsentParameters($work, 'artfct_cursor_inertia_client');

    $response = $this->actingAs($member)
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post('/oauth/authorize', [
            ...$parameters,
            'decision' => 'approve',
            'team' => $work->slug,
        ]);

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))
        ->toStartWith('https://www.cursor.com/agents/mcp/oauth/callback?')
        ->toContain('code=');
});

test('oauth consent is not redirected to the terms gate when consent is required', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v1']);

    [$member, , $work] = memberWithPersonalAndWorkTeam();
    expect($member->terms_version)->not->toBe('v1');

    $parameters = cursorConsentParameters($work, 'artfct_cursor_terms_client');

    $this->actingAs($member)
        ->get('/oauth/authorize?'.http_build_query($parameters))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('oauth/authorize'));
});
