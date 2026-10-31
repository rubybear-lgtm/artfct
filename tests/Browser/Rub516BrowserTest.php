<?php

/**
 * RUB-516 items 5–6: browser evidence for a signed-in deleted-artifact 404
 * (not a login redirect) and escaped comment HTML in the viewer panel.
 */

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Models\ArtifactComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const RUB516_BROWSER_ARTIFACT_ID = 'rub516del0001';

test('rub516_item5_signed_in_member_sees_404_for_deleted_artifact_not_login', function () {
    config([
        'services.artifact_access.token_secret' => 'browser-artifact-secret',
        'services.artifact_access.origin_suffix' => '.localhost',
    ]);

    $owner = User::factory()->create(['name' => 'Rub516 Owner', 'email' => 'rub516-deleted@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Rub516 Deleted');
    /** @var ArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => RUB516_BROWSER_ARTIFACT_ID,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Deleted report',
        'description' => 'RUB-516 item 5',
        'content_hash' => md5(RUB516_BROWSER_ARTIFACT_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => now()->toIso8601String(),
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'can_change_sharing' => true,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($owner);

    $page = visit('/a/'.RUB516_BROWSER_ARTIFACT_ID)
        ->assertNoJavaScriptErrors()
        ->assertPathIs('/a/'.RUB516_BROWSER_ARTIFACT_ID)
        ->assertDontSee('Sign in')
        ->assertDontSee('Log in');

    // Laravel's error page for 404; must not redirect to /login.
    expect($page->script('window.location.pathname'))->toBe('/a/'.RUB516_BROWSER_ARTIFACT_ID)
        ->and($page->script('document.body.innerText.toLowerCase().includes("not found") || document.title.toLowerCase().includes("404") || document.body.innerText.includes("404")'))
        ->toBeTrue();
});

test('rub516_item6_comment_script_and_onerror_render_escaped', function () {
    config([
        'services.artifact_access.token_secret' => 'browser-artifact-secret',
        'services.artifact_access.origin_suffix' => '.localhost',
    ]);

    $owner = User::factory()->create(['name' => 'Rub516 Commenter', 'email' => 'rub516-comment@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Rub516 Comments');
    /** @var ArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $artifactId = 'rub516cmt0002';
    $directory->seedArtifact([
        'id' => $artifactId,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Comment escape report',
        'description' => 'RUB-516 item 6',
        'content_hash' => md5($artifactId),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'can_change_sharing' => true,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    ArtifactComment::query()->create([
        'team_id' => $team->id,
        'artifact_id' => $artifactId,
        'author_user_id' => (string) $owner->id,
        'author_name' => $owner->name,
        'body' => '<script>window.__rub516_xss = 1</script><img src=x onerror="window.__rub516_xss = 1">plain note',
        'parent_id' => null,
    ]);

    test()->actingAs($owner);

    visit('/a/'.$artifactId)
        ->assertNoJavaScriptErrors()
        ->click('@artifact-panel-toggle')
        ->assertVisible('@artifact-panel')
        ->assertSee('Comments')
        ->assertSee('plain note')
        ->assertSee('<script>')
        ->assertSee('onerror=')
        ->assertScript('window.__rub516_xss === undefined')
        ->assertScript(
            'document.querySelector("[data-testid=\\"artifact-panel\\"]").innerHTML.includes("&lt;script&gt;")',
            true,
        )
        ->assertScript(
            'document.querySelector("[data-testid=\\"artifact-panel\\"]").innerHTML.includes("<script>window.__rub516_xss")',
            false,
        );
});
