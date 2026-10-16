<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Jobs\IndexArtifactJob;
use App\Jobs\OperatorReindexJob;
use App\Models\AuditEvent;
use App\Models\OperatorAuditEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Billing\BillingService;
use App\Services\Governance\ArtifactGovernanceContract;
use App\Services\Governance\FakeArtifactGovernance;
use App\Services\Operator\Actions\ChangePlanAction;
use App\Services\Operator\Actions\EraseTenantAction;
use App\Services\Operator\SuppressionCaseService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config([
        'inertia.testing.ensure_pages_exist' => false,
        'services.worker.base_url' => 'https://worker.test',
        'services.worker.limits_write_secret' => 'limits-secret',
        'indexing.enabled' => true,
    ]);
});

const OPS_ACTION_REASON = 'Customer asked in ticket 4411';

function opsActionStaff(bool $caseManager = false): User
{
    $staff = User::factory()->create();
    $staff->forceFill(['staff_at' => now(), 'case_manager_at' => $caseManager ? now() : null])->save();

    return $staff;
}

function opsActionGovernance(): FakeArtifactGovernance
{
    /** @var FakeArtifactGovernance $governance */
    $governance = app(ArtifactGovernanceContract::class);

    return $governance;
}

/**
 * @param  list<string>  $ids
 */
function opsActionSeedArtifacts(Team $team, array $ids = ['art-one', 'art-two']): void
{
    foreach ($ids as $id) {
        opsActionGovernance()->seedArtifact($team->slug, ['id' => $id, 'created_at' => now()->subDay()->toIso8601String()]);
    }
}

/**
 * @param  list<string>  $ids
 */
function opsActionArtifactsExist(Team $team, array $ids = ['art-one', 'art-two']): bool
{
    foreach ($ids as $id) {
        if (! opsActionGovernance()->stillExists($team->slug, $id)) {
            return false;
        }
    }

    return true;
}

/**
 * Every operator-owned row: the internal log and the tenant's copies.
 *
 * @return array{internal: int, tenant: int}
 */
function opsActionRowCounts(): array
{
    return [
        'internal' => OperatorAuditEvent::query()->count(),
        'tenant' => AuditEvent::query()->where('event_type', 'like', 'operator.%')->count(),
    ];
}

/**
 * @return array<string, array{route: string, payload: array<string, mixed>}>
 */
function opsActionRoutes(): array
{
    return [
        'reindex' => ['route' => 'ops.tenants.reindex', 'payload' => ['scope' => 'tenant']],
        'limits' => ['route' => 'ops.tenants.limits', 'payload' => []],
        'plan' => ['route' => 'ops.tenants.plan', 'payload' => ['plan' => 'enterprise', 'payment_status' => 'active', 'confirm' => true]],
        'erase' => ['route' => 'ops.tenants.erase', 'payload' => ['confirm_slug' => 'ignored']],
    ];
}

test('action_requires_reason', function () {
    Queue::fake();
    Http::fake();
    $team = Team::factory()->create(['slug' => 'reason-org', 'plan' => Plan::Free]);
    memberOfTeam($team, TeamRole::Admin);
    opsActionSeedArtifacts($team);
    $staff = opsActionStaff();

    // Erase needs a fresh dry run, so every attempt below fails on the
    // reason and nothing else.
    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team))->assertRedirect();
    Http::assertNothingSent();

    foreach (['missing' => null, 'blank' => '   ', 'too short' => 'abcd', 'too long' => str_repeat('x', 501)] as $label => $reason) {
        foreach (opsActionRoutes() as $name => $definition) {
            $payload = $definition['payload'];
            if ($name === 'erase') {
                $payload['confirm_slug'] = $team->slug;
            }
            if ($reason !== null) {
                $payload['reason'] = $reason;
            }

            $this->actingAs($staff)
                ->post(route($definition['route'], $team), $payload)
                ->assertSessionHasErrors('reason');

            expect(opsActionRowCounts())->toBe(['internal' => 0, 'tenant' => 0], "{$name} with a {$label} reason wrote an audit row");
        }
    }

    // None of the refused attempts changed anything.
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect($team->fresh()->plan)->toBe(Plan::Free)
        ->and(opsActionArtifactsExist($team))->toBeTrue();

    // Control: with a reason the same requests run, and the reason is
    // stored, trimmed, on both copies of the event.
    $this->actingAs($staff)->post(route('ops.tenants.reindex', $team), ['scope' => 'tenant', 'reason' => '  '.OPS_ACTION_REASON.'  '])
        ->assertSessionHasNoErrors();

    $internal = OperatorAuditEvent::query()->sole();
    $tenantCopy = AuditEvent::query()->where('event_type', AuditEventType::OperatorReindexRequested)->sole();
    expect($internal->reason)->toBe(OPS_ACTION_REASON)
        ->and($internal->outcome)->toBe('success')
        ->and($tenantCopy->reason)->toBe(OPS_ACTION_REASON);
    Queue::assertPushed(OperatorReindexJob::class, 1);
});

test('erase_requires_dry_run_then_typed_slug', function () {
    $team = Team::factory()->create(['slug' => 'erase-me']);
    opsActionSeedArtifacts($team);
    $staff = opsActionStaff();
    $erase = fn (array $payload) => $this->actingAs($staff)->post(route('ops.tenants.erase', $team), $payload + ['reason' => OPS_ACTION_REASON]);

    // No dry run yet: refused, nothing erased, a failure event with the reason.
    $erase(['confirm_slug' => 'erase-me'])->assertSessionHasErrors(['action' => 'Run the dry run first.']);
    expect(opsActionArtifactsExist($team))->toBeTrue();
    $failure = OperatorAuditEvent::query()->sole();
    expect($failure->event_type)->toBe(AuditEventType::OperatorTenantErased)
        ->and($failure->outcome)->toBe('failure')
        ->and($failure->reason)->toBe(OPS_ACTION_REASON)
        ->and($failure->target)->toBe('erase-me');

    // The dry run is read-only: nothing erased, counts only, no operator row.
    $before = opsActionRowCounts();
    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team))
        ->assertRedirect(route('ops.tenants.show', $team));
    expect(opsActionArtifactsExist($team))->toBeTrue()
        ->and(opsActionRowCounts())->toBe($before)
        ->and(session(EraseTenantAction::previewKey($team).'.plan'))->toHaveKeys(['refused', 'artifact_count', 'held', 'generated_at'])
        ->and(session(EraseTenantAction::previewKey($team).'.plan.artifact_count'))->toBe(2)
        ->and(session(EraseTenantAction::previewKey($team).'.plan.refused'))->toBeFalse()
        ->and(json_encode(session(EraseTenantAction::previewKey($team))))->not->toContain('art-one')
        ->and(session(EraseTenantAction::previewKey($team).'.team_id'))->toBe($team->id)
        ->and(session(EraseTenantAction::previewKey($team).'.slug'))->toBe('erase-me');

    // A wrong or missing slug is refused and leaves the dry run in place.
    $erase(['confirm_slug' => 'erase-me-please'])->assertSessionHasErrors('confirm_slug');
    $erase([])->assertSessionHasErrors('confirm_slug');
    $erase(['confirm_slug' => 'ERASE-ME'])->assertSessionHasErrors('confirm_slug');
    expect(opsActionArtifactsExist($team))->toBeTrue();
    expect(OperatorAuditEvent::query()->where('outcome', 'failure')->count())->toBe(4);

    // A dry run older than 15 minutes is refused.
    $this->travel(16)->minutes();
    $erase(['confirm_slug' => 'erase-me'])->assertSessionHasErrors('action');
    expect(opsActionArtifactsExist($team))->toBeTrue();
    $this->travelBack();

    // A fresh dry run and the exact slug erase it.
    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team));
    $erase(['confirm_slug' => 'erase-me'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('ops.tenants.index'));

    expect(opsActionArtifactsExist($team))->toBeFalse()
        ->and(session(EraseTenantAction::previewKey($team)))->toBeNull();
    $success = OperatorAuditEvent::query()->where('outcome', 'success')->where('event_type', AuditEventType::OperatorTenantErased)->sole();
    expect($success->reason)->toBe(OPS_ACTION_REASON);

    // The irreversible call is preceded by an internal-only row, which the
    // tenant never sees.
    $started = OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorEraseStarted)->sole();
    expect($started->reason)->toBe(OPS_ACTION_REASON)
        ->and($started->id)->toBeLessThan($success->id)
        ->and(AuditEvent::query()->where('event_type', 'operator.erase_started')->exists())->toBeFalse();

    // The dry run is consumed: a second erase needs a new one.
    opsActionSeedArtifacts($team, ['art-three']);
    $erase(['confirm_slug' => 'erase-me'])->assertSessionHasErrors('action');
    expect(opsActionArtifactsExist($team, ['art-three']))->toBeTrue();
});

test('erase_refused_plan_and_deleted_tenant_leave_state_untouched', function () {
    $team = Team::factory()->create(['slug' => 'held-org']);
    opsActionSeedArtifacts($team);
    opsActionGovernance()->placeLegalHold($team->slug, 'art-two');
    $staff = opsActionStaff();

    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team));
    expect(session(EraseTenantAction::previewKey($team).'.plan.refused'))->toBeTrue()
        ->and(session(EraseTenantAction::previewKey($team).'.plan.held'))->toBeTrue();

    $this->actingAs($staff)->post(route('ops.tenants.erase', $team), ['confirm_slug' => 'held-org', 'reason' => OPS_ACTION_REASON])
        ->assertSessionHasErrors('action');

    // Never partial: the clean artifact survives with the held one.
    expect(opsActionArtifactsExist($team))->toBeTrue();
    expect(OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorTenantErased)->sole()->outcome)->toBe('failure');

    // A soft-deleted tenant refuses every action with a failure event.
    $deleted = Team::factory()->create(['slug' => 'gone-org']);
    $deleted->delete();
    opsActionSeedArtifacts($deleted);

    foreach (opsActionRoutes() as $definition) {
        $this->actingAs($staff)
            ->post(route($definition['route'], $deleted->slug), $definition['payload'] + ['reason' => OPS_ACTION_REASON])
            ->assertSessionHasErrors('action');
    }

    expect(OperatorAuditEvent::query()->where('team_id', $deleted->id)->where('outcome', 'failure')->count())->toBe(4)
        ->and(opsActionArtifactsExist($deleted))->toBeTrue();
});

test('plan_change_refuses_unchanged_and_unconfirmed_values_and_records_the_diff', function () {
    Http::fake(['worker.test/*' => Http::response(['org' => 'plan-org'])]);
    $team = Team::factory()->create(['slug' => 'plan-org', 'plan' => Plan::Free, 'payment_status' => PaymentStatus::Active]);
    $staff = opsActionStaff();
    $post = fn (array $payload) => $this->actingAs($staff)->post(route('ops.tenants.plan', $team), $payload + ['reason' => OPS_ACTION_REASON]);

    $post(['plan' => 'free', 'payment_status' => 'active', 'confirm' => true])->assertSessionHasErrors('action');
    $post(['plan' => 'team', 'payment_status' => 'active'])->assertSessionHasErrors('confirm');
    $post(['plan' => 'gold', 'payment_status' => 'active', 'confirm' => true])->assertSessionHasErrors('plan');

    Http::assertNothingSent();
    expect($team->fresh()->plan)->toBe(Plan::Free)
        ->and(OperatorAuditEvent::query()->where('outcome', 'failure')->count())->toBe(2);

    $post(['plan' => 'team', 'payment_status' => 'past_due', 'confirm' => true])->assertSessionHasNoErrors();

    $team->refresh();
    expect($team->plan)->toBe(Plan::Team)->and($team->payment_status)->toBe(PaymentStatus::PastDue);
    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://worker.test/v1/internal/org-limits'
        && $request['org'] === 'plan-org'
        && $request['read_only'] === true);

    $tenantCopy = AuditEvent::query()->where('event_type', AuditEventType::OperatorPlanChanged)->where('outcome', 'success')->sole();
    expect($tenantCopy->reason)->toBe(OPS_ACTION_REASON.' (plan free → team, payment active → past_due)')
        ->and($tenantCopy->target)->toBe('plan-org');
});

test('limits_failure_is_refused_with_a_failure_event', function () {
    Http::fake(['worker.test/*' => Http::response([], 500)]);
    $team = Team::factory()->create(['slug' => 'limits-down']);
    $staff = opsActionStaff();

    $this->actingAs($staff)->post(route('ops.tenants.limits', $team), ['reason' => OPS_ACTION_REASON])
        ->assertSessionHasErrors('action');

    $row = OperatorAuditEvent::query()->sole();
    expect($row->event_type)->toBe(AuditEventType::OperatorLimitsChanged)
        ->and($row->outcome)->toBe('failure')
        ->and($row->reason)->toBe(OPS_ACTION_REASON);
});

test('console_limits_match_billing_sync_limits', function () {
    Http::fake(['worker.test/*' => Http::response(['org' => 'sync-org'])]);
    config(['billing.plans.team' => [
        'storage_bytes' => 4321,
        'artifacts_per_month' => 77,
        'bundle_size_ceiling_bytes' => 999,
        'render_minutes_per_month' => 5,
    ]]);
    $team = Team::factory()->create(['slug' => 'sync-org', 'plan' => Plan::Team, 'payment_status' => PaymentStatus::PastDue]);

    $this->artisan('billing:sync-limits', ['org' => 'sync-org'])->assertSuccessful();
    $this->actingAs(opsActionStaff())->post(route('ops.tenants.limits', $team), ['reason' => OPS_ACTION_REASON])
        ->assertSessionHasNoErrors();

    $pushes = Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/internal/org-limits'))
        ->map(fn (array $pair): array => [
            'url' => $pair[0]->url(),
            'authorization' => $pair[0]->header('Authorization'),
            'body' => $pair[0]->data(),
        ])->values();

    expect($pushes)->toHaveCount(2)
        ->and($pushes[1])->toBe($pushes[0])
        ->and($pushes[0]['body'])->toBe([
            'org' => 'sync-org',
            'storage_bytes' => 4321,
            'artifacts_per_month' => 77,
            'bundle_ceiling_bytes' => 999,
            'read_only' => true,
        ]);
});

test('ui_and_cli_actions_write_equivalent_audit_rows', function () {
    $cliTeam = Team::factory()->create(['slug' => 'cli-org']);
    $uiTeam = Team::factory()->create(['slug' => 'ui-org']);
    opsActionSeedArtifacts($cliTeam);
    opsActionSeedArtifacts($uiTeam);
    $staff = opsActionStaff();

    $this->artisan('governance:erase', ['org' => 'cli-org', '--apply' => true])->assertSuccessful();

    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $uiTeam));
    $this->actingAs($staff)->post(route('ops.tenants.erase', $uiTeam), ['confirm_slug' => 'ui-org', 'reason' => OPS_ACTION_REASON])
        ->assertSessionHasNoErrors();

    // The service writes the erase rows, so both paths write the same ones.
    $rows = fn (Team $team): array => AuditEvent::query()
        ->where('team_id', $team->id)
        ->where('event_type', AuditEventType::ArtifactDeleted)
        ->orderBy('id')
        ->get()
        ->map(fn (AuditEvent $event): array => [
            'type' => $event->event_type->value,
            'target' => str_replace($team->slug, '{slug}', $event->target),
            'ip' => $event->ip,
            'user_agent' => $event->user_agent,
            'outcome' => $event->outcome,
            'reason' => $event->reason,
        ])->all();

    $cliRows = $rows($cliTeam);
    $uiRows = array_values(array_filter($rows($uiTeam), fn (array $row): bool => ! str_starts_with((string) $row['outcome'], 'dry_run')));

    expect($uiRows)->toBe($cliRows)->and($cliRows)->toHaveCount(1);

    $actors = fn (Team $team): array => AuditEvent::query()
        ->where('team_id', $team->id)->where('event_type', AuditEventType::ArtifactDeleted)->pluck('actor')->unique()->values()->all();
    expect($actors($cliTeam))->toBe(['cli'])
        ->and($actors($uiTeam))->toBe(["staff:{$staff->id}"]);

    // Reindex: the console's queued job dispatches the same index jobs the
    // CLI command queues.
    Queue::fake();
    $admin = memberOfTeam($uiTeam, TeamRole::Admin);
    expect($admin)->not->toBeNull();

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => 'abcdef0123456789abcdef0123456789', 'org_id' => $uiTeam->slug, 'user_id' => 1, 'title' => 'T', 'description' => 'd',
        'content_hash' => 'h', 'created_at' => now()->subDay()->toIso8601String(), 'revoked_at' => null,
        'provenance' => ['agent' => null, 'repo_url' => null, 'commit_sha' => null],
    ]);
    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($uiTeam->slug, 'abcdef0123456789abcdef0123456789', '<h1>x</h1>');

    $this->actingAs($staff)->post(route('ops.tenants.reindex', $uiTeam), ['scope' => 'tenant', 'reason' => OPS_ACTION_REASON]);
    Queue::assertPushed(OperatorReindexJob::class, fn (OperatorReindexJob $job): bool => $job->teamId === $uiTeam->id && $job->artifactId === null);
    Queue::assertNotPushed(IndexArtifactJob::class);

    app()->call([new OperatorReindexJob($uiTeam->id), 'handle']);
    Queue::assertPushed(IndexArtifactJob::class, 1);
    Queue::assertPushed(IndexArtifactJob::class, fn (IndexArtifactJob $job): bool => $job->artifactId === 'abcdef0123456789abcdef0123456789' && $job->queue === 'indexing');
});

test('reindex_result_shown_to_staff_contains_no_content', function () {
    Queue::fake();
    $team = Team::factory()->create(['slug' => 'blind-org']);
    $secretTitle = 'SentinelQuarterlyRoadmapTitle';
    $secretHtml = 'SentinelRenderedBodyHtml';
    $artifactId = 'abcdef0123456789abcdef0123456789';

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $artifactId, 'org_id' => $team->slug, 'user_id' => 1, 'title' => $secretTitle, 'description' => 'SentinelArtifactDescription',
        'content_hash' => 'h', 'created_at' => now()->subDay()->toIso8601String(), 'revoked_at' => null,
        'provenance' => ['agent' => null, 'repo_url' => null, 'commit_sha' => null],
    ]);
    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, $artifactId, "<html><body>{$secretHtml}</body></html>");
    memberOfTeam($team, TeamRole::Admin);

    $staff = opsActionStaff();

    foreach ([['scope' => 'tenant'], ['scope' => 'artifact', 'artifact_id' => $artifactId]] as $payload) {
        $response = $this->actingAs($staff)->followingRedirects()
            ->post(route('ops.tenants.reindex', $team), $payload + ['reason' => OPS_ACTION_REASON]);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('ops/tenants/show')
            ->where('status', 'Reindex queued.')
            ->where('errors', []));

        $body = (string) $response->getContent();
        foreach ([$secretTitle, $secretHtml, 'SentinelArtifactDescription'] as $sentinel) {
            expect($body)->not->toContain($sentinel);
        }
    }

    // The request only queued a job. It carries ids, never content, and it
    // read nothing from the Worker.
    Queue::assertPushed(OperatorReindexJob::class, 2);
    Queue::assertNotPushed(IndexArtifactJob::class);
    Queue::assertPushed(OperatorReindexJob::class, fn (OperatorReindexJob $job): bool => $job->artifactId === $artifactId);

    expect(OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorReindexRequested)->orderBy('id')->pluck('target')->all())
        ->toBe(['blind-org', "blind-org:{$artifactId}"])
        ->and(json_encode(OperatorAuditEvent::query()->get()->toArray()))->not->toContain($secretTitle);

    // The artifact id is checked for shape only.
    $this->actingAs($staff)->post(route('ops.tenants.reindex', $team), ['scope' => 'artifact', 'artifact_id' => '../etc', 'reason' => OPS_ACTION_REASON])
        ->assertSessionHasErrors('artifact_id');

    // Indexing off: a guard failure with a failure event, nothing queued.
    config(['indexing.enabled' => false]);
    Queue::fake();
    $this->actingAs($staff)->post(route('ops.tenants.reindex', $team), ['scope' => 'tenant', 'reason' => OPS_ACTION_REASON])
        ->assertSessionHasErrors('action');
    Queue::assertNothingPushed();
    expect(OperatorAuditEvent::query()->latest('id')->first()->outcome)->toBe('failure');
});

test('mutating_actions_stay_visible_during_a_case_through_the_console', function () {
    Queue::fake();
    Http::fake(['worker.test/*' => Http::response(['org' => 'case-org'])]);
    $team = Team::factory()->create(['slug' => 'case-org', 'plan' => Plan::Enterprise]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    opsActionSeedArtifacts($team);
    $staff = opsActionStaff();

    app(SuppressionCaseService::class)->create(
        opsActionStaff(caseManager: true),
        $team,
        'legal_request',
        'LEGAL-2041',
        now()->addDays(30),
    );

    // Control: the case is live, so a plain view is withheld from the tenant.
    $this->actingAs($staff)->get(route('ops.tenants.show', $team))->assertOk();
    expect(AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->exists())->toBeFalse()
        ->and(OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->exists())->toBeTrue();

    $this->actingAs($staff)->post(route('ops.tenants.reindex', $team), ['scope' => 'tenant', 'reason' => 'Reindex after the import']);
    $this->actingAs($staff)->post(route('ops.tenants.limits', $team), ['reason' => 'Re-push after the incident']);
    $this->actingAs($staff)->post(route('ops.tenants.plan', $team), [
        'plan' => 'enterprise', 'payment_status' => 'past_due', 'confirm' => true, 'reason' => 'Payment hold per finance',
    ]);
    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team));
    $this->actingAs($staff)->post(route('ops.tenants.erase', $team), ['confirm_slug' => 'case-org', 'reason' => 'Erasure request RUB-1']);

    $page = $this->actingAs($admin)->get(route('teams.audit.index', $team))->assertOk();
    $types = collect($page->inertiaProps('events.data'))->pluck('type');

    expect($types)->toContain('operator.reindex_requested')
        ->toContain('operator.limits_changed')
        ->toContain('operator.plan_changed')
        ->toContain('operator.tenant_erased')
        ->not->toContain('operator.tenant_viewed');

    // Visible the same minute: every copy was written now, none was held.
    $copies = AuditEvent::query()->where('event_type', 'like', 'operator.%')->get();
    expect($copies)->toHaveCount(4);
    foreach ($copies as $copy) {
        expect($copy->created_at->diffInSeconds(now()))->toBeLessThan(60);
    }
    expect(OperatorAuditEvent::query()->whereNotNull('suppression_case_id')->where('event_type', '!=', AuditEventType::OperatorTenantViewed)->exists())->toBeFalse();

    // The tenant copy carries the reason and the staff label, never the
    // staff member's own address.
    $erased = AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantErased)->sole();
    expect($erased->reason)->toBe('Erasure request RUB-1')
        ->and($erased->ip)->toBe('artfct staff');
});

test('non_staff_cannot_post_any_action', function () {
    Queue::fake();
    Http::fake();
    $team = Team::factory()->create(['slug' => 'locked-org', 'plan' => Plan::Free]);
    opsActionSeedArtifacts($team);
    $member = memberOfTeam($team, TeamRole::Admin);
    $staff = opsActionStaff();

    $routes = opsActionRoutes() + ['preview' => ['route' => 'ops.tenants.erase.preview', 'payload' => []]];
    $routes['erase']['payload'] = ['confirm_slug' => 'locked-org'];

    $assertNothingChanged = function (string $label) use ($team): void {
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        expect(opsActionRowCounts())->toBe(['internal' => 0, 'tenant' => 0], "{$label} wrote an audit row")
            ->and(opsActionArtifactsExist($team))->toBeTrue("{$label} erased artifacts")
            ->and($team->fresh()->plan)->toBe(Plan::Free);
    };

    $attempts = [
        'signed out' => fn () => $this,
        'non-staff admin' => fn () => $this->actingAs($member),
    ];

    foreach ($attempts as $label => $as) {
        foreach ($routes as $name => $definition) {
            $response = $as()->post(route($definition['route'], $team), $definition['payload'] + ['reason' => OPS_ACTION_REASON]);

            expect($response->getStatusCode())->toBe(404, "{$label} POST {$name} answered {$response->getStatusCode()}");
            $assertNothingChanged("{$label} {$name}");
        }
    }

    // A staff grant without the staff sign-in session is refused where the
    // session is required. CSRF is enforced outside `testing`, so a valid
    // token is sent: the refusal below is the staff gate, not the token.
    app()['env'] = 'staging';
    foreach ($routes as $name => $definition) {
        $response = $this->actingAs($staff)
            ->withSession(['_token' => 'csrf-token'])
            ->post(route($definition['route'], $team), $definition['payload'] + ['reason' => OPS_ACTION_REASON, '_token' => 'csrf-token']);

        expect($response->getStatusCode())->toBe(404, "staff without a session POST {$name} answered {$response->getStatusCode()}");
        $assertNothingChanged("staff without a session {$name}");
    }

    // Control: the same request with the session reaches the action.
    $this->actingAs($staff)
        ->withSession(['_token' => 'csrf-token', 'staff_session' => ['user_id' => $staff->id, 'at' => now()->timestamp]])
        ->post(route('ops.tenants.reindex', $team), ['scope' => 'tenant', 'reason' => OPS_ACTION_REASON, '_token' => 'csrf-token'])
        ->assertRedirect(route('ops.tenants.show', $team));
    Queue::assertPushed(OperatorReindexJob::class, 1);
});

test('expired_csrf_token_on_ops_looks_like_an_unknown_url', function () {
    config(['app.debug' => false]);
    $team = Team::factory()->create(['slug' => 'csrf-org']);
    $staff = opsActionStaff();

    // CSRF is bypassed while the app runs in `testing`.
    app()['env'] = 'local';

    $unknown = $this->actingAs($staff)->post('/ops/no-such-page', []);
    $unknown->assertNotFound();

    foreach ([
        route('ops.tenants.limits', $team),
        route('ops.tenants.reindex', $team),
        route('ops.tenants.plan', $team),
        route('ops.tenants.erase.preview', $team),
        route('ops.tenants.erase', $team),
    ] as $url) {
        $response = $this->actingAs($staff)->post($url, ['reason' => OPS_ACTION_REASON]);

        $response->assertNotFound();
        $response->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404));
        expect($response->getContent())->toContain('404')->not->toContain('419');
    }

    // A signed-out visitor is the one the 404 protects.
    auth()->logout();
    $this->flushSession();
    $signedOut = $this->post(route('ops.tenants.limits', $team), ['reason' => OPS_ACTION_REASON]);
    $signedOut->assertNotFound();
    $signedOut->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404));

    expect(OperatorAuditEvent::query()->count())->toBe(0);

    // The rest of the application keeps answering 419.
    $this->actingAs($staff)->post('/logout')->assertStatus(419);
});

test('plan_change_service_sets_both_fields_without_touching_stripe', function () {
    Http::fake(['worker.test/*' => Http::response(['org' => 'svc-org'])]);
    $team = Team::factory()->create(['slug' => 'svc-org', 'plan' => Plan::Free]);

    app(BillingService::class)->applyOperatorPlanChange($team, Plan::Enterprise, PaymentStatus::PastDue);

    $team->refresh();
    expect($team->plan)->toBe(Plan::Enterprise)
        ->and($team->payment_status)->toBe(PaymentStatus::PastDue);
    // The service only changes state; pushing to the Worker is the caller's
    // job, after its own transaction commits.
    Http::assertNothingSent();
});

test('plan_change_is_never_enforced_by_the_worker_when_the_audit_write_fails', function () {
    Http::fake(['worker.test/*' => Http::response(['org' => 'rollback-org'])]);
    $team = Team::factory()->create(['slug' => 'rollback-org', 'plan' => Plan::Free, 'payment_status' => PaymentStatus::Active]);
    $staff = opsActionStaff();

    Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
        throw new RuntimeException('audit write failed');
    });

    try {
        $this->actingAs($staff)->post(route('ops.tenants.plan', $team), [
            'plan' => 'team',
            'payment_status' => 'past_due',
            'confirm' => true,
            'reason' => OPS_ACTION_REASON,
        ])->assertStatus(500);
    } finally {
        Event::forget('eloquent.creating: '.AuditEvent::class);
    }

    expect($team->fresh()->plan)->toBe(Plan::Free)
        ->and($team->fresh()->payment_status)->toBe(PaymentStatus::Active);
    Http::assertNothingSent();
});

test('erase_audit_rows_carry_a_state_as_outcome_and_the_message_as_reason', function () {
    $team = Team::factory()->create(['slug' => 'erase-audit']);
    opsActionSeedArtifacts($team);
    $staff = opsActionStaff();

    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team))->assertSessionHasNoErrors();

    $row = AuditEvent::query()->where('event_type', AuditEventType::ArtifactDeleted)->sole();
    expect($row->outcome)->toBe('dry_run')
        ->and($row->reason)->toBe('would erase 2 artifact(s)')
        ->and($row->actor)->toBe("staff:{$staff->id}");
});

test('show_page_carries_the_action_props', function () {
    $team = Team::factory()->create(['slug' => 'props-org']);
    $staff = opsActionStaff();

    $this->actingAs($staff)->get(route('ops.tenants.show', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.reindex.enabled', true)
            ->where('actions.limits.enabled', true)
            ->where('actions.billing.enabled', true)
            ->where('actions.billing.plans', ['free', 'team', 'enterprise'])
            ->where('actions.billing.payment_statuses', ['active', 'past_due'])
            ->where('actions.erase.enabled', true)
            ->where('erasure_plan', null)
            ->where('status', null));

    config(['indexing.enabled' => false]);
    $this->actingAs($staff)->get(route('ops.tenants.show', $team))
        ->assertInertia(fn (Assert $page) => $page->where('actions.reindex.enabled', false));

    $team->delete();
    $this->actingAs($staff)->get(route('ops.tenants.show', $team->slug))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.limits.enabled', false)
            ->where('actions.billing.enabled', false)
            ->where('actions.erase.enabled', false));

    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', Team::factory()->create(['slug' => 'flash-org'])));
    $this->actingAs($staff)->followingRedirects()->post(route('ops.tenants.erase.preview', 'flash-org'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('erasure_plan.artifact_count', 0)
            ->where('erasure_plan.refused', false));
});

test('csrf_protection_stays_on_for_ops_posts', function () {
    Queue::fake();
    Http::fake(['worker.test/*' => Http::response(['org' => 'forgery-org'])]);
    config(['app.debug' => false]);
    $team = Team::factory()->create(['slug' => 'forgery-org', 'plan' => Plan::Free]);
    memberOfTeam($team, TeamRole::Admin);
    opsActionSeedArtifacts($team);
    $staff = opsActionStaff();

    // Laravel skips the token check while the app runs in `testing`; `local`
    // enforces it and still accepts `staff_at` alone.
    app()['env'] = 'local';

    $requests = [
        ['route' => 'ops.tenants.reindex', 'payload' => ['scope' => 'tenant']],
        ['route' => 'ops.tenants.limits', 'payload' => []],
        ['route' => 'ops.tenants.plan', 'payload' => ['plan' => 'team', 'payment_status' => 'active', 'confirm' => true]],
        ['route' => 'ops.tenants.erase.preview', 'payload' => []],
        ['route' => 'ops.tenants.erase', 'payload' => ['confirm_slug' => 'forgery-org']],
    ];

    foreach ($requests as $request) {
        $response = $this->actingAs($staff)->post(route($request['route'], $team), $request['payload'] + ['reason' => OPS_ACTION_REASON]);

        expect($response->getStatusCode())->toBe(404, "{$request['route']} without a token answered {$response->getStatusCode()}");
    }

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(opsActionRowCounts())->toBe(['internal' => 0, 'tenant' => 0])
        ->and(OperatorAuditEvent::query()->count())->toBe(0)
        ->and(opsActionArtifactsExist($team))->toBeTrue()
        ->and($team->fresh()->plan)->toBe(Plan::Free);

    // Control: the same staff session with a token reaches the action.
    $this->actingAs($staff)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('ops.tenants.limits', $team), ['reason' => OPS_ACTION_REASON, '_token' => 'csrf-token'])
        ->assertRedirect(route('ops.tenants.show', $team))
        ->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    expect(OperatorAuditEvent::query()->sole()->event_type)->toBe(AuditEventType::OperatorLimitsChanged);
});

test('erase_after_a_valid_dry_run_is_refused_when_the_tenant_was_soft_deleted_in_between', function () {
    $team = Team::factory()->create(['slug' => 'deleted-between']);
    opsActionSeedArtifacts($team);
    $staff = opsActionStaff();

    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team))->assertSessionHasNoErrors();
    expect(session(EraseTenantAction::previewKey($team).'.plan.artifact_count'))->toBe(2);

    $team->delete();

    $this->actingAs($staff)
        ->post(route('ops.tenants.erase', $team->slug), ['confirm_slug' => 'deleted-between', 'reason' => OPS_ACTION_REASON])
        ->assertSessionHasErrors('action');

    expect(opsActionArtifactsExist($team))->toBeTrue();
    $failure = OperatorAuditEvent::query()->sole();
    expect($failure->event_type)->toBe(AuditEventType::OperatorTenantErased)
        ->and($failure->outcome)->toBe('failure');

    // A soft-deleted tenant shows no confirm step either.
    $this->actingAs($staff)->get(route('ops.tenants.show', $team->slug))
        ->assertInertia(fn (Assert $page) => $page->where('erasure_plan', null));
});

test('erase_step_two_survives_a_failed_erase_and_a_reload', function () {
    $team = Team::factory()->create(['slug' => 'step-two']);
    $other = Team::factory()->create(['slug' => 'step-other']);
    opsActionSeedArtifacts($team);
    $staff = opsActionStaff();
    $show = fn (Team $tenant) => $this->actingAs($staff)->get(route('ops.tenants.show', $tenant));

    $show($team)->assertInertia(fn (Assert $page) => $page->where('erasure_plan', null));

    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team));

    $show($team)->assertInertia(fn (Assert $page) => $page
        ->where('erasure_plan.artifact_count', 2)
        ->where('erasure_plan.refused', false)
        ->where('erasure_plan.held', false)
        ->has('erasure_plan.generated_at'));

    // Another tenant never sees this tenant's dry run.
    $show($other)->assertInertia(fn (Assert $page) => $page->where('erasure_plan', null));

    // A refused erase and a reload both leave the confirm step in place.
    $this->actingAs($staff)->post(route('ops.tenants.erase', $team), ['confirm_slug' => 'wrong', 'reason' => OPS_ACTION_REASON])
        ->assertSessionHasErrors('confirm_slug');
    $show($team)->assertInertia(fn (Assert $page) => $page->where('erasure_plan.artifact_count', 2));
    $show($team)->assertInertia(fn (Assert $page) => $page->where('erasure_plan.artifact_count', 2));

    // It lapses with the dry run.
    $this->travel(16)->minutes();
    $show($team)->assertInertia(fn (Assert $page) => $page->where('erasure_plan', null));
    $this->travelBack();

    // And it is consumed by the erase.
    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team));
    $this->actingAs($staff)->post(route('ops.tenants.erase', $team), ['confirm_slug' => 'step-two', 'reason' => OPS_ACTION_REASON])
        ->assertSessionHasNoErrors();
    $show($team)->assertInertia(fn (Assert $page) => $page->where('erasure_plan', null));
});

test('erase_is_refused_while_another_erase_holds_the_tenant_lock', function () {
    $team = Team::factory()->create(['slug' => 'locked-erase']);
    opsActionSeedArtifacts($team);
    $staff = opsActionStaff();

    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team));

    $lock = Cache::lock("ops:erase:{$team->id}", 120);
    expect($lock->get())->toBeTrue();

    $this->actingAs($staff)
        ->post(route('ops.tenants.erase', $team), ['confirm_slug' => 'locked-erase', 'reason' => OPS_ACTION_REASON])
        ->assertSessionHasErrors(['action' => 'An erase is already running for this tenant.']);

    expect(opsActionArtifactsExist($team))->toBeTrue()
        ->and(OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorEraseStarted)->exists())->toBeFalse();
    $failure = OperatorAuditEvent::query()->sole();
    expect($failure->event_type)->toBe(AuditEventType::OperatorTenantErased)
        ->and($failure->outcome)->toBe('failure');

    // The dry run was not consumed; once the lock is free the erase runs, and
    // it releases the lock when it is done.
    $lock->release();
    $this->actingAs($staff)
        ->post(route('ops.tenants.erase', $team), ['confirm_slug' => 'locked-erase', 'reason' => OPS_ACTION_REASON])
        ->assertSessionHasNoErrors();
    expect(opsActionArtifactsExist($team))->toBeFalse();

    $again = Cache::lock("ops:erase:{$team->id}", 120);
    expect($again->get())->toBeTrue();
    $again->release();
});

test('reindex_refused_without_an_admin_and_while_already_queued', function () {
    Queue::fake();
    $team = Team::factory()->create(['slug' => 'no-admin-org']);
    $staff = opsActionStaff();
    $post = fn (array $payload = ['scope' => 'tenant']) => $this->actingAs($staff)
        ->post(route('ops.tenants.reindex', $team), $payload + ['reason' => OPS_ACTION_REASON]);

    // No admin to act as: refused, with a failure event, nothing queued.
    $post()->assertSessionHasErrors(['action' => 'This tenant has no admin to reindex as.']);
    Queue::assertNothingPushed();
    expect(OperatorAuditEvent::query()->sole()->outcome)->toBe('failure');

    memberOfTeam($team, TeamRole::Admin);

    // The first whole-tenant reindex queues; a second is refused while the
    // first is still queued.
    $post()->assertSessionHasNoErrors();
    Queue::assertPushed(OperatorReindexJob::class, 1);

    $post()->assertSessionHasErrors(['action' => 'A reindex for this tenant is already queued.']);
    Queue::assertPushed(OperatorReindexJob::class, 1);
    expect(OperatorAuditEvent::query()->where('outcome', 'failure')->count())->toBe(2)
        ->and(OperatorAuditEvent::query()->where('outcome', 'success')->count())->toBe(1);

    // A single artifact is not held back by it.
    $post(['scope' => 'artifact', 'artifact_id' => 'abcdef0123456789abcdef0123456789'])->assertSessionHasNoErrors();
    Queue::assertPushed(OperatorReindexJob::class, 2);

    // The job frees the slot when it finishes, and puts the guard back.
    config(['indexing.enabled' => true]);
    expect(Cache::has(OperatorReindexJob::queuedKey($team->id)))->toBeTrue();
    $previous = User::factory()->create();
    auth()->setUser($previous);

    app()->call([new OperatorReindexJob($team->id), 'handle']);

    expect(Cache::has(OperatorReindexJob::queuedKey($team->id)))->toBeFalse()
        ->and(auth()->id())->toBe($previous->id);

    auth()->forgetUser();
    app()->call([new OperatorReindexJob($team->id), 'handle']);
    expect(auth()->hasUser())->toBeFalse();
});

test('plan_change_rechecks_the_locked_row', function () {
    Http::fake(['worker.test/*' => Http::response(['org' => 'race-org'])]);
    $team = Team::factory()->create(['slug' => 'race-org', 'plan' => Plan::Free, 'payment_status' => PaymentStatus::Active]);
    $staff = opsActionStaff();
    $request = Request::create('/ops/tenants/race-org/plan', 'POST');
    $request->setUserResolver(fn () => $staff);
    $action = app(ChangePlanAction::class);

    // A stale model: the page loaded it as free, then another change landed.
    $stale = Team::query()->findOrFail($team->id);
    Team::query()->whereKey($team->id)->update(['plan' => Plan::Team->value]);
    expect($stale->plan)->toBe(Plan::Free);

    // The request would record "free -> team" against the stale row. The
    // locked row is already team, so it is refused and nothing is written.
    expect(fn () => $action->run($request, $stale, Plan::Team, PaymentStatus::Active, true, OPS_ACTION_REASON))
        ->toThrow(ValidationException::class);

    expect(OperatorAuditEvent::query()->sole()->outcome)->toBe('failure');
    Http::assertNothingSent();

    // When the values still differ, the diff is computed from the locked row.
    Team::query()->whereKey($team->id)->update(['plan' => Plan::Enterprise->value]);
    $action->run($request, $stale, Plan::Free, PaymentStatus::Active, true, OPS_ACTION_REASON);

    expect($team->fresh()->plan)->toBe(Plan::Free);
    $copy = AuditEvent::query()->where('event_type', AuditEventType::OperatorPlanChanged)->where('outcome', 'success')->sole();
    expect($copy->reason)->toBe(OPS_ACTION_REASON.' (plan enterprise → free)');
    Http::assertSentCount(1);
});

test('erase_status_reaches_the_tenant_list', function () {
    $team = Team::factory()->create(['slug' => 'status-org']);
    $staff = opsActionStaff();

    $this->actingAs($staff)->get(route('ops.tenants.index'))
        ->assertInertia(fn (Assert $page) => $page->where('status', null));

    $this->actingAs($staff)->post(route('ops.tenants.erase.preview', $team));
    $this->actingAs($staff)->followingRedirects()
        ->post(route('ops.tenants.erase', $team), ['confirm_slug' => 'status-org', 'reason' => OPS_ACTION_REASON])
        ->assertInertia(fn (Assert $page) => $page
            ->component('ops/tenants/index')
            ->where('status', 'Tenant erased.'));
});
