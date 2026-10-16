<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.worker.base_url' => 'https://worker.test',
        'services.worker.limits_write_secret' => 'limits-secret',
    ]);
});

test('an_admin_turning_public_sharing_off_pushes_settings_and_audits_each_downgrade', function () {
    Http::fake([
        'worker.test/v1/internal/org-settings' => Http::response([
            'org' => 'acme',
            'public_sharing_allowed' => false,
            'downgraded' => ['a1', 'a2'],
        ]),
    ]);
    $team = Team::factory()->create(['slug' => 'acme']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'public_sharing_allowed' => false])
        ->assertRedirect(route('teams.edit', ['team' => $team->slug]));

    expect($team->fresh()->public_sharing_allowed)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://worker.test/v1/internal/org-settings'
        && $request->hasHeader('Authorization', 'Bearer limits-secret')
        && $request['org'] === 'acme'
        && $request['public_sharing_allowed'] === false);

    $downgrades = AuditEvent::query()->where('event_type', AuditEventType::ArtifactSharingChanged)->get();

    expect($downgrades)->toHaveCount(2)
        ->and($downgrades->pluck('target')->all())->toEqualCanonicalizing([
            'artifact:a1 public->team (public sharing turned off)',
            'artifact:a2 public->team (public sharing turned off)',
        ])
        ->and($downgrades->every(fn (AuditEvent $event): bool => $event->team_id === $team->id && $event->actor === (string) $admin->id))->toBeTrue();

    expect(AuditEvent::query()->where('event_type', AuditEventType::PublicSharingChanged)->count())->toBe(1);
});

test('turning_public_sharing_on_audits_the_setting_without_any_downgrade', function () {
    Http::fake([
        'worker.test/v1/internal/org-settings' => Http::response([
            'org' => 'acme',
            'public_sharing_allowed' => true,
            'downgraded' => [],
        ]),
    ]);
    $team = Team::factory()->create(['slug' => 'acme', 'public_sharing_allowed' => false]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'public_sharing_allowed' => true])
        ->assertRedirect(route('teams.edit', ['team' => $team->slug]));

    expect($team->fresh()->public_sharing_allowed)->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ArtifactSharingChanged)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::PublicSharingChanged)->count())->toBe(1);
});

test('a_member_cannot_change_the_public_sharing_setting', function () {
    Http::fake();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'public_sharing_allowed' => false])
        ->assertForbidden();

    Http::assertNothingSent();

    expect($team->fresh()->public_sharing_allowed)->toBeTrue();
});

test('a_rejected_push_leaves_the_local_setting_unchanged', function () {
    Http::fake(['worker.test/*' => Http::response('nope', 401)]);
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'public_sharing_allowed' => false])
        ->assertRedirect(route('teams.edit', ['team' => $team->slug]))
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'error',
            'message' => "Couldn't update public links. Try again.",
        ]);

    expect($team->fresh()->public_sharing_allowed)->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::PublicSharingChanged)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ArtifactSharingChanged)->exists())->toBeFalse();
});

test('an_unchanged_setting_does_not_call_the_worker', function () {
    Http::fake();
    $team = Team::factory()->create(['public_sharing_allowed' => true]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'public_sharing_allowed' => true])
        ->assertRedirect(route('teams.edit', ['team' => $team->slug]));

    Http::assertNothingSent();
});
