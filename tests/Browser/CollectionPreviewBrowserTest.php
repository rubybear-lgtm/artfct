<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{Team, User, Collection} */
function previewCollectionFixture(TeamRole $role, int $artifactCount = 1): array
{
    $team = Team::factory()->create(['name' => 'Preview team']);
    $user = memberOfTeam($team, $role);
    $user->switchTeam($team);
    $collection = Collection::factory()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => str_repeat('Collection', 10),
        'canonical' => true,
    ]);

    for ($index = 0; $index < $artifactCount; $index++) {
        $id = 'preview-document-'.$index;
        $collection->artifacts()->create(['artifact_id' => $id, 'added_at' => now()]);
        app(ArtifactContentSource::class)->seed($team->slug, $id, '<!doctype html><style>body{background:#fdf6e3}</style><h1>Preview document '.$index.'</h1>');
        app(ArtifactDirectory::class)->seedArtifact([
            'id' => $id, 'org_id' => $team->slug, 'user_id' => $user->id,
            'title' => str_repeat('LongArtifactTitle', 8).' '.$index,
            'description' => 'Preview fixture', 'content_hash' => md5($id),
            'created_at' => now()->toIso8601String(), 'revoked_at' => null,
            'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
        ]);
    }

    return [$team, $user, $collection];
}

test('collection previews scale and stay inside their cards at all four widths', function (int $width, int $columns) {
    config(['services.artifact_access.token_secret' => null]);
    [$team, $user, $collection] = previewCollectionFixture(TeamRole::Admin, 3);
    test()->actingAs($user);

    $page = visit(route('teams.collections.index', $team))->resize($width, 850)->wait(0.2);
    $page->assertScript("getComputedStyle(document.querySelector('[data-testid=collection-row-0]')).gridTemplateColumns.split(' ').length", $columns)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript('document.querySelectorAll("iframe").length', 1)
        ->assertScript('document.querySelector("iframe").getAttribute("aria-hidden")', 'true')
        ->assertScript('Array.from(document.querySelectorAll("iframe")).every(frame => frame.getAttribute("sandbox") === "" && frame.getAttribute("loading") === "lazy" && frame.getAttribute("referrerpolicy") === "no-referrer" && frame.tabIndex === -1 && getComputedStyle(frame).pointerEvents === "none")')
        ->assertScript('document.querySelector("iframe").title.startsWith("Preview of LongArtifactTitle")')
        ->assertNoJavaScriptErrors();

    $page->click("@collection-toggle-{$collection->id}")->wait(0.2);
    $page->script("document.getElementById('collection-panel-{$collection->id}').scrollIntoView()");
    $page->wait(0.2)
        ->assertScript("document.querySelector('[data-testid=collection-artifact-{$collection->id}-preview-document-0] iframe') !== null")
        ->assertScript("document.querySelector('[data-testid=collection-artifact-{$collection->id}-preview-document-0] iframe').getAttribute('aria-hidden') === null")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript(<<<'JS'
            Array.from(document.querySelectorAll('iframe')).every(frame => {
                const frameRect = frame.getBoundingClientRect();
                const containerRect = frame.parentElement.parentElement.getBoundingClientRect();
                return frameRect.width <= containerRect.width + 1
                    && Math.abs(frameRect.width / frameRect.height - 1.6) < 0.02
                    && getComputedStyle(frame.parentElement.parentElement).overflow === 'hidden';
            })
            JS)
        ->assertMissing("@collection-open-{$collection->id}-preview-document-0")
        ->assertNoJavaScriptErrors();
})->with([[320, 1], [390, 1], [768, 2], [1280, 3]]);

test('large collections mount only nearby previews and unload them when scrolled away', function () {
    [$team, $user, $collection] = previewCollectionFixture(TeamRole::Member, 30);
    test()->actingAs($user);

    $page = visit(route('teams.collections.index', $team))->resize(390, 650)->wait(0.2);
    $page->assertScript('document.querySelectorAll("iframe").length', 1)
        ->click("@collection-toggle-{$collection->id}")->wait(0.2);

    $page->assertScript('document.querySelectorAll("iframe").length < 10')
        ->assertScript('document.querySelector(\'iframe[src*="preview-document-29/"]\') === null');

    $page->script("document.querySelector('[data-testid=collection-artifact-{$collection->id}-preview-document-29]').scrollIntoView()");
    $page->wait(0.2)
        ->assertScript('document.querySelector(\'iframe[src*="preview-document-29/"]\') !== null')
        ->assertScript('document.querySelectorAll("iframe").length < 10')
        ->assertScript("document.querySelector('[data-testid=collection-stack-{$collection->id}] iframe') === null")
        ->assertNoJavaScriptErrors();

    $page->click("@collection-toggle-{$collection->id}")->wait(0.2)
        ->assertMissing("@collection-panel-{$collection->id}")
        ->assertScript('document.querySelectorAll("iframe").length <= 1')
        ->assertNoJavaScriptErrors();
});

test('stack management permissions remain distinct from expansion', function (TeamRole $role) {
    config(['services.artifact_access.token_secret' => 'preview-browser-secret']);
    [$team, $user, $collection] = previewCollectionFixture($role);
    test()->actingAs($user);

    $page = visit(route('teams.collections.index', $team))->resize(320, 850);
    $page->click("@collection-toggle-{$collection->id}")->wait(0.2)
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
        ->assertPresent("@collection-open-{$collection->id}-preview-document-0")
        ->assertNoJavaScriptErrors();

    if ($role === TeamRole::Viewer) {
        $page->assertDontSee('Rename')->assertDontSee('Unpin canonical')
            ->assertMissing("@collection-remove-{$collection->id}-preview-document-0")
            ->assertMissing('input[aria-label="Collection name"]');
    } else {
        $page->assertSee('Rename')->assertPresent("@collection-remove-{$collection->id}-preview-document-0");
        $page->click("@collection-remove-{$collection->id}-preview-document-0")
            ->assertSee('Remove from collection')
            ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
            ->assertNoJavaScriptErrors();
        $page->click('Keep it');

        if ($role === TeamRole::Admin) {
            $page->click('Unpin canonical')->waitForText('Unpinned.')
                ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
                ->assertSee('Pin as canonical');
            $page->click('Pin as canonical')->waitForText('Pinned.')
                ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
                ->assertSee('Unpin canonical');
        } else {
            $page->assertDontSee('Unpin canonical')->assertDontSee('Pin as canonical');
        }
    }

    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
})->with([TeamRole::Viewer, TeamRole::Member, TeamRole::Admin]);
