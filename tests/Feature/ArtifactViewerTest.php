<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Contracts\PublicArtifactSource;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\ArtifactComment;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Artifacts\FakePublicArtifactSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * The artifact viewer (RUB-438): `/a/{id}` renders a thin header over a
 * sandboxed frame, and the sharing control over it.
 *
 * A secure artifact is framed with a short-lived signed link minted for the
 * click and audited; a public one is framed untokened; an artifact the caller
 * may not see is the same 404 as a missing one; and every sharing update
 * outcome is a plain redirect back rather than a dead end. The props, the mint
 * and the download are asserted here; the DOM is asserted in
 * `tests/Browser/ArtifactViewerBrowserTest.php`.
 */

/**
 * @param  array<string, mixed>  $overrides
 */
function seedViewerArtifact(Team $team, string $id, array $overrides = []): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact(array_merge([
        'id' => $id,
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => 'Quarterly report',
        'description' => 'A seeded report.',
        'content_hash' => md5($id),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function viewerProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** The team a viewer test uses, with the owner already a member. */
function viewerTeam(string $slug = 'view-team', string $name = 'View Team'): Team
{
    return Team::factory()->create(['slug' => $slug, 'name' => $name]);
}

test('a_member_views_a_team_artifact_with_a_signed_frame_and_an_audited_mint', function () {
    configureArtifactLinks();

    $team = viewerTeam();
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'owner_user_id' => (string) $member->id,
        'can_change_sharing' => false,
    ]);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('artifacts/show')
        ->where('team.slug', 'view-team')
        ->where('team.publicSharingAllowed', true)
        ->where('artifact.id', ARTIFACT_LINK_ID)
        ->where('artifact.title', 'Quarterly report')
        ->where('artifact.sharing', 'team')
        ->where('artifact.canChangeSharing', false)
        ->where('artifact.ownerName', $member->name)
        ->where('selectedVersion', null)
    );

    $props = viewerProps($response);

    expect($props['frameUrl'])->toStartWith(
        'https://view-team--'.ARTIFACT_LINK_ID.'.artfct.dev/p/'.ARTIFACT_LINK_ID.'/?token=',
    );

    parse_str((string) parse_url($props['frameUrl'], PHP_URL_QUERY), $query);
    $token = (string) ($query['token'] ?? '');

    expect($token)->not->toBe('')
        ->and(artifactTokenVerifies($token, ARTIFACT_LINK_ID, ARTIFACT_LINK_SECRET, now()->timestamp))->toBeTrue();

    // The token belongs to the frame URL and nowhere else — not the page props
    // (only the frame URL carries it, which is the point), and not the audit
    // row. The mint is audited with the actor and the expiry, exactly like the
    // console's open route.
    $event = AuditEvent::query()->where('event_type', AuditEventType::ArtifactLinkMinted)->sole();

    expect($event->team_id)->toBe($team->id)
        ->and($event->actor)->toBe((string) $member->id)
        ->and($event->target)->toBe(
            'artifact:'.ARTIFACT_LINK_ID.' expires:'.Carbon::createFromTimestamp((int) explode('.', $token)[1])->toIso8601String(),
        )
        ->and($event->target)->not->toContain($token);
});

test('a_public_artifact_is_framed_on_its_isolated_origin_without_a_token', function () {
    // No signing secret: a public artifact needs none, and minting one anyway
    // would put a credential in the frame for an artifact that never needs it.
    config([
        'services.artifact_access.token_secret' => null,
        'services.artifact_access.origin_suffix' => '.artfct.dev',
    ]);

    $team = viewerTeam();
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'public']);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID);

    $response->assertOk();

    $props = viewerProps($response);

    expect($props['frameUrl'])
        ->toBe('https://view-team--'.ARTIFACT_LINK_ID.'.artfct.dev/p/'.ARTIFACT_LINK_ID.'/')
        ->not->toContain('token')
        // A public artifact does not expire, so the open control is the same
        // untokened URL rather than a mint-on-click route.
        ->and($props['openUrl'])->toBe($props['frameUrl']);

    expect(AuditEvent::query()->where('event_type', AuditEventType::ArtifactLinkMinted)->exists())->toBeFalse();
});

test('an_artifact_the_worker_hides_is_the_same_404_as_a_missing_one', function () {
    $team = viewerTeam('private-team', 'Private Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'private',
        'hidden' => true,
    ]);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID)->assertNotFound();
    test()->actingAs($member)->get('/a/ffffffffffffffffffffffffffffffff')->assertNotFound();
});

test('an_admin_sees_the_sharing_control_for_a_private_artifact_the_worker_returns', function () {
    configureArtifactLinks();

    $team = viewerTeam('admin-team', 'Admin Team');
    $admin = memberOfTeam($team, TeamRole::Admin);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'private',
        'owner_user_id' => null,
        'can_change_sharing' => true,
    ]);

    test()->actingAs($admin)->get('/a/'.ARTIFACT_LINK_ID)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('artifact.sharing', 'private')
            ->where('artifact.canChangeSharing', true));
});

test('a_version_query_is_framed_at_that_exact_version', function () {
    configureArtifactLinks();

    $team = viewerTeam('version-team', 'Version Team');
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'team']);
    $directory->seedVersions(ARTIFACT_LINK_ID, [
        ['version' => 3, 'created_at' => now()->subDay()->toIso8601String(), 'current' => true],
        ['version' => 2, 'created_at' => now()->subDays(2)->toIso8601String(), 'current' => false],
        ['version' => 1, 'created_at' => now()->subDays(3)->toIso8601String(), 'current' => false],
    ], 3);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'?version=2');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedVersion', 2)
        ->where('artifact.version', 3)
        ->where('artifact.versionCount', 3)
        ->has('versions', 3));

    $props = viewerProps($response);

    expect($props['frameUrl'])->toStartWith(
        'https://version-team--'.ARTIFACT_LINK_ID.'.artfct.dev/p/'.ARTIFACT_LINK_ID.'/v:2/?token=',
    );

    // The version the picker names is carried into the open-in-new-tab route.
    expect($props['openUrl'])->toContain('version=2');
});

test('a_malformed_version_query_is_refused_rather_than_framed', function () {
    configureArtifactLinks();

    $team = viewerTeam('bad-version-team', 'Bad Version Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'team']);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'?version=0')->assertStatus(422);
    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'?version=later')->assertStatus(422);
});

test('a_member_of_two_teams_resolves_the_team_that_owns_the_artifact', function () {
    configureArtifactLinks();

    $currentTeam = Team::factory()->create(['slug' => 'team-a']);
    $ownerTeam = Team::factory()->create(['slug' => 'team-b']);
    $member = memberOfTeam($currentTeam, TeamRole::Member);
    $ownerTeam->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    $member->update(['current_team_id' => $currentTeam->id]);

    // The fake serves globally, so a subclass pins the artifact to team-b: the
    // viewer must keep looking past the current team to find it. Seed the
    // subclass itself — a fresh instance has none of the singleton's rows.
    $directory = new class extends FakeArtifactDirectory
    {
        public function getArtifact(string $orgSlug, string $artifactId): ?array
        {
            return $orgSlug === 'team-b' ? parent::getArtifact($orgSlug, $artifactId) : null;
        }
    };
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $ownerTeam->slug,
        'user_id' => 1,
        'title' => 'Team B report',
        'description' => null,
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);
    app()->instance(ArtifactDirectory::class, $directory);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('team.slug', 'team-b'));
});

test('an_artifact_id_that_is_not_permanent_is_a_404', function () {
    $team = viewerTeam();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get('/a/short')->assertNotFound();
    test()->actingAs($member)->get('/a/'.str_repeat('z', 40))->assertNotFound();
});

test('a_guest_is_sent_to_sign_in_and_returned_to_the_viewer', function () {
    test()->get('/a/'.ARTIFACT_LINK_ID)->assertRedirect(route('login'));
});

test('a_sharing_update_applies_the_change_and_flashes_a_success', function () {
    configureArtifactLinks();

    $team = viewerTeam('share-team', 'Share Team');
    $owner = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'can_change_sharing' => true,
    ]);

    test()->actingAs($owner)
        ->patch('/a/'.ARTIFACT_LINK_ID.'/sharing', ['sharing' => 'private', 'edit_access' => 'edit'])
        ->assertRedirect('/a/'.ARTIFACT_LINK_ID)
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'success',
            'message' => 'Sharing updated.',
        ]);

    $metadata = app(ArtifactDirectory::class)->getArtifact('share-team', ARTIFACT_LINK_ID);

    expect($metadata['sharing'])->toBe('private')
        ->and($metadata['edit_access'])->toBe('edit');
});

test('a_member_without_permission_cannot_change_sharing', function () {
    configureArtifactLinks();

    $team = viewerTeam('no-permission-team', 'No Permission Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'can_change_sharing' => false,
    ]);

    test()->actingAs($member)
        ->patch('/a/'.ARTIFACT_LINK_ID.'/sharing', ['sharing' => 'public'])
        ->assertRedirect('/a/'.ARTIFACT_LINK_ID)
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'error',
            'message' => 'Only the owner or a team admin can change sharing.',
        ]);

    expect(app(ArtifactDirectory::class)->getArtifact('no-permission-team', ARTIFACT_LINK_ID)['sharing'])->toBe('team');
});

test('choosing_public_when_the_team_turned_it_off_is_refused', function () {
    configureArtifactLinks();

    $team = viewerTeam('public-off-team', 'Public Off Team');
    $owner = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'can_change_sharing' => true,
        'public_sharing_allowed' => false,
    ]);

    test()->actingAs($owner)
        ->patch('/a/'.ARTIFACT_LINK_ID.'/sharing', ['sharing' => 'public'])
        ->assertRedirect('/a/'.ARTIFACT_LINK_ID)
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'error',
            'message' => 'Your team has turned off public links.',
        ]);

    expect(app(ArtifactDirectory::class)->getArtifact('public-off-team', ARTIFACT_LINK_ID)['sharing'])->toBe('team');
});

test('a_sharing_update_for_an_artifact_that_vanished_is_a_404', function () {
    $team = viewerTeam('vanish-team', 'Vanish Team');
    $owner = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'can_change_sharing' => true,
    ]);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->failSharing(ARTIFACT_LINK_ID, 'not_found');

    test()->actingAs($owner)
        ->patch('/a/'.ARTIFACT_LINK_ID.'/sharing', ['sharing' => 'team'])
        ->assertNotFound();
});

test('the_team_public_sharing_setting_travels_to_the_page', function () {
    configureArtifactLinks();

    $team = Team::factory()->create([
        'slug' => 'public-off-page',
        'name' => 'Public Off Page',
        'public_sharing_allowed' => false,
    ]);
    $owner = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'team', 'can_change_sharing' => true]);

    test()->actingAs($owner)->get('/a/'.ARTIFACT_LINK_ID)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('team.publicSharingAllowed', false)
            ->where('artifact.canChangeSharing', true));
});

test('download_returns_the_entrypoint_as_a_slugified_attachment', function () {
    $team = viewerTeam('download-team', 'Download Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'title' => 'Quarterly report',
    ]);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Download me</h1>');

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download');

    $response->assertOk();

    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('quarterly-report.html')
        ->and($response->getContent())->toBe('<h1>Download me</h1>');
});

test('download_is_refused_for_an_artifact_the_caller_cannot_see', function () {
    $team = viewerTeam('download-hidden-team', 'Download Hidden Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'private', 'hidden' => true]);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download')->assertNotFound();
});

test('a_member_comments_replies_and_resolves_on_the_viewer', function () {
    configureArtifactLinks();

    $team = viewerTeam('comment-team', 'Comment Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID);

    test()->actingAs($member)->post('/a/'.ARTIFACT_LINK_ID.'/comments', ['body' => 'Is this current?'])
        ->assertRedirect('/a/'.ARTIFACT_LINK_ID);
    $root = ArtifactComment::query()->firstOrFail();

    test()->actingAs($member)->post('/a/'.ARTIFACT_LINK_ID.'/comments', ['body' => 'Yes.', 'parent_id' => $root->id])
        ->assertRedirect('/a/'.ARTIFACT_LINK_ID);

    $props = viewerProps(test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID));
    expect(array_column($props['comments'], 'body'))->toBe(['Is this current?', 'Yes.'])
        ->and($props['comments'][1]['parent_id'])->toBe($root->id);

    test()->actingAs($member)->patch('/a/'.ARTIFACT_LINK_ID.'/comments/'.$root->id, ['resolved' => true])
        ->assertRedirect('/a/'.ARTIFACT_LINK_ID);
    expect($root->fresh()->resolved_at)->not->toBeNull();
});

test('comment_bodies_with_script_and_onerror_are_escaped_in_the_viewer_html', function () {
    configureArtifactLinks();

    $team = viewerTeam('comment-escape-team', 'Comment Escape Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'owner_user_id' => (string) $member->id,
    ]);

    $payload = '<script>alert(1)</script><img src=x onerror=alert(1)>';

    test()->actingAs($member)
        ->post('/a/'.ARTIFACT_LINK_ID.'/comments', ['body' => $payload])
        ->assertRedirect('/a/'.ARTIFACT_LINK_ID);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID)->assertOk();
    $props = viewerProps($response);

    // Stored and passed to the client as plain text — React text nodes escape
    // on render (asserted in the browser suite). The document must not embed
    // the comment as a live script tag outside the JSON page payload.
    expect($props['comments'][0]['body'])->toBe($payload);

    $html = $response->getContent();
    expect(substr_count($html, '<script>alert(1)</script>'))->toBe(0);
});

test('a_comment_on_an_artifact_the_member_cannot_see_is_a_404_and_stores_nothing', function () {
    configureArtifactLinks();

    $team = viewerTeam('comment-hidden-team', 'Comment Hidden Team');
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->post('/a/'.ARTIFACT_LINK_ID.'/comments', ['body' => 'hello'])->assertNotFound();

    expect(ArtifactComment::query()->count())->toBe(0);
});

test('the_comment_routes_are_throttled', function () {
    configureArtifactLinks();

    $team = viewerTeam('comment-throttle-team', 'Comment Throttle Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID);

    foreach (range(1, 30) as $n) {
        test()->actingAs($member)->post('/a/'.ARTIFACT_LINK_ID.'/comments', ['body' => "c{$n}"])->assertRedirect();
    }

    test()->actingAs($member)->post('/a/'.ARTIFACT_LINK_ID.'/comments', ['body' => 'one too many'])->assertStatus(429);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function seedFreshnessPublicArtifact(string $id, array $overrides = []): void
{
    /** @var FakePublicArtifactSource $public */
    $public = app(PublicArtifactSource::class);
    $public->seed($id, 'freshness-public-org', array_merge([
        'title' => 'A public freshness report',
        'description' => 'Anyone with the link.',
        'version' => 1,
        'version_count' => 1,
        'updated_at' => now()->subDay()->toIso8601String(),
        'edit_access' => 'view',
    ], $overrides));
}

test('a_member_polls_the_current_version_and_sharing_as_no_store_json', function () {
    $team = viewerTeam('freshness-team', 'Freshness Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'team', 'version' => 2]);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/freshness');

    $response->assertOk()
        ->assertExactJson(['version' => 2, 'sharing' => 'team', 'revoked' => false])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('a_freshness_poll_reflects_a_sharing_change_and_a_new_version', function () {
    $team = viewerTeam('freshness-change-team', 'Freshness Change Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'team', 'version' => 1]);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedVersions(ARTIFACT_LINK_ID, [
        ['version' => 2, 'created_at' => now()->subHour()->toIso8601String(), 'current' => true],
        ['version' => 1, 'created_at' => now()->subDay()->toIso8601String(), 'current' => false],
    ], 2);

    // The publish flips sharing and lands a new version; the next poll
    // reports both. Re-seeding replaces the earlier row by id.
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'private', 'version' => 1]);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/freshness');

    $response->assertOk()
        ->assertJson(['version' => 2, 'sharing' => 'private', 'revoked' => false]);
});

test('a_freshness_poll_without_the_known_header_is_a_404_for_a_revoked_artifact', function () {
    $team = viewerTeam('freshness-revoked-team', 'Freshness Revoked Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'revoked_at' => now()->toIso8601String(),
    ]);

    // The viewer itself 404s a revoked artifact, and a fresh poll — one that
    // never saw the artifact — gets the same answer, not a revocation signal.
    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID)->assertNotFound();
    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/freshness')->assertNotFound();
});

test('a_signed_in_member_gets_404_not_login_for_a_deleted_artifact', function () {
    $team = viewerTeam('deleted-viewer-team', 'Deleted Viewer Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'revoked_at' => now()->toIso8601String(),
    ]);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID);

    $response->assertNotFound();
    expect($response->headers->get('Location'))->toBeNull();
});

test('a_freshness_poll_with_the_known_header_reports_revoked_for_a_prior_viewer', function () {
    $team = viewerTeam('freshness-known-team', 'Freshness Known Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, [
        'sharing' => 'team',
        'revoked_at' => now()->toIso8601String(),
    ]);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/freshness', ['X-Freshness-Known' => '1']);

    $response->assertOk()
        ->assertExactJson(['version' => null, 'sharing' => null, 'revoked' => true])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('a_signed_out_visitor_is_sent_to_sign_in_for_a_team_artifact_poll', function () {
    $team = viewerTeam('freshness-guest-team', 'Freshness Guest Team');
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'team']);

    test()->get('/a/'.ARTIFACT_LINK_ID.'/freshness')->assertRedirect(route('login'));
});

test('a_signed_out_visitor_polls_a_public_artifact_without_a_session', function () {
    seedFreshnessPublicArtifact(ARTIFACT_LINK_ID, ['version' => 3]);

    $response = test()->get('/a/'.ARTIFACT_LINK_ID.'/freshness');

    $response->assertOk()
        ->assertExactJson(['version' => 3, 'sharing' => 'public', 'revoked' => false]);
});

test('a_freshness_poll_for_a_malformed_id_is_a_404', function () {
    $team = viewerTeam('freshness-malformed-team', 'Freshness Malformed Team');
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get('/a/short/freshness')->assertNotFound();
});

test('a_signed_in_non_member_gets_the_same_404_as_a_missing_poll', function () {
    $victimTeam = Team::factory()->create(['slug' => 'freshness-victim']);
    $outsiderTeam = Team::factory()->create(['slug' => 'freshness-outsider']);
    $outsider = memberOfTeam($outsiderTeam, TeamRole::Member);

    // The directory fake serves every org, so pin it to the victim's: the
    // outsider's own teams must not resolve the artifact.
    $directory = new class extends FakeArtifactDirectory
    {
        public function getArtifact(string $orgSlug, string $artifactId): ?array
        {
            return $orgSlug === 'freshness-victim' ? parent::getArtifact($orgSlug, $artifactId) : null;
        }
    };
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $victimTeam->slug,
        'user_id' => 1,
        'title' => 'Someone else’s report',
        'description' => null,
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);
    app()->instance(ArtifactDirectory::class, $directory);

    // Without the header this is the same 404 as a missing id; with it the
    // outsider holds no prior view, but membership alone must not mint an
    // oracle — the known-viewer branch answers revoked the same for any id
    // their teams do not resolve, missing or not.
    test()->actingAs($outsider)->get('/a/'.ARTIFACT_LINK_ID.'/freshness')->assertNotFound();
    test()->actingAs($outsider)->get('/a/ffffffffffffffffffffffffffffffff/freshness', ['X-Freshness-Known' => '1'])
        ->assertOk()
        ->assertExactJson(['version' => null, 'sharing' => null, 'revoked' => true]);
});

test('a_freshness_poll_mints_no_link_and_writes_no_audit_row', function () {
    configureArtifactLinks();

    $team = viewerTeam('freshness-quiet-team', 'Freshness Quiet Team');
    $member = memberOfTeam($team, TeamRole::Member);
    seedViewerArtifact($team, ARTIFACT_LINK_ID, ['sharing' => 'team']);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/freshness');

    $response->assertOk();

    expect(AuditEvent::query()->where('event_type', AuditEventType::ArtifactLinkMinted)->exists())->toBeFalse();
});
