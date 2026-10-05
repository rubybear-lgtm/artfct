<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The viewports every public and auth-facing page must fit: small phone,
 * large phone, tablet and desktop. Each test resizes the real browser window,
 * so `scrollWidth <= innerWidth` proves the page itself never scrolls
 * sideways (code blocks may still scroll inside their own containers).
 *
 * @return list<int>
 */
function responsiveWidths(): array
{
    return [320, 390, 768, 1280];
}

/** @param  array<string>  $urls */
function assertPagesFitViewports(array $urls, ?string $expectedText = null): void
{
    foreach ($urls as $url) {
        foreach (responsiveWidths() as $width) {
            $page = visit($url)
                ->resize($width, 900)
                ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1', true)
                ->assertNoJavaScriptErrors();
            if ($expectedText !== null) {
                $page->assertSee($expectedText);
            }
        }
    }
}

test('public reading pages fit every viewport without sideways scrolling', function () {
    assertPagesFitViewports([
        '/',
        '/free',
        '/docs',
        '/blog',
        '/blog/developer-tools',
        '/terms',
        '/privacy',
    ]);
});

test('auth pages fit every viewport without sideways scrolling', function () {
    assertPagesFitViewports(['/login'], 'Continue to Artfct');

    $user = User::factory()->create(['email' => 'responsive@example.com']);
    test()->actingAs($user);
    assertPagesFitViewports(['/onboarding/team'], 'Create your first team');

    $team = app(CreateTeam::class)->handle($user, 'Responsive Co');
    oauthTestClient();

    $oauth = '/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => 'test-native-client',
        'redirect_uri' => 'http://127.0.0.1:43123/oauth/callback',
        'scope' => 'artifacts:read artifacts:delete usage:read',
        'state' => 'responsive-state',
        'code_challenge' => str_repeat('c', 64),
        'code_challenge_method' => 'S256',
        'team' => $team->slug,
    ]);

    assertPagesFitViewports([$oauth], 'Responsive Co');
});

test('invitation and terms acceptance fit every viewport', function () {
    $team = Team::factory()->create(['name' => 'Viewport Analytics']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $invitation = $team->invitations()->create([
        'email' => 'viewport-invitee@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $admin->id,
        'expires_at' => now()->addDays(3),
    ]);

    assertPagesFitViewports([route('invitations.show', $invitation)]);

    config(['legal.consent_required' => true, 'legal.terms_version' => 'viewport-v1']);
    test()->actingAs($admin);

    assertPagesFitViewports([route('terms.accept.show')]);
});

test('a very long team name and email still fit a 320px invitation', function () {
    $team = Team::factory()->create(['name' => str_repeat('Very Long Team Name ', 6)]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $invitation = $team->invitations()->create([
        'email' => 'an-extremely-long-invitation-recipient-address@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $admin->id,
        'expires_at' => now()->addDays(3),
    ]);

    visit(route('invitations.show', $invitation))
        ->resize(320, 900)
        ->assertSee('Very Long Team Name')
        ->assertSee('an-extremely-long-invitation-recipient-address@example.com')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1', true)
        ->assertNoJavaScriptErrors();
});

test('phone controls meet touch and type sizes', function () {
    // Landing hero call to action is reachable with a thumb.
    visit('/')
        ->resize(390, 844)
        ->assertScript('document.querySelector(\'[data-testid="landing-hero-cta"]\').getBoundingClientRect().height >= 44', true)
        ->assertNoJavaScriptErrors();

    // Sign-in fields stay at 16px (no pinch-zoom on focus) with a 44px button.
    visit('/login')
        ->resize(390, 844)
        ->assertScript('parseFloat(getComputedStyle(document.querySelector(\'#email\')).fontSize) >= 16', true)
        ->assertScript('document.querySelector(\'button[type="submit"]\').getBoundingClientRect().height >= 44', true)
        ->assertNoJavaScriptErrors();

    // Team creation is the same story one step later.
    $user = User::factory()->create(['email' => 'phone-targets@example.com']);
    test()->actingAs($user);

    visit('/onboarding/team')
        ->resize(390, 844)
        ->assertScript('parseFloat(getComputedStyle(document.querySelector(\'#name\')).fontSize) >= 16', true)
        ->assertScript('document.querySelector(\'button[type="submit"]\').getBoundingClientRect().height >= 44', true)
        ->assertNoJavaScriptErrors();
});

test('docs code blocks scroll inside their containers at 320px', function () {
    visit('/docs')
        ->resize(320, 900)
        ->assertScript('Array.from(document.querySelectorAll("pre")).every((block) => block.getBoundingClientRect().right <= window.innerWidth + 1) && document.querySelectorAll("pre").length > 0', true)
        ->assertNoJavaScriptErrors();
});
