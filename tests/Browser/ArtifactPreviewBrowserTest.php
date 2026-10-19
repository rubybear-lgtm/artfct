<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('hostile preview content cannot run scripts or load network resources even when opened directly', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    test()->actingAs($member);
    config(['services.artifact_access.token_secret' => null]);

    $requests = new ArrayObject;
    Route::get('/__preview-probe/{kind}', function (string $kind) use ($requests) {
        $requests[] = $kind;

        return response('body { color: red; }')->header('Content-Type', 'text/css');
    });

    $html = <<<'HTML'
        <!doctype html><html><head><title>Hostile preview</title>
        <style>@import url('/__preview-probe/import'); body { background: rgb(253, 246, 227); }
        .network { background-image: url('/__preview-probe/background'); }</style>
        <link rel="stylesheet" href="/__preview-probe/style">
        <script src="/__preview-probe/script"></script>
        </head><body><h1>Static preview content</h1>
        <div class="network">Embedded content stays visible</div>
        <img id="embedded" alt="Embedded pixel" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7">
        <img src="/__preview-probe/image" onerror="document.documentElement.dataset.executed = 'yes'">
        <iframe src="/__preview-probe/frame"></iframe>
        <script>
            document.documentElement.dataset.executed = 'yes';
            fetch('/__preview-probe/fetch');
            navigator.sendBeacon('/__preview-probe/beacon', 'secret');
            try { parent.document.documentElement.dataset.compromised = 'yes'; } catch (_) {}
        </script></body></html>
        HTML;

    /** @var FakeArtifactContentSource $source */
    $source = app(ArtifactContentSource::class);
    // The preview applies the Private rule itself, so a seed that omits the
    // sharing level fails closed now; `team` keeps the hostile payload shown.
    $source->seed($team->slug, 'hostile-preview', $html, sharing: 'team');

    // The collections page renders only artifacts the directory says this
    // member may see, so the stack has to be seeded there too for the embedded
    // preview to be exercised.
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => 'hostile-preview',
        'org_id' => $team->slug,
        'user_id' => $member->id,
        'title' => 'Hostile preview',
        'description' => null,
        'content_hash' => md5('hostile-preview'),
        'created_at' => now()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => null, 'repo_url' => null, 'commit_sha' => null],
    ]);

    // The same payload without the preview headers proves the probes detect
    // script execution and outbound loads rather than passing vacuously.
    Route::get('/__preview-control', fn () => response($html));
    visit('/__preview-control')
        ->assertScript("document.documentElement.dataset.executed === 'yes'");
    expect($requests->getArrayCopy())->toContain('fetch', 'image', 'script', 'frame', 'style', 'import', 'background');
    $requests->exchangeArray([]);

    $previewUrl = route('teams.artifacts.preview', ['team' => $team, 'artifactId' => 'hostile-preview'], absolute: false);
    $direct = visit($previewUrl)
        ->assertSee('Static preview content')
        ->assertScript('document.documentElement.dataset.executed === undefined')
        ->assertScript('getComputedStyle(document.body).backgroundColor', 'rgb(253, 246, 227)')
        ->assertScript("document.querySelector('#embedded').naturalWidth", 1)
        ->assertNoJavaScriptErrors();

    expect($requests->getArrayCopy())->toBe([]);

    Route::get('/__preview-frame', fn () => response(
        '<!doctype html><title>Preview host</title><h1>Preview host</h1><iframe title="Artifact preview" sandbox="" src="'.e($previewUrl).'" onload="document.documentElement.dataset.loaded = \'yes\'"></iframe>'
    ));
    $direct->navigate('/__preview-frame')
        ->assertScript("document.documentElement.dataset.loaded === 'yes'")
        ->assertScript('document.documentElement.dataset.compromised === undefined')
        ->assertNoJavaScriptErrors();

    expect($requests->getArrayCopy())->toBe([]);

    $collection = Collection::factory()->create([
        'team_id' => $team->id, 'created_by_user_id' => $member->id,
        'name' => 'Hostile document stack',
    ]);
    $collection->artifacts()->create(['artifact_id' => 'hostile-preview', 'added_at' => now()]);
    $direct->navigate(route('teams.collections.index', $team))->resize(390, 850)->wait(0.2)
        ->assertScript('document.querySelector("iframe") !== null')
        ->click("@collection-toggle-{$collection->id}")->wait(0.2)
        ->assertScript('document.documentElement.dataset.compromised === undefined')
        ->assertNoJavaScriptErrors();
    expect($requests->getArrayCopy())->toBe([]);
});
