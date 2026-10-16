<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\ArtifactChunk;
use App\Models\ArtifactComment;
use App\Models\ArtifactIndexEntry;
use App\Models\AuditEvent;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Operator\TenantDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function contentBoundaryStaff(): User
{
    $staff = User::factory()->create();
    $staff->forceFill(['staff_at' => now()])->save();

    return $staff;
}

/** Strings that must never reach a staff response. */
const CONTENT_BOUNDARY_SENTINELS = [
    'title' => 'SentinelQuarterlyRoadmapTitle',
    'description' => 'SentinelArtifactDescription',
    'html' => 'SentinelRenderedBodyHtml',
    'index_title' => 'SentinelIndexedTitle',
    'heading' => 'SentinelIndexedHeading',
    'chunk' => 'SentinelChunkText',
    'comment' => 'SentinelCommentBody',
    'collection' => 'SentinelCollectionName',
    'collection_description' => 'SentinelCollectionDescription',
    'query' => 'SentinelSearchQueryText',
    'artifact_target' => 'SentinelArtifactTargetTitle',
    'repo' => 'sentinel-repo-url',
    'ip' => '203.0.113.77',
    'user_agent' => 'SentinelUserAgent',
    'reason' => 'SentinelAuditReason',
];

/**
 * Seeds every place content or something derived from it lives for a tenant:
 * the Worker-side directory and content source (fakes), indexed text, chunks,
 * a comment, a collection, and audit rows whose target holds a title or a
 * search query.
 */
function contentBoundarySeedTenant(Team $team): void
{
    $s = CONTENT_BOUNDARY_SENTINELS;
    $artifactId = 'abcdef0123456789abcdef0123456789';

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $artifactId,
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => $s['title'],
        'description' => $s['description'],
        'content_hash' => md5($artifactId),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => "https://github.com/acme/{$s['repo']}", 'commit_sha' => 'deadbeef'],
    ]);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, $artifactId, "<html><body>{$s['html']}</body></html>");

    ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'artifact_id' => $artifactId,
        'title' => $s['index_title'],
        'headings' => [$s['heading']],
        'extracted_text' => $s['chunk'],
    ]);
    ArtifactChunk::factory()->create([
        'team_id' => $team->id,
        'artifact_id' => $artifactId,
        'text' => $s['chunk'],
        'search_text' => $s['chunk'],
        'repo_url' => "https://github.com/acme/{$s['repo']}",
    ]);
    ArtifactComment::factory()->create(['team_id' => $team->id, 'artifact_id' => $artifactId, 'body' => $s['comment']]);
    Collection::factory()->create([
        'team_id' => $team->id,
        'name' => $s['collection'],
        'description' => $s['collection_description'],
    ]);

    foreach ([
        [AuditEventType::SearchPerformed, $s['query']],
        [AuditEventType::ArtifactCreated, "{$artifactId} {$s['artifact_target']}"],
        [AuditEventType::ArtifactViewed, $s['artifact_target']],
    ] as [$type, $target]) {
        AuditEvent::factory()->create([
            'team_id' => $team->id,
            'event_type' => $type,
            'actor' => '1',
            'target' => $target,
            'ip' => $s['ip'],
            'user_agent' => $s['user_agent'],
            'reason' => $s['reason'],
        ]);
    }
}

/**
 * Every `/ops` page, rendered as the browser gets it and as an Inertia JSON
 * visit, with the deferred usage loaded.
 *
 * @return list<string>
 */
function contentBoundaryBodies(User $staff, Team $team): array
{
    $urls = [
        route('ops.tenants.index'),
        route('ops.tenants.index', ['q' => $team->slug]),
        route('ops.tenants.show', $team->slug),
        route('ops.health'),
    ];

    $bodies = [];

    $version = '';

    foreach ($urls as $url) {
        $page = test()->actingAs($staff)->get($url)->assertOk();
        $version = (string) $page->viewData('page')['version'];
        $bodies[] = $page->getContent();
        $bodies[] = test()->actingAs($staff)
            ->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->assertOk()
            ->getContent();
    }

    $partial = test()->actingAs($staff)->get(route('ops.tenants.show', $team->slug), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'ops/tenants/show',
        'X-Inertia-Partial-Data' => 'usage',
    ])->assertOk();

    $bodies[] = $partial->getContent();

    return $bodies;
}

test('tenant_detail_exposes_no_artifact_titles_or_content', function () {
    $team = Team::factory()->create(['slug' => 'boundary-tenant']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    contentBoundarySeedTenant($team);

    // The seeding is real: the same strings are readable where they live.
    expect(ArtifactChunk::query()->where('team_id', $team->id)->value('text'))->toBe(CONTENT_BOUNDARY_SENTINELS['chunk'])
        ->and(ArtifactComment::query()->where('team_id', $team->id)->value('body'))->toBe(CONTENT_BOUNDARY_SENTINELS['comment'])
        ->and(AuditEvent::query()->where('team_id', $team->id)->count())->toBe(3);

    foreach (contentBoundaryBodies(contentBoundaryStaff(), $team) as $body) {
        foreach (CONTENT_BOUNDARY_SENTINELS as $name => $sentinel) {
            expect($body)->not->toContain($sentinel, "the {$name} sentinel reached a staff page");
        }
    }

    // And the page still shows what it is for, so the loop above is not
    // passing on an empty page.
    test()->actingAs(contentBoundaryStaff())->get(route('ops.tenants.show', $team->slug))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.slug', 'boundary-tenant')
            ->where('members.admins.0.email', $admin->email)
            ->has('events'));
});

test('recent_events_omit_target_for_search_and_artifact_events', function () {
    $team = Team::factory()->create(['slug' => 'events-tenant']);
    $member = memberOfTeam($team, TeamRole::Member);

    $at = now()->subMinutes(10);
    $rows = [
        [AuditEventType::SearchPerformed, 'quarterly revenue forecast', '3 result(s)'],
        [AuditEventType::ArtifactCreated, 'artifact:abc Roadmap deck', 'success'],
        [AuditEventType::ArtifactViewed, 'abc', 'success'],
        [AuditEventType::LegalHoldApplied, 'artifact:abc', 'success'],
        [AuditEventType::ShareCreated, 'abc Roadmap deck', 'success'],
        [AuditEventType::MemberAdded, 'new-member@example.test', 'success'],
        [AuditEventType::TokenCreated, 'ci-token', 'success'],
    ];

    foreach ($rows as $index => [$type, $target, $outcome]) {
        AuditEvent::factory()->create([
            'team_id' => $team->id,
            'event_type' => $type,
            'actor' => (string) $member->id,
            'target' => $target,
            'outcome' => $outcome,
            'created_at' => $at->copy()->addSeconds($index),
        ]);
    }

    $response = test()->actingAs(contentBoundaryStaff())->get(route('ops.tenants.show', $team->slug))->assertOk();
    $events = collect($response->viewData('page')['props']['events'])->keyBy('type');

    // The search event is shown: type, actor, outcome and time, no query.
    expect($events['search.performed'])->toMatchArray([
        'type_label' => 'Search run',
        'actor_label' => $member->name,
        'target' => null,
        'outcome' => '3 result(s)',
    ])
        ->and($events['search.performed']['at'])->toBe($at->toIso8601String())
        ->and($response->getContent())->not->toContain('quarterly revenue forecast')
        ->and($response->getContent())->not->toContain('Roadmap deck');

    foreach (['artifact.created', 'artifact.viewed', 'legal_hold.applied', 'share.created'] as $type) {
        expect($events[$type]['target'])->toBeNull("{$type} leaked its target");
    }

    // The allowlisted types keep their target, so the guard is not "always null".
    expect($events['member.added']['target'])->toBe('new-member@example.test')
        ->and($events['token.created']['target'])->toBe('ci-token')
        ->and(array_keys($events['search.performed']))->toBe(['id', 'type', 'type_label', 'actor_label', 'target', 'outcome', 'at']);
});

test('recent_events_omit_target_for_an_event_type_this_build_does_not_know', function () {
    $team = Team::factory()->create(['slug' => 'unknown-events']);

    AuditEvent::factory()->create(['team_id' => $team->id]);
    DB::table('audit_events')->update([
        'event_type' => 'artifact.annotated',
        'target' => 'SentinelUnknownTarget',
    ]);

    $response = test()->actingAs(contentBoundaryStaff())->get(route('ops.tenants.show', $team->slug))->assertOk();
    $event = collect($response->viewData('page')['props']['events'])->firstWhere('type', 'artifact.annotated');

    expect($event)->not->toBeNull()
        ->and($event['type'])->toBe('artifact.annotated')
        ->and($event['target'])->toBeNull()
        ->and($response->getContent())->not->toContain('SentinelUnknownTarget');
});

test('team_admin_still_reads_private_artifacts_and_exports', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'admin-reads']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $owner = memberOfTeam($team, TeamRole::Member);
    $artifactId = ARTIFACT_LINK_ID;

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $artifactId,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Private owner notes',
        'description' => 'Only the owner and admins.',
        'content_hash' => md5($artifactId),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'private',
        'owner_user_id' => (string) $owner->id,
        'can_change_sharing' => true,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($admin)->get('/a/'.$artifactId)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('artifact.id', $artifactId)
            ->where('artifact.title', 'Private owner notes')
            ->where('artifact.sharing', 'private'));

    $export = test()->actingAs($admin)->get("/settings/teams/{$team->slug}/console/export");
    $export->assertOk()->assertDownload();

    expect($export)->toBeInstanceOf(TestResponse::class)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ExportPerformed)->where('team_id', $team->id)->count())->toBe(1);

    // Staff who are not members of the tenant get none of it.
    $staff = contentBoundaryStaff();

    test()->actingAs($staff)->get('/a/'.$artifactId)->assertNotFound();
    expect(test()->actingAs($staff)->get("/settings/teams/{$team->slug}/console/export")->getStatusCode())->toBeIn([403, 404]);
});

test('every_audit_event_type_has_an_explicit_target_decision', function () {
    // Written out by hand, deliberately not derived from the production
    // allowlist: a new `AuditEventType` case fails this test until a person
    // decides whether its target can carry content.
    $showsTarget = [
        'member.added',
        'member.removed',
        'member.left',
        'role.changed',
        'token.created',
        'token.revoked',
        'auth_mode.changed',
        'owner.transferred',
        'export.performed',
        'retention.applied',
        'billing.seats_synced',
        'billing.subscription_cancelled',
        'billing.subscription_resumed',
        'account.deleted',
        'invitation.declined',
        'team.created',
        'team.renamed',
        'team.deleted',
        'public_sharing.changed',
        'mcp.connection_created',
        'mcp.connection_refreshed',
        'mcp.connection_revoked',
        'mcp.connection_reauthorized',
        'staff.granted',
        'staff.revoked',
        'operator.tenant_viewed',
        'operator.reindex_requested',
        'operator.limits_changed',
        'operator.plan_changed',
        'operator.tenant_erased',
        'operator.erase_started',
        'operator.case_created',
        'operator.case_renewed',
        'operator.case_released',
        'operator.case_denied',
    ];

    foreach (AuditEventType::cases() as $case) {
        expect(TenantDetail::showsTargetFor($case->value))
            ->toBe(in_array($case->value, $showsTarget, true), "{$case->value} has no explicit target decision, or it disagrees with the allowlist");
    }

    // Content-bearing types stay hidden, and so does anything unknown.
    foreach (['artifact.created', 'artifact.viewed', 'share.created', 'legal_hold.applied', 'search.performed', 'artifact.annotated'] as $hidden) {
        expect(TenantDetail::showsTargetFor($hidden))->toBeFalse();
    }
});
