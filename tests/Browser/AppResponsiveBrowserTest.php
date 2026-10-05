<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\TeamDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Signed-in pages at phone, tablet and desktop widths (RUB mobile slice).
 *
 * The layout switches below 768px: the desktop nav hides and a hamburger
 * opens an accessible Dialog with every role-visible destination, team
 * switching and account controls. Artifact and membership tables become
 * labeled cards without duplicating testids or controls; billing and audit
 * tables keep contained scrolling.
 */
function responsiveOwner(string $email = 'responsive-owner@example.com'): array
{
    $owner = User::factory()->create(['name' => 'Responsive Owner', 'email' => $email]);
    $team = app(CreateTeam::class)->handle($owner, 'Responsive Co');

    return [$team, $owner];
}

/** @return array<string, string> label => url */
function responsivePages($team): array
{
    return [
        'dashboard' => route('dashboard', $team),
        'account' => route('account.show'),
        'teams' => route('teams.index'),
        'team settings' => route('teams.edit', $team),
        'console' => route('console.index', ['team' => $team->slug]),
        'search' => route('teams.search', $team),
        'tokens' => route('teams.tokens.index', $team),
        'connections' => route('teams.mcp-connections.index', $team),
        'billing' => route('teams.billing.show', $team),
        'audit' => route('teams.audit.index', $team),
        'authentication' => route('teams.authentication.show', $team),
        'governance' => route('teams.governance.show', $team),
    ];
}

function assertNarrowPageFits($page): void
{
    $page->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
}

function openMobileMenu($page)
{
    return $page->click('@mobile-menu-button')
        ->assertVisible('@mobile-menu')
        ->assertNoJavaScriptErrors();
}

test('signed_in_pages_fit_without_overflow_at_320px', function () {
    [$team, $owner] = responsiveOwner();
    test()->actingAs($owner);

    foreach (responsivePages($team) as $label => $url) {
        $page = visit($url)->resize(320, 800);
        assertNarrowPageFits($page);
    }
});

test('signed_in_pages_fit_without_overflow_at_390px', function () {
    [$team, $owner] = responsiveOwner('responsive-390@example.com');
    test()->actingAs($owner);

    foreach (responsivePages($team) as $label => $url) {
        $page = visit($url)->resize(390, 844);
        assertNarrowPageFits($page);
    }
});

test('signed_in_pages_fit_without_overflow_at_768px', function () {
    [$team, $owner] = responsiveOwner('responsive-768@example.com');
    test()->actingAs($owner);

    foreach (responsivePages($team) as $label => $url) {
        $page = visit($url)->resize(768, 1024);
        assertNarrowPageFits($page);
    }
});

test('signed_in_pages_fit_without_overflow_at_1280px', function () {
    [$team, $owner] = responsiveOwner('responsive-1280@example.com');
    test()->actingAs($owner);

    foreach (responsivePages($team) as $label => $url) {
        $page = visit($url)->resize(1280, 800);
        assertNarrowPageFits($page);
    }
});

test('long_team_artifact_member_and_domain_content_fits_at_320px', function () {
    config(['services.artifact_access.token_secret' => 'browser-artifact-secret']);

    $owner = User::factory()->create(['name' => 'Long Content Owner', 'email' => 'long-content-owner@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'A Remarkably Long Team Name That Keeps Going And Going For Width Testing Purposes');
    $member = memberOfTeam($team, TeamRole::Member);
    $member->update(['email' => 'a-very-long-email-address-for-a-teammate-on-this-team@example-corporation.com']);

    /** @var ArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'An Exceptionally Long Artifact Title That Should Wrap Onto Several Lines Rather Than Overflow The Phone Screen',
        'description' => 'Seeded for the responsive overflow tests.',
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/example-organization/a-very-long-repository-name-for-overflow-testing', 'commit_sha' => null],
    ]);

    TeamDomain::factory()->create([
        'team_id' => $team->id,
        'domain' => 'a-very-long-subdomain-label-for-testing-purposes.another-long-label.example-corporation.com',
    ]);

    test()->actingAs($owner);

    $page = visit(route('console.index', ['team' => $team->slug]))->resize(320, 800);
    assertNarrowPageFits($page);
    $page->assertSee('Exceptionally Long Artifact Title');

    $page = visit(route('teams.edit', $team))->resize(320, 800);
    assertNarrowPageFits($page);
    $page->assertSee('a-very-long-email-address-for-a-teammate')
        ->assertSee('another-long-label');

    $page = visit(route('dashboard', $team))->resize(320, 800);
    assertNarrowPageFits($page);

    openMobileMenu($page)->assertSee('A Remarkably Long Team Name');
    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
});

test('mobile_menu_reaches_every_admin_destination_and_closes_on_navigation', function () {
    [$team, $owner] = responsiveOwner('menu-admin@example.com');
    test()->actingAs($owner);

    $page = visit(route('dashboard', $team))->resize(390, 844);

    openMobileMenu($page);

    foreach (['dashboard', 'artifacts', 'search', 'collections', 'team', 'tokens', 'connections', 'billing', 'authentication', 'governance', 'audit'] as $destination) {
        $page->assertVisible("@mobile-nav-{$destination}");
    }

    $page->click('@mobile-nav-artifacts')->wait(1)
        ->assertRoute('console.index', ['team' => $team->slug])
        ->assertMissing('@mobile-menu')
        ->assertNoJavaScriptErrors();

    openMobileMenu($page)->click('@mobile-nav-billing')->wait(1)
        ->assertRoute('teams.billing.show', ['team' => $team->slug])
        ->assertMissing('@mobile-menu')
        ->assertNoJavaScriptErrors();
});

test('mobile_menu_hides_admin_destinations_from_members', function () {
    [$team, $owner] = responsiveOwner('menu-owner@example.com');
    $member = memberOfTeam($team, TeamRole::Member);
    test()->actingAs($member);

    $page = visit(route('dashboard', $team))->resize(390, 844);

    openMobileMenu($page);

    foreach (['dashboard', 'artifacts', 'search', 'team', 'billing'] as $destination) {
        $page->assertVisible("@mobile-nav-{$destination}");
    }

    foreach (['authentication', 'governance', 'audit'] as $destination) {
        $page->assertMissing("@mobile-nav-{$destination}");
    }

    $page->assertNoJavaScriptErrors();
});

test('mobile_menu_switches_teams_and_reaches_account_settings', function () {
    $owner = User::factory()->create(['name' => 'Team Switcher', 'email' => 'team-switcher@example.com']);
    $alpha = app(CreateTeam::class)->handle($owner, 'Alpha Team');
    $beta = app(CreateTeam::class)->handle($owner, 'Beta Team');
    test()->actingAs($owner);

    $page = visit(route('dashboard', $alpha))->resize(390, 844);

    openMobileMenu($page)
        ->assertSeeIn('@mobile-menu', 'Beta Team')
        ->click('Beta Team')->wait(1)
        ->assertRoute('dashboard', ['current_team' => $beta->slug])
        ->assertSee('Beta Team')
        ->assertNoJavaScriptErrors();

    openMobileMenu($page)->click('Account settings')->wait(1)
        ->assertRoute('account.show')
        ->assertSee('Linked identities')
        ->assertNoJavaScriptErrors();
});

test('mobile_menu_signs_out', function () {
    [$team, $owner] = responsiveOwner('menu-logout@example.com');
    test()->actingAs($owner);

    $page = visit(route('dashboard', $team))->resize(390, 844);

    openMobileMenu($page)->assertSeeIn('@mobile-menu', 'Sign out');
    $page->click('Sign out')->wait(1);

    $page->navigate(route('account.show'))->wait(1)
        ->assertSee('Continue with Google')
        ->assertNoJavaScriptErrors();
});

test('mobile_menu_traps_focus_and_closes_with_escape', function () {
    [$team, $owner] = responsiveOwner('menu-focus@example.com');
    test()->actingAs($owner);

    $page = visit(route('dashboard', $team))->resize(390, 844);

    openMobileMenu($page)
        ->assertScript('document.querySelector(\'[data-testid="mobile-menu"]\').contains(document.activeElement)');

    // The driver presses buttons by text, so Tab is sent as a keydown:
    // from the last focusable control it must wrap inside the menu.
    $page->script('([...document.querySelector(\'[data-testid="mobile-menu"]\').querySelectorAll(\'a[href],button:not([disabled])\')].pop().focus(), document.dispatchEvent(new KeyboardEvent(\'keydown\', {key: \'Tab\', bubbles: true, cancelable: true})))');
    $page->assertScript('document.querySelector(\'[data-testid="mobile-menu"]\').contains(document.activeElement)');

    // pest-plugin-browser presses buttons by text; Escape is sent as a keydown
    // because Radix closes the dialog on the document keydown handler.
    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}))");
    $page->wait(0.5)->assertMissing('@mobile-menu')->assertNoJavaScriptErrors();

    // The trigger lives outside the Dialog Root, so focus is returned to it
    // explicitly when the menu closes.
    $page->assertScript('document.activeElement === document.querySelector(\'[data-testid="mobile-menu-button"]\')');
});

test('mobile_menu_close_button_closes_and_returns_focus', function () {
    [$team, $owner] = responsiveOwner('menu-close@example.com');
    test()->actingAs($owner);

    $page = visit(route('dashboard', $team))->resize(390, 844);

    openMobileMenu($page)->click('Close menu')->wait(0.5)
        ->assertMissing('@mobile-menu')
        ->assertScript('document.activeElement === document.querySelector(\'[data-testid="mobile-menu-button"]\')')
        ->assertNoJavaScriptErrors();
});

test('mobile_menu_closes_when_resized_to_desktop', function () {
    [$team, $owner] = responsiveOwner('menu-resize@example.com');
    test()->actingAs($owner);

    $page = visit(route('dashboard', $team))->resize(390, 844);

    openMobileMenu($page);
    $page->resize(1280, 800)->wait(0.5)
        ->assertMissing('@mobile-menu')
        ->assertSee('Artifacts')
        ->assertNoJavaScriptErrors();
});

test('mobile_controls_meet_touch_and_font_targets', function () {
    config(['services.artifact_access.token_secret' => 'browser-artifact-secret']);

    [$team, $owner] = responsiveOwner('menu-targets@example.com');

    /** @var ArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Touch Target Report',
        'description' => 'Seeded for the responsive control tests.',
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($owner);

    $page = visit(route('console.index', ['team' => $team->slug]))->resize(390, 844);

    // iOS zooms inputs under 16px; filters must stay at body size on phones.
    $page->assertScript('parseFloat(getComputedStyle(document.querySelector("#repo_url")).fontSize) >= 16')
        ->assertScript('(document.querySelector(\'[data-testid="mobile-menu-button"]\').getBoundingClientRect().height) >= 44')
        ->assertScript('(document.querySelector(\'[data-testid="open-artifact"]\').getBoundingClientRect().height) >= 44')
        ->assertNoJavaScriptErrors();

    openMobileMenu($page)
        ->assertScript('Array.from(document.querySelectorAll(\'[data-testid="mobile-menu"] nav a\')).every((link) => link.getBoundingClientRect().height >= 44)');
});

test('desktop_nav_returns_at_tablet_width_and_above', function () {
    [$team, $owner] = responsiveOwner('menu-desktop@example.com');
    test()->actingAs($owner);

    foreach ([768, 1280] as $width) {
        $page = visit(route('dashboard', $team))->resize($width, 800);

        $page->assertSee('Artifacts')
            ->assertMissing('@mobile-menu-button')
            ->assertNoJavaScriptErrors();
    }
});
