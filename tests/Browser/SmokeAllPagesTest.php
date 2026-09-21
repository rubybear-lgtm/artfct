<?php

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Every page a person can open, by route name. When you add a page, add it to
 * `signedInPages()` or `publicPages()` (it is then smoke-tested for JavaScript
 * errors), or to `coveredElsewhere()`/`notPages()` with a reason. The
 * `every_page_route_is_accounted_for` test fails until you do.
 *
 * @return list<string>
 */
function signedInPages(): array
{
    return [
        'dashboard', 'account.show', 'teams.index', 'teams.edit', 'teams.audit.index',
        'teams.authentication.show', 'teams.billing.show', 'teams.collections.index',
        'console.index', 'teams.governance.show', 'teams.search', 'teams.tokens.index',
        'teams.mcp-connections.index',
    ];
}

/** @return list<string> */
function publicPages(): array
{
    return ['home', 'free', 'blog', 'docs', 'terms', 'privacy', 'login'];
}

/** @return array<string, string> route name => where it is covered */
function coveredElsewhere(): array
{
    return [
        'blog.show' => 'BlogPermalinksTest',
        'invitations.show' => 'InvitationBrowserTest',
        'onboarding.team.show' => 'IdentityBrowserTest (first sign-in)',
        'terms.accept.show' => 'TeamLifecycleBrowserTest (consent)',
    ];
}

/** @return array<string, string> route name => why it is not a page */
function notPages(): array
{
    return [
        'jwks' => 'JSON', 'sitemap' => 'XML', 'authenticate' => 'sign-in callback (redirect)',
        'oauth.metadata' => 'OAuth discovery JSON',
        'oauth.resource-metadata' => 'OAuth protected-resource discovery JSON',
        'mcp.oauth.protected-resource.nested' => 'OAuth protected-resource discovery JSON',
        'oauth.metadata.nested' => 'OAuth discovery JSON (path-inserted)',
        'oauth.organizations' => 'OAuth organization context JSON',
        'api.collections.index' => 'organization-scoped collection directory JSON',
        'oauth.authorize' => 'OAuth authorization handshake and consent flow',
        'sso.authenticate' => 'SSO callback (redirect)', 'sso.login' => 'SSO start (redirect)',
        'teams.audit.export' => 'file download', 'console.export' => 'JSON download',
    ];
}

test('signed_in_pages_have_no_javascript_errors', function () {
    $owner = User::factory()->create(['email' => 'smoke@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Smoke Co');
    test()->actingAs($owner);

    foreach (signedInPages() as $name) {
        $url = match ($name) {
            'dashboard' => route($name, $team),
            'account.show', 'teams.index' => route($name),
            'console.index' => route($name, ['team' => $team->slug]),
            default => route($name, $team),
        };

        visit($url)->assertNoJavaScriptErrors();
    }
});

test('public_pages_have_no_javascript_errors', function () {
    foreach (publicPages() as $name) {
        visit(route($name))->assertNoJavaScriptErrors();
    }
});

test('public docs expose the MCP onboarding path', function () {
    visit(route('docs'))
        ->assertSee('Sign in from the command line')
        ->assertSee('artfct login --oauth')
        ->assertSee('https://artfct.dev/mcp')
        ->assertSee('artifacts:read')
        ->assertNoJavaScriptErrors();
});

test('every_page_route_is_accounted_for', function () {
    $known = array_merge(signedInPages(), publicPages(), array_keys(coveredElsewhere()), array_keys(notPages()));

    $unlisted = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->map(fn ($route) => $route->getName())
        ->filter(fn ($name) => $name !== null && ! str_starts_with($name, 'generated::') && ! str_starts_with($name, 'boost.') && ! in_array($name, ['storage.local', 'storage.local.upload', 'up', 'livewire.update']))
        ->reject(fn ($name) => in_array($name, $known, true))
        ->values()
        ->all();

    expect($unlisted)->toBe([], 'These GET routes are not in the smoke list: '.implode(', ', $unlisted));
});

// Proves the smoke assertion works: a page that throws must make it fail, so this
// test is expected to fail (and is reported as passing when it does).
test('a_page_with_a_javascript_error_fails_the_smoke_assertion', function () {
    Route::get('/__broken-page', fn () => response('<html><body><script>throw new Error("boom");</script>hi</body></html>'));

    visit('/__broken-page')->assertNoJavaScriptErrors();
})->fails();
