<?php

use App\Enums\AuditEventType;
use App\Enums\FeatureReleaseState;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\FeatureRelease;
use App\Models\OperatorAuditEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\FeatureFlags;
use App\Services\Operator\Actions\UpdateFeatureRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['features.registry' => ['test-feature' => ['name' => 'Test feature', 'description' => 'An unreleased feature.']]]);
    app(FeatureFlags::class)->register();
    $this->staff = User::factory()->create();
    $this->staff->forceFill(['staff_at' => now()])->save();
});

test('only staff can inspect or change releases', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    foreach ([null, $admin] as $visitor) {
        if ($visitor !== null) {
            $this->actingAs($visitor);
        }
        $this->get('/ops/features')->assertNotFound();
        $this->get('/ops/features/test-feature')->assertNotFound();
        $this->put('/ops/features/test-feature', ['state' => 'released', 'team_slugs' => [], 'confirm' => true, 'reason' => 'Release the feature'])->assertNotFound();
    }
    expect(FeatureRelease::query()->count())->toBe(0)
        ->and(OperatorAuditEvent::query()->count())->toBe(0);
    $this->actingAs($this->staff)->get('/ops/features')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('releases.0.state', 'Off')->where('environment', 'testing'));
});

test('staff can select teams release and pause while retaining the selection', function () {
    $team = Team::factory()->create(['slug' => 'beta-team']);
    $other = Team::factory()->create();
    $flags = app(FeatureFlags::class);
    expect($flags->enabled('test-feature', $team))->toBeFalse();
    $save = fn (string $state) => $this->actingAs($this->staff)->put('/ops/features/test-feature', [
        'state' => $state, 'team_slugs' => ['beta-team'], 'reason' => 'Validate this release', 'confirm' => true,
    ])->assertRedirect('/ops/features/test-feature')->assertSessionHasNoErrors();

    $save('selected');
    expect($flags->enabled('test-feature', $team))->toBeTrue()
        ->and($flags->enabled('test-feature', $other))->toBeFalse();
    $save('released');
    expect($flags->enabled('test-feature', $other))->toBeTrue()
        ->and($flags->enabled('test-feature', Team::factory()->create()))->toBeTrue();
    $save('off');
    expect($flags->enabled('test-feature', $team))->toBeFalse()
        ->and(FeatureRelease::query()->sole()->selected_team_ids)->toBe([$team->id]);
    $save('selected');
    expect($flags->enabled('test-feature', $team))->toBeTrue()
        ->and($flags->enabled('test-feature', $other))->toBeFalse()
        ->and(OperatorAuditEvent::query()->count())->toBe(4)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::OperatorFeatureReleaseChanged)->count())->toBe(0);

    $audit = OperatorAuditEvent::query()->latest('id')->first();
    expect($audit->staff_user_id)->toBe($this->staff->id)
        ->and($audit->reason)->toContain('Off → Selected teams', 'beta-team')
        ->and($audit->target)->toBe('testing:feature:test-feature');
    $this->get('/ops/features/test-feature')->assertInertia(fn (Assert $page) => $page
        ->where('state', 'selected')->where('team_slugs', ['beta-team'])->has('history', 4));
});

test('a release requires a valid state live teams reason and confirmation', function (array $override, string $error) {
    $payload = array_replace(['state' => 'released', 'team_slugs' => [], 'reason' => 'Validate this release', 'confirm' => true], $override);
    $this->actingAs($this->staff)->put('/ops/features/test-feature', $payload)->assertSessionHasErrors($error);
    expect(FeatureRelease::query()->count())->toBe(0)->and(OperatorAuditEvent::query()->count())->toBe(0);
})->with([
    'invalid state' => [['state' => 'yes'], 'state'],
    'missing reason' => [['reason' => ''], 'reason'],
    'no confirmation' => [['confirm' => false], 'confirm'],
    'unknown team' => [['team_slugs' => ['missing-team']], 'team_slugs.0'],
    'duplicate team' => [['team_slugs' => ['missing-team', 'missing-team']], 'team_slugs.1'],
]);

test('unknown feature keys and deleted teams cannot be enabled', function () {
    $team = Team::factory()->create();
    $team->delete();
    $this->actingAs($this->staff)->get('/ops/features/unknown')->assertNotFound();
    $this->put('/ops/features/unknown', ['state' => 'released', 'team_slugs' => [], 'reason' => 'Validate this release', 'confirm' => true])->assertNotFound();
    $this->put('/ops/features/test-feature', ['state' => 'selected', 'team_slugs' => [$team->slug], 'reason' => 'Validate this release', 'confirm' => true])->assertSessionHasErrors('team_slugs.0');
    expect(FeatureRelease::query()->count())->toBe(0);
});

test('audit failure rolls back the release rules', function () {
    $release = FeatureRelease::factory()->create(['key' => 'test-feature']);
    Event::listen('eloquent.creating: '.OperatorAuditEvent::class, function (): never {
        throw new RuntimeException('Audit unavailable');
    });
    $this->actingAs($this->staff)->put('/ops/features/test-feature', ['state' => 'released', 'team_slugs' => [], 'reason' => 'Validate this release', 'confirm' => true])->assertServerError();
    expect($release->fresh()->state)->toBe(FeatureReleaseState::Off);
});

test('an audited change invalidates decisions immediately within the same request', function () {
    $team = Team::factory()->create();
    $flags = app(FeatureFlags::class);
    $request = Request::create('/ops/features/test-feature', 'PUT');
    $request->setUserResolver(fn () => $this->staff);

    expect($flags->enabled('test-feature', $team))->toBeFalse();
    app(UpdateFeatureRelease::class)->run($request, 'test-feature', FeatureReleaseState::Released, [], 'Release after verification');
    expect($flags->enabled('test-feature', $team))->toBeTrue();
    app(UpdateFeatureRelease::class)->run($request, 'test-feature', FeatureReleaseState::Off, [], 'Pause after verification');
    expect($flags->enabled('test-feature', $team))->toBeFalse();
});

test('changing one environment leaves the other environment untouched', function () {
    $production = FeatureRelease::factory()->create(['key' => 'test-feature', 'environment' => 'production']);
    $this->actingAs($this->staff)->put('/ops/features/test-feature', ['state' => 'released', 'team_slugs' => [], 'reason' => 'Validate this release', 'confirm' => true, 'environment' => 'production'])->assertSessionHasNoErrors();
    expect($production->fresh()->state)->toBe(FeatureReleaseState::Off)
        ->and(FeatureRelease::query()->where('environment', 'testing')->sole()->state)->toBe(FeatureReleaseState::Released);
});
