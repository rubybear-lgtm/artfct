<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\WorkerEvents\ArtifactSharingChangedHandler;
use App\Services\WorkerEvents\WorkerEventHandlers;

test('the_sharing_changed_handler_audits_the_transition_with_edit_access', function () {
    $team = Team::factory()->create(['slug' => 'acme']);

    app(ArtifactSharingChangedHandler::class)->handle([
        'org_id' => 'acme',
        'data' => [
            'artifact_id' => 'a1',
            'from' => 'public',
            'to' => 'team',
            'from_edit_access' => 'view',
            'to_edit_access' => 'edit',
            'actor_user_id' => '42',
        ],
    ]);

    $event = AuditEvent::query()->where('event_type', AuditEventType::ArtifactSharingChanged)->sole();

    expect($event->team_id)->toBe($team->id)
        ->and($event->actor)->toBe('42')
        ->and($event->target)->toBe('artifact:a1 public->team edit_access view->edit');
});

test('the_sharing_changed_handler_omits_edit_access_when_it_did_not_change', function () {
    $team = Team::factory()->create(['slug' => 'acme']);

    app(ArtifactSharingChangedHandler::class)->handle([
        'org_id' => 'acme',
        'data' => [
            'artifact_id' => 'a2',
            'from' => 'team',
            'to' => 'private',
            'from_edit_access' => 'view',
            'to_edit_access' => 'view',
            'actor_user_id' => '7',
        ],
    ]);

    expect(AuditEvent::query()->where('event_type', AuditEventType::ArtifactSharingChanged)->sole()->target)
        ->toBe('artifact:a2 team->private');
});

test('the_sharing_changed_handler_ignores_an_unknown_team', function () {
    app(ArtifactSharingChangedHandler::class)->handle([
        'org_id' => 'not-a-team',
        'data' => ['artifact_id' => 'a1', 'from' => 'team', 'to' => 'public'],
    ]);

    expect(AuditEvent::query()->count())->toBe(0);
});

test('the_sharing_changed_event_is_registered_with_the_worker_event_registry', function () {
    $team = Team::factory()->create(['slug' => 'acme']);

    $handled = app(WorkerEventHandlers::class)->dispatch([
        'type' => 'artifact.sharing_changed',
        'org_id' => 'acme',
        'data' => [
            'artifact_id' => 'a1',
            'from' => 'team',
            'to' => 'public',
            'actor_user_id' => '9',
        ],
    ]);

    expect($handled)->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ArtifactSharingChanged)->count())->toBe(1);
});
