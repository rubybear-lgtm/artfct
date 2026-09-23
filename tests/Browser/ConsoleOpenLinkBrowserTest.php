<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactContentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * The console's Open control, exercised in a browser (RUB-365, RUB-367).
 *
 * What only a browser can prove, and what it cannot:
 *
 * A secure artifact's link is minted into
 * `https://<slug>--<id>.artfct.dev/p/{id}?token=...`, on an isolated origin
 * this harness does not serve: `pest-plugin-browser` serves the app in-process
 * on `http://127.0.0.1:<port>` and exposes no request interception, so a
 * browser here cannot follow that hop — the host neither resolves nor is served
 * over the harness origin. The redirect itself (its target, the token's wire
 * format and binding to the artifact and workspace host, and the mint audit
 * row) is asserted in `Feature/ConsoleTest.php`, where the response can be
 * inspected without following it.
 *
 * A public artifact needs no token, so its hop *is* followable: the app
 * redirects it to `config('app.public_base_url')`, and pointing that at the
 * harness makes this a real navigation a real browser completes. The Worker's
 * side of that origin is stubbed below — the harness runs no Worker — so the
 * test proves the app's half of the hop and the landing, not the Worker's
 * rendering.
 *
 * `?q=` narrows the console to the one seeded row, so the selectors below
 * address exactly one control rather than whichever of the demo rows happens
 * to come first.
 */
function consoleOpenUser(string $teamName = 'Open Users', string $email = 'open-user@example.com'): array
{
    $owner = User::factory()->create(['name' => 'Open User', 'email' => $email]);
    $team = app(CreateTeam::class)->handle($owner, $teamName);

    return [$team, $owner];
}

function seedConsoleArtifact(Team $team, string $tier, bool $revoked = false): void
{
    /** @var ArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => 'RUB367 report',
        'description' => 'Seeded for the console open-link tests.',
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => $revoked ? now()->toIso8601String() : null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>RUB367 artifact body</h1>', tier: $tier);
}

function consoleUrl(Team $team): string
{
    return '/settings/teams/'.$team->slug.'/console?q=RUB367';
}

test('console_open_control_opens_the_signed_link_route_in_a_new_tab', function () {
    // The app under test is served in-process, so this reaches it.
    config(['services.artifact_access.token_secret' => 'browser-artifact-secret']);

    [$team, $owner] = consoleOpenUser();
    seedConsoleArtifact($team, tier: 'secure');
    test()->actingAs($owner);

    $page = visit(consoleUrl($team))
        ->assertNoJavaScriptErrors()
        ->assertSee('RUB367 report')
        // The href points at the open action, never at a token.
        ->assertAttributeContains('@open-artifact', 'href', '/open')
        ->assertAttributeDoesntContain('@open-artifact', 'href', 'token=')
        ->assertAttribute('@open-artifact', 'target', '_blank')
        // The title is the same control, not decoration: opening an artifact
        // from its row's name is the interaction a reader reaches for first.
        ->assertAttributeContains('@open-artifact-title', 'href', '/open')
        ->assertAttributeDoesntContain('@open-artifact-title', 'href', 'token=')
        ->assertAttribute('@open-artifact-title', 'target', '_blank');

    // Click the control with the browser's own navigation suppressed: the
    // assertion is about the tab the click asks for, not about a tab that
    // would follow the redirect to an isolated origin this harness does not
    // serve (see the file docblock).
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
        ->and($targets[0][0])->toContain('/settings/teams/'.$team->slug.'/console/artifacts/')
        ->and($targets[0][0])->toEndWith('/open')
        ->and($targets[0][0])->not->toContain('token');
});

test('following_the_console_open_link_reaches_the_public_artifact_url_without_a_token', function () {
    config(['services.artifact_access.token_secret' => 'browser-artifact-secret']);

    [$team, $owner] = consoleOpenUser('Public Users', 'public-user@example.com');
    seedConsoleArtifact($team, tier: 'public');
    test()->actingAs($owner);

    $page = visit(consoleUrl($team))->assertNoJavaScriptErrors();

    // A public artifact is served by the Worker to anyone, so the browser is
    // sent straight to that URL and no token is minted for it. Pointing the
    // public base at this harness is what makes the hop followable here.
    $harness = (string) config('app.url');

    expect($harness)->toStartWith('http://127.0.0.1');

    config(['app.public_base_url' => $harness]);

    // The Worker's half of that origin: the harness runs no Worker, so the
    // artifact's public URL is stood up here to prove the browser completes the
    // hop and renders what the link points at.
    Route::get('/p/{artifact}', fn (string $artifact) => response(
        '<!doctype html><title>RUB367 artifact</title><h1>RUB367 artifact body '.$artifact.'</h1>'
    ));

    $href = $page->attribute('@open-artifact-title', 'href');

    expect($href)->toEndWith('/open');

    $page->navigate($href)
        ->assertNoJavaScriptErrors()
        ->assertPathIs('/p/'.ARTIFACT_LINK_ID)
        ->assertQueryStringMissing('token')
        ->assertSee('RUB367 artifact body')
        ->assertSee(ARTIFACT_LINK_ID);

    expect($page->url())->toStartWith($harness)
        ->and($page->url())->not->toContain('token');
});

test('a_revoked_artifact_shows_a_disabled_open_control_with_the_reason', function () {
    config(['services.artifact_access.token_secret' => 'browser-artifact-secret']);

    [$team, $owner] = consoleOpenUser('Revoked Users', 'revoked-user@example.com');
    seedConsoleArtifact($team, tier: 'secure', revoked: true);
    test()->actingAs($owner);

    $page = visit(consoleUrl($team))
        ->assertNoJavaScriptErrors()
        ->assertSee('RUB367 report')
        ->assertSee('Revoked');

    // No link at all for a revoked artifact — in the title or in the row's
    // actions — but the control is still shown, disabled, with the reason, so
    // the reader is told why rather than wondering where Open went.
    expect($page->attribute('@open-artifact-disabled', 'aria-disabled'))->toBe('true')
        ->and($page->attribute('@open-artifact-disabled', 'title'))->toContain('revoked')
        ->and($page->attribute('@open-artifact-title-disabled', 'aria-disabled'))->toBe('true')
        ->and($page->attribute('@open-artifact-title-disabled', 'title'))->toContain('revoked');

    $page->assertMissing('@open-artifact')
        ->assertMissing('@open-artifact-title');
});

test('console_offers_no_open_control_without_a_signing_secret', function () {
    config(['services.artifact_access.token_secret' => null]);

    [$team, $owner] = consoleOpenUser('No Secret Users', 'no-secret-user@example.com');
    seedConsoleArtifact($team, tier: 'secure');
    test()->actingAs($owner);

    // The link could only 503 for a secure artifact, so the console does not
    // offer one.
    visit(consoleUrl($team))
        ->assertNoJavaScriptErrors()
        ->assertSee('RUB367 report')
        ->assertMissing('@open-artifact')
        ->assertMissing('@open-artifact-title');
});
