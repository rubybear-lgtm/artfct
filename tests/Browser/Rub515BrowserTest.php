<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Models\ArtifactComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * RUB-515 browser evidence: markdown renders as HTML (h1 + table) inside a
 * sandboxed viewer frame, and comment bodies with HTML are escaped in the UI.
 */
const RUB515_BROWSER_ARTIFACT_ID = 'mdk123abc4567';

test('a_markdown_document_renders_h1_and_table_inside_a_sandboxed_viewer_frame', function () {
    // Mirrors backend/src/markdown.rs::render_markdown_document for
    // `# Hello` + a two-column table — the Worker serves this as text/html,
    // never application/octet-stream (RUB-515 item 1).
    $rendered = <<<'HTML'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Hello</title>
<style>body{font-family:system-ui,sans-serif;margin:1.5rem;color:#262624;background:#F7F5F2}table{border-collapse:collapse}td,th{border:1px solid #DEDAD2;padding:.4rem .6rem}</style>
</head><body>
<h1>Hello</h1>
<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>
</body></html>
HTML;

    Route::get('/__rub515-markdown', fn () => response($rendered, 200, [
        'Content-Type' => 'text/html; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
    ]));

    Route::get('/__rub515-viewer', fn () => response(
        '<!doctype html><title>Viewer</title>'
        .'<iframe data-testid="artifact-frame" title="Artifact" sandbox="allow-scripts allow-same-origin" '
        .'src="/__rub515-markdown"></iframe>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8'],
    ));

    $page = visit('/__rub515-markdown')
        ->assertNoJavaScriptErrors()
        ->assertSee('Hello')
        ->assertSee('A')
        ->assertSee('1')
        ->assertScript('document.querySelector("h1") !== null')
        ->assertScript('document.querySelector("table") !== null')
        ->assertScript('document.contentType', 'text/html');

    expect($page->driver()->getCurrentURL())->toContain('/__rub515-markdown');

    visit('/__rub515-viewer')
        ->assertNoJavaScriptErrors()
        ->assertVisible('@artifact-frame')
        ->assertScript(
            'document.querySelector("[data-testid=\\"artifact-frame\\"]").contentDocument.querySelector("h1").textContent',
            'Hello',
        )
        ->assertScript(
            'document.querySelector("[data-testid=\\"artifact-frame\\"]").contentDocument.querySelector("table td").textContent',
            '1',
        );
});

test('comment_bodies_with_script_and_onerror_are_escaped_in_the_viewer_ui', function () {
    config([
        'services.artifact_access.token_secret' => 'browser-artifact-secret',
        'services.artifact_access.origin_suffix' => '.localhost',
    ]);

    $owner = User::factory()->create(['name' => 'Comment Owner', 'email' => 'rub515-comment@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Rub515 Comments');
    /** @var ArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => RUB515_BROWSER_ARTIFACT_ID,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Comment escape report',
        'description' => 'RUB-515 item 18',
        'content_hash' => md5(RUB515_BROWSER_ARTIFACT_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'can_change_sharing' => true,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    ArtifactComment::query()->create([
        'team_id' => $team->id,
        'artifact_id' => RUB515_BROWSER_ARTIFACT_ID,
        'author_user_id' => (string) $owner->id,
        'author_name' => $owner->name,
        'body' => '<script>window.__rub515_xss = 1</script><img src=x onerror="window.__rub515_xss = 1">plain note',
        'parent_id' => null,
    ]);

    test()->actingAs($owner);

    $page = visit('/a/'.RUB515_BROWSER_ARTIFACT_ID)
        ->assertNoJavaScriptErrors()
        ->click('@artifact-panel-toggle')
        ->assertVisible('@artifact-panel')
        ->assertSee('Comments')
        ->assertSee('plain note')
        ->assertSee('<script>')
        ->assertSee('onerror=')
        ->assertScript('window.__rub515_xss === undefined');

    expect($page->script('document.body.innerHTML'))->toContain('&lt;script&gt;')
        ->and($page->script('document.body.innerHTML'))->not->toContain('<script>window.__rub515_xss');
});
