<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The console's open control hands the browser a link to the isolated artifact
 * origin (RUB-365). The browser is the only place the control's new-tab wiring
 * is real, so it is asserted here; the redirect and the token it carries are
 * asserted in `Feature/ConsoleTest.php`, where the response can be inspected
 * without following it to a public artifact origin.
 */
test('console_open_control_opens_the_signed_link_route_in_a_new_tab', function () {
    // The app under test is served in-process, so this reaches it.
    config(['services.artifact_access.token_secret' => 'browser-artifact-secret']);

    $page = visit('/login');

    $page->assertNoJavaScriptErrors()
        ->fill('email', 'open-user@example.com')
        ->fill('name', 'Open User')
        ->click('Continue with Google')
        ->assertSee('Create your first team')
        ->click('Create team')
        ->assertSee('What your AI makes');

    $page->navigate('/settings/teams/open-users-team/console')
        ->assertNoJavaScriptErrors()
        ->assertSee('Dashboard HTML')
        // The href points at the open action, never at a token.
        ->assertAttributeContains('@open-artifact', 'href', '/open')
        ->assertAttributeDoesntContain('@open-artifact', 'href', 'token=')
        ->assertAttribute('@open-artifact', 'target', '_blank');

    // Click the control with the browser's own navigation suppressed: the
    // assertion is about the tab the click asks for, not about a tab that
    // would follow the redirect to a real public artifact origin.
    $page->script(<<<'JS'
        window.__artifactOpenTargets = [];
        document.addEventListener('click', (event) => {
            const anchor = event.target.closest('[data-testid="open-artifact"]');
            if (! anchor) {
                return;
            }
            window.__artifactOpenTargets.push([anchor.getAttribute('href'), anchor.getAttribute('target')]);
            event.preventDefault();
        }, true);
        JS);

    $page->click('@open-artifact')->wait(0.5);

    $targets = $page->script('window.__artifactOpenTargets');

    expect($targets)->toHaveCount(1)
        ->and($targets[0][1])->toBe('_blank')
        ->and($targets[0][0])->toContain('/settings/teams/open-users-team/console/artifacts/')
        ->and($targets[0][0])->toEndWith('/open')
        ->and($targets[0][0])->not->toContain('token');
});

test('console_offers_no_open_control_without_a_signing_secret', function () {
    config(['services.artifact_access.token_secret' => null]);

    $page = visit('/login');

    $page->assertNoJavaScriptErrors()
        ->fill('email', 'no-secret-user@example.com')
        ->fill('name', 'No Secret User')
        ->click('Continue with Google')
        ->assertSee('Create your first team')
        ->click('Create team')
        ->assertSee('What your AI makes');

    // The link would 503, so the console does not offer one.
    $page->navigate('/settings/teams/no-secret-users-team/console')
        ->assertNoJavaScriptErrors()
        ->assertSee('Dashboard HTML')
        ->assertMissing('@open-artifact');
});
