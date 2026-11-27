<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\MarkdownRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * RUB-603 evidence: `#slug` links on Markdown artifacts scroll to the section.
 *
 * The first test visits the real artifact page signed in and proves the
 * viewer forwards the hash into the frame src (the `useEffect` in
 * `resources/js/pages/artifacts/show.tsx`). The frame itself points at the
 * artifact's isolated origin, which this harness does not serve, so the
 * second test proves the scroll on a same-origin harness that replicates
 * that forwarding exactly (initial src without the hash, hash appended once
 * after mount): the heading lands inside the frame viewport, the document
 * scrolled, and the PNG shows the section instead of the top of the doc.
 * Both sides render `tests/Fixtures/markdown-heading-ids.md`, whose ids are
 * asserted from the Worker and Laravel renderers in
 * `backend/src/markdown.rs` and `tests/Unit/MarkdownHeadingIdsTest.php`.
 */
const RUB603_BROWSER_ARTIFACT_ID = 'rub603anchor1';
const RUB603_BROWSER_ANCHOR = 'use-artfct-deploy-today';

/** @return array{0: Team, 1: User} */
function rub603BrowserSeed(): array
{
    $owner = User::factory()->create(['name' => 'Heading Owner', 'email' => 'heading-owner@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Heading Users');

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => RUB603_BROWSER_ARTIFACT_ID,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Markdown headings report',
        'description' => 'Seeded for the RUB-603 heading id browser tests.',
        'content_hash' => md5(RUB603_BROWSER_ARTIFACT_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
        'can_change_sharing' => true,
    ]);

    return [$team, $owner];
}

/**
 * Visit a path carrying a hash fragment. `visit()` rebuilds relative URLs
 * from path plus query only (`LaravelHttpServer::rewrite()`), so the probe
 * load learns the server origin and the real load goes in absolute — with
 * the fragment present from the first byte, the way a teammate opens
 * `/a/{id}#{slug}`.
 */
function rub603VisitWithFragment(string $path, string $fragment)
{
    $parts = parse_url(visit($path)->url());
    $origin = $parts['scheme'].'://'.$parts['host'].':'.$parts['port'];

    return visit($origin.$path.$fragment);
}

test('the_viewer_forwards_a_valid_hash_into_the_frame_src', function () {
    // `.localhost` resolves to loopback so the frame's own fetch fails fast;
    // this test is about the src the viewer sets, not about fetching it.
    config([
        'services.artifact_access.token_secret' => 'browser-artifact-secret',
        'services.artifact_access.origin_suffix' => '.localhost',
    ]);

    [, $owner] = rub603BrowserSeed();
    test()->actingAs($owner);

    // Fragment rides in on an absolute URL, which `visit()` passes through
    // untouched — a fresh load carrying the hash, exactly how a teammate
    // opens `/a/{id}#{slug}`.
    $page = rub603VisitWithFragment('/a/'.RUB603_BROWSER_ARTIFACT_ID, '#'.RUB603_BROWSER_ANCHOR);

    $page
        ->assertNoJavaScriptErrors()
        ->assertFragmentIs(RUB603_BROWSER_ANCHOR)
        ->assertVisible('@artifact-frame')
        ->assertScript(
            'document.querySelector("[data-testid=\\"artifact-frame\\"]").getAttribute("src").endsWith("#'.RUB603_BROWSER_ANCHOR.'")',
            true,
        );
});

test('a_fragment_link_scrolls_the_rendered_markdown_to_the_section', function () {
    $source = file_get_contents(dirname(__DIR__).'/Fixtures/markdown-heading-ids.md');

    expect($source)->not->toBeFalse();

    $rendered = (new MarkdownRenderer)->toFullDocument($source);

    Route::get('/__rub603-doc', fn () => response($rendered, 200, ['Content-Type' => 'text/html; charset=utf-8']));

    Route::get('/__rub603-viewer', fn () => response(
        '<!doctype html><title>RUB-603 harness viewer</title>'
        .'<iframe data-testid="artifact-frame" title="Artifact" sandbox="allow-scripts allow-same-origin" src="/__rub603-doc" style="width:100%;height:320px;border:0"></iframe>'
        .'<script>'
        .'var FRAME_FRAGMENT = /^#[A-Za-z0-9_.-]{1,128}$/;'
        .'var frame = document.querySelector("[data-testid=\\"artifact-frame\\"]");'
        .'if (FRAME_FRAGMENT.test(window.location.hash)) { frame.src = "/__rub603-doc" + window.location.hash; }'
        .'</script>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8'],
    ));

    [, $owner] = rub603BrowserSeed();
    test()->actingAs($owner);

    $page = rub603VisitWithFragment('/__rub603-viewer', '#'.RUB603_BROWSER_ANCHOR);

    $page
        ->assertNoJavaScriptErrors()
        ->assertScript(
            '(() => { const doc = document.querySelector("[data-testid=\\"artifact-frame\\"]").contentDocument;'
            .' const el = doc.querySelector("#'.RUB603_BROWSER_ANCHOR.'");'
            .' if (! el) { return "missing"; }'
            .' const r = el.getBoundingClientRect();'
            .' const vh = doc.documentElement.clientHeight;'
            .' return (r.top >= -2 && r.top < vh) ? "in-view" : ("top=" + Math.round(r.top) + ",vh=" + vh); })()',
            'in-view',
        )
        ->assertScript(
            'document.querySelector("[data-testid=\\"artifact-frame\\"]").contentDocument.documentElement.scrollTop > 0',
            true,
        )
        ->screenshot(filename: 'rub603-heading-anchor');
});
