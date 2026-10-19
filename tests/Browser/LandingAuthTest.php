<?php

use App\Actions\Teams\CreateTeam;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Signs in a user who has a current team, so `auth.user` and `currentTeam` are
 * both shared. Returns the team so tests can address its console.
 */
function landingAccountUserWithTeam(): Team
{
    $user = User::factory()->create([
        'name' => 'Account User',
        'email' => 'account-user@example.com',
    ]);

    $team = app(CreateTeam::class)->handle($user, 'Account Team');

    test()->actingAs($user);

    return $team;
}

test('guest sees the guest account controls on the landing page and shared header', function () {
    visit('/')
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@landing-account', 'Sign in')
        ->assertSeeIn('@landing-account', 'Try Team free')
        ->assertAttributeContains('@landing-account-cta', 'href', '/login');

    visit('/blog')
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@site-account', 'Sign in')
        ->assertSeeIn('@site-account', 'Try Team free')
        ->assertAttributeContains('@site-account-cta', 'href', '/login');
});

test('signed-in user with a current team sees open console everywhere', function () {
    $team = landingAccountUserWithTeam();

    $consoleUrl = '/settings/teams/'.$team->slug.'/console';

    visit('/')
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@landing-account', 'Open console')
        ->assertDontSeeIn('@landing-account', 'Try Team free')
        ->assertMissing('@landing-signin')
        ->assertAttributeContains('@landing-account-cta', 'href', $consoleUrl)
        ->assertAttributeContains('@landing-hero-cta', 'href', $consoleUrl)
        ->assertSeeIn('@landing-pricing-cta', 'Open console')
        ->assertAttributeContains('@landing-pricing-cta', 'href', $consoleUrl)
        ->assertSeeIn('@landing-closing-cta', 'Open console')
        ->assertAttributeContains('@landing-closing-cta', 'href', $consoleUrl)
        ->assertScript(<<<JS
            (() => {
                const links = Array.from(
                    document.querySelectorAll('.landing [data-testid$="-cta"]'),
                );

                return links.length === 4 && links.every(
                    (link) =>
                        link.getAttribute('href').includes('{$consoleUrl}') &&
                        link.textContent.includes('Open console'),
                );
            })()
            JS, true);

    visit('/blog')
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@site-account', 'Open console')
        ->assertDontSeeIn('@site-account', 'Try Team free')
        ->assertMissing('@site-signin')
        ->assertAttributeContains('@site-account-cta', 'href', $consoleUrl);
});

test('signed-in user without a current team sees create team everywhere', function () {
    $user = User::factory()->create([
        'name' => 'No Team User',
        'email' => 'no-team-user@example.com',
    ]);

    test()->actingAs($user);

    visit('/')
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@landing-account', 'Create team')
        ->assertDontSeeIn('@landing-account', 'Try Team free')
        ->assertMissing('@landing-signin')
        ->assertAttributeContains('@landing-account-cta', 'href', '/onboarding/team')
        ->assertAttributeContains('@landing-hero-cta', 'href', '/onboarding/team')
        ->assertSeeIn('@landing-pricing-cta', 'Create team')
        ->assertAttributeContains('@landing-pricing-cta', 'href', '/onboarding/team')
        ->assertSeeIn('@landing-closing-cta', 'Create team')
        ->assertAttributeContains('@landing-closing-cta', 'href', '/onboarding/team')
        ->assertScript(<<<'JS'
            (() => {
                const links = Array.from(
                    document.querySelectorAll('.landing [data-testid$="-cta"]'),
                );

                return links.length === 4 && links.every(
                    (link) =>
                        link.getAttribute('href').includes('/onboarding/team') &&
                        link.textContent.includes('Create team'),
                );
            })()
            JS, true);

    visit('/blog')
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@site-account', 'Create team')
        ->assertDontSeeIn('@site-account', 'Try Team free')
        ->assertMissing('@site-signin')
        ->assertAttributeContains('@site-account-cta', 'href', '/onboarding/team');
});
