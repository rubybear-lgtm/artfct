<?php

use App\Enums\FeatureReleaseState;
use App\Enums\TeamRole;
use App\Http\Middleware\EnsureFeatureIsEnabled;
use App\Http\Middleware\EnsureTeamMembership;
use App\Models\FeatureRelease;
use App\Models\Team;
use App\Services\FeatureFlags;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Facades\Mcp;
use Tests\Fixtures\FeatureFlags\ReleaseTestContextMiddleware;
use Tests\Fixtures\FeatureFlags\ReleaseTestServer;

beforeEach(function () {
    $this->withoutVite();
    config(['features.registry' => [
        'test-feature' => ['name' => 'Test feature', 'description' => 'A release test.', 'expose_to_browser' => true],
        'server-only' => ['name' => 'Server feature', 'description' => 'Not exposed to the browser.'],
    ]]);
    app(FeatureFlags::class)->register();

    Route::middleware(['web', 'auth', EnsureTeamMembership::class])->get('/_release/{current_team:slug}', function (Team $current_team) {
        return Inertia::render('welcome');
    })->middleware(EnsureFeatureIsEnabled::class.':test-feature');

    Mcp::web('/_release-mcp', ReleaseTestServer::class)->middleware(ReleaseTestContextMiddleware::class);
});

test('registered features are off by default and unknown or removed flags never enable', function () {
    $team = Team::factory()->create();
    $flags = app(FeatureFlags::class);
    expect($flags->enabled('test-feature', $team))->toBeFalse()
        ->and($flags->enabled('unknown', $team))->toBeFalse()
        ->and(FeatureRelease::query()->count())->toBe(0);

    FeatureRelease::factory()->create(['key' => 'test-feature', 'state' => FeatureReleaseState::Released]);
    $flags->flush();
    expect($flags->enabled('test-feature', $team))->toBeTrue();
    config(['features.registry' => []]);
    expect($flags->enabled('test-feature', $team))->toBeFalse();
});

test('selection is team scoped and release includes existing and future teams', function () {
    $selected = Team::factory()->create();
    $other = Team::factory()->create();
    $flags = app(FeatureFlags::class);
    $release = FeatureRelease::factory()->create([
        'key' => 'test-feature', 'state' => FeatureReleaseState::SelectedTeams, 'selected_team_ids' => [$selected->id],
    ]);
    expect($flags->enabled('test-feature', $selected))->toBeTrue()
        ->and($flags->enabled('test-feature', $other))->toBeFalse();

    $release->update(['state' => FeatureReleaseState::Released]);
    $flags->flush();
    expect($flags->enabled('test-feature', $other))->toBeTrue()
        ->and($flags->enabled('test-feature', Team::factory()->create()))->toBeTrue();

    $release->update(['state' => FeatureReleaseState::Off]);
    $flags->flush();
    expect($flags->enabled('test-feature', $selected))->toBeFalse()
        ->and($release->fresh()->selected_team_ids)->toBe([$selected->id]);
});

test('staging and production have independent persisted rules', function () {
    $team = Team::factory()->create();
    FeatureRelease::factory()->create(['key' => 'test-feature', 'environment' => 'staging', 'state' => FeatureReleaseState::Released]);
    FeatureRelease::factory()->create(['key' => 'test-feature', 'environment' => 'production']);
    app()['env'] = 'staging';
    expect(app(FeatureFlags::class)->enabled('test-feature', $team))->toBeTrue();
    app(FeatureFlags::class)->flush();
    app()['env'] = 'production';
    expect(app(FeatureFlags::class)->enabled('test-feature', $team))->toBeFalse();
});

test('browser entry points refuse disabled access and expose only browser flags for the authorized team', function () {
    $team = Team::factory()->create();
    $user = memberOfTeam($team, TeamRole::Admin);
    $release = FeatureRelease::factory()->create(['key' => 'test-feature']);
    $this->actingAs($user)->get('/_release/'.$team->slug)->assertNotFound();
    $release->update(['state' => FeatureReleaseState::Released]);
    $this->get('/_release/'.$team->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('features.test-feature', true)->missing('features.server-only'));
    $stranger = Team::factory()->create();
    $this->get('/_release/'.$stranger->slug)->assertForbidden();
    $release->update(['state' => FeatureReleaseState::Off]);
    $this->get('/_release/'.$team->slug)->assertNotFound();
});

test('MCP discovery and direct invocation use the connection team rather than the browser team', function () {
    $selected = Team::factory()->create();
    $other = Team::factory()->create();
    $user = memberOfTeam($selected, TeamRole::Admin);
    $user->switchTeam($selected);
    $this->actingAs($user);
    FeatureRelease::factory()->create(['key' => 'test-feature', 'state' => FeatureReleaseState::SelectedTeams, 'selected_team_ids' => [$selected->id]]);

    $call = fn (Team $team, string $method) => $this->withHeader('X-Test-Team', (string) $team->id)->postJson('/_release-mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => ['name' => 'release-test-tool', 'arguments' => []],
    ]);
    $call($other, 'tools/list')->assertJsonCount(0, 'result.tools');
    $call($other, 'tools/call')->assertJsonPath('error.code', -32602);
    $call($selected, 'tools/list')->assertJsonPath('result.tools.0.name', 'release-test-tool');
    $call($selected, 'tools/call')->assertJsonPath('result.content.0.text', 'Feature executed');
});

test('a completed queue job clears decisions before the next job runs', function () {
    $team = Team::factory()->create();
    $release = FeatureRelease::factory()->create(['key' => 'test-feature', 'state' => FeatureReleaseState::Released]);
    expect(app(FeatureFlags::class)->enabled('test-feature', $team))->toBeTrue();
    $release->update(['state' => FeatureReleaseState::Off]);
    Event::dispatch(new JobProcessed('sync', Mockery::mock(Job::class)));
    expect(app(FeatureFlags::class)->enabled('test-feature', $team))->toBeFalse();
});

test('deleted and unpersisted teams cannot receive a release', function () {
    FeatureRelease::factory()->create(['key' => 'test-feature', 'state' => FeatureReleaseState::Released]);
    $team = Team::factory()->create();
    $team->delete();
    expect(app(FeatureFlags::class)->enabled('test-feature', $team))->toBeFalse()
        ->and(app(FeatureFlags::class)->enabled('test-feature', Team::factory()->make()))->toBeFalse();
});
