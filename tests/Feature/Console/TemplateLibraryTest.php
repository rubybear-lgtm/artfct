<?php

use App\Contracts\UsageContract;
use App\Enums\FeatureReleaseState;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\FeatureRelease;
use App\Models\Team;
use App\Models\TemplatePullRecord;
use App\Services\Billing\FakeUsage;
use App\Services\FeatureFlags;
use App\Services\Templates\TemplateFeatures;
use Illuminate\Support\Facades\Http;

/*
 * Spec 21 mechanism, library surface: the console shows the template
 * gallery only when released, and a Use-template post (one request id per
 * dialog) pulls through the same idempotent path as `pull_template`, so a
 * double click still makes one board.
 */

beforeEach(function () {
    configureArtifactLinks();
    configureSigning(testSigningKey());
    fixtureCatalog();
});

function enableTemplateLibrary(Team $team, bool $db = true): void
{
    FeatureRelease::query()->updateOrCreate(
        ['key' => TemplateFeatures::ARTIFACT_TEMPLATES, 'environment' => app()->environment()],
        ['state' => FeatureReleaseState::SelectedTeams, 'selected_team_ids' => [$team->id]],
    );

    if ($db) {
        FeatureRelease::query()->updateOrCreate(
            ['key' => 'artifact-db', 'environment' => app()->environment()],
            ['state' => FeatureReleaseState::SelectedTeams, 'selected_team_ids' => [$team->id]],
        );
    }

    app(FeatureFlags::class)->flush();
}

function fakeLibraryWorker(string $id = ARTIFACT_LINK_ID, array $missing = []): void
{
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response([
            'id' => $id, 'version' => 1, 'url' => 'https://worker.test/p/'.$id,
            'tier' => 'secure', 'sharing' => 'team', 'missing_files' => $missing,
        ], 201),
        'worker.test/v1/artifacts/*/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);
}

function pullUrl(Team $team, string $template = 'fixture'): string
{
    return "/settings/teams/{$team->slug}/console/templates/{$template}/pull";
}

test('the_templates_section_is_hidden_until_released', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('templatesReleased', false)
            ->where('templates', [])
            ->where('canPullTemplates', true));
});

test('a_released_team_sees_the_template_row', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $member = memberOfTeam($team, TeamRole::Member);
    enableTemplateLibrary($team);

    test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('templatesReleased', true)
            ->where('templates.0.key', 'fixture')
            ->where('templates.0.title', 'Fixture board')
            ->where('templates.0.available', true)
            ->where('templates.0.unavailable_reason', null));
});

test('a_template_needing_an_unreleased_feature_lists_as_unavailable', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $member = memberOfTeam($team, TeamRole::Member);
    enableTemplateLibrary($team, db: false);

    test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('templatesReleased', true)
            ->where('templates.0.available', false)
            ->where('templates.0.unavailable_reason', fn (?string $reason): bool => is_string($reason) && str_contains($reason, 'artifact-db')));
});

test('a_viewer_sees_templates_but_gets_no_use_control', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $viewer = memberOfTeam($team, TeamRole::Viewer);
    enableTemplateLibrary($team);

    test()->actingAs($viewer)
        ->get("/settings/teams/{$team->slug}/console")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('templatesReleased', true)
            ->where('canPullTemplates', false));
});

test('a_double_submit_with_one_request_id_makes_one_board', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $member = memberOfTeam($team, TeamRole::Member);
    enableTemplateLibrary($team);
    fakeLibraryWorker();

    $post = fn () => test()->actingAs($member)->post(pullUrl($team), ['request_id' => 'req-dialog-1']);

    $post()->assertRedirect("/settings/teams/{$team->slug}/console");
    $post()->assertRedirect("/settings/teams/{$team->slug}/console");

    Http::assertSentCount(1, fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts'));

    expect(TemplatePullRecord::query()->where('team_id', $team->id)->count())->toBe(1);
});

test('two_pulls_without_a_request_id_make_two_boards', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $member = memberOfTeam($team, TeamRole::Member);
    enableTemplateLibrary($team);
    fakeLibraryWorker();

    test()->actingAs($member)->post(pullUrl($team))->assertRedirect("/settings/teams/{$team->slug}/console");
    test()->actingAs($member)->post(pullUrl($team))->assertRedirect("/settings/teams/{$team->slug}/console");

    Http::assertSentCount(2, fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts'));

    expect(TemplatePullRecord::query()->where('team_id', $team->id)->count())->toBe(0);
});

test('pulling_an_unknown_template_is_a_404', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $member = memberOfTeam($team, TeamRole::Member);
    enableTemplateLibrary($team);
    fakeLibraryWorker();

    test()->actingAs($member)->post(pullUrl($team, 'missing'))->assertNotFound();
});

test('pulling_before_release_is_a_404', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $member = memberOfTeam($team, TeamRole::Member);
    fakeLibraryWorker();

    test()->actingAs($member)->post(pullUrl($team))->assertNotFound();
});

test('a_viewer_cannot_pull', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $viewer = memberOfTeam($team, TeamRole::Viewer);
    enableTemplateLibrary($team);
    fakeLibraryWorker();

    test()->actingAs($viewer)->post(pullUrl($team))->assertForbidden();

    Http::assertNothingSent();
});
