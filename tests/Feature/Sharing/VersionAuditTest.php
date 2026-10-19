<?php

use App\Contracts\ArtifactContentSource;
use App\Enums\AuditEventType;
use App\Jobs\IndexArtifactJob;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\WorkerEvents\ArtifactVersionCreatedHandler;
use Illuminate\Support\Facades\Bus;

test('a_version_publish_is_audited_with_the_editor_as_actor', function () {
    config(['indexing.enabled' => false]);
    $team = Team::factory()->create(['slug' => 'acme']);

    app(ArtifactVersionCreatedHandler::class)->handle([
        'org_id' => 'acme',
        'data' => [
            'artifact_id' => 'a1',
            'version' => 3,
            'created_by' => '42',
            'editor_org' => 'acme',
        ],
    ]);

    $event = AuditEvent::query()->where('event_type', AuditEventType::ArtifactVersionPublished)->sole();

    expect($event->team_id)->toBe($team->id)
        ->and($event->actor)->toBe('42')
        ->and($event->outcome)->toBe('success')
        ->and($event->target)->toBe('artifact:a1 version:3');
});

test('a_cross_org_edit_appends_the_editors_org_to_the_target', function () {
    config(['indexing.enabled' => false]);
    Team::factory()->create(['slug' => 'acme']);

    app(ArtifactVersionCreatedHandler::class)->handle([
        'org_id' => 'acme',
        'data' => [
            'artifact_id' => 'a1',
            'version' => 2,
            'created_by' => '99',
            'editor_org' => 'other-org',
        ],
    ]);

    expect(AuditEvent::query()->where('event_type', AuditEventType::ArtifactVersionPublished)->sole()->target)
        ->toBe('artifact:a1 version:2 by other-org');
});

test('a_version_publish_with_missing_fields_is_still_audited_with_an_unknown_actor', function () {
    config(['indexing.enabled' => false]);
    Team::factory()->create(['slug' => 'acme']);

    app(ArtifactVersionCreatedHandler::class)->handle([
        'org_id' => 'acme',
        'data' => ['artifact_id' => 'a1'],
    ]);

    $event = AuditEvent::query()->where('event_type', AuditEventType::ArtifactVersionPublished)->sole();

    expect($event->actor)->toBe('unknown')
        ->and($event->target)->toBe('artifact:a1 version:unknown');
});

test('a_version_event_for_an_unknown_team_is_not_audited', function () {
    config(['indexing.enabled' => false]);

    app(ArtifactVersionCreatedHandler::class)->handle([
        'org_id' => 'not-a-team',
        'data' => ['artifact_id' => 'a1', 'version' => 1, 'created_by' => '1'],
    ]);

    expect(AuditEvent::query()->count())->toBe(0);
});

test('the_publish_is_audited_even_when_indexing_is_disabled', function () {
    config(['indexing.enabled' => false]);
    Bus::fake();
    Team::factory()->create(['slug' => 'acme']);

    app(ArtifactVersionCreatedHandler::class)->handle([
        'org_id' => 'acme',
        'data' => ['artifact_id' => 'a1', 'version' => 1, 'created_by' => '1'],
    ]);

    expect(AuditEvent::query()->where('event_type', AuditEventType::ArtifactVersionPublished)->count())->toBe(1);
    Bus::assertNothingDispatched();
});

test('indexing_is_unchanged_and_the_publish_is_audited_when_enabled', function () {
    config(['indexing.enabled' => true]);
    Bus::fake();
    $team = Team::factory()->create(['slug' => 'acme']);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, 'a1', '<h1>v2</h1>', version: 2);

    app(ArtifactVersionCreatedHandler::class)->handle([
        'org_id' => 'acme',
        'data' => ['artifact_id' => 'a1', 'version' => 2, 'created_by' => '7'],
    ]);

    expect(AuditEvent::query()->where('event_type', AuditEventType::ArtifactVersionPublished)->count())->toBe(1);
    Bus::assertDispatched(IndexArtifactJob::class);
});
