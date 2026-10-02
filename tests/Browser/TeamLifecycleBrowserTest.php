<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\User;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('new_user_signs_up_becomes_owner_and_invites_a_teammate', function () {
    $page = visit('/login');

    $page->assertNoJavaScriptErrors()
        ->fill('email', 'captain@example.com')
        ->fill('name', 'Casey Captain')
        ->click('Continue with Google')
        ->assertSee('Create your first team')
        ->click('Create team')
        ->assertSee('What your AI makes')
        ->assertNoJavaScriptErrors();

    $page->navigate('/settings/teams')
        ->assertSee('Your orgs')
        ->fill('name', 'Northwind Analytics')
        ->click('Create org')
        ->assertSee('Northwind Analytics')
        ->assertSee('owner')
        ->fill('email', 'teammate@example.com')
        ->click('Send invite')
        ->assertSee('teammate@example.com')
        ->assertNoJavaScriptErrors();
});

test('billing_and_token_pages_render_without_errors', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Pages Co');

    test()->actingAs($owner);

    visit(route('teams.billing.show', $team))
        ->assertSee('Billing')
        ->assertSee('Current plan')
        ->assertSee('What each plan includes')
        ->assertNoJavaScriptErrors();

    visit(route('teams.tokens.index', $team))
        ->assertSee('API tokens')
        ->assertSee('Create a token')
        ->assertNoJavaScriptErrors();

    visit(route('teams.edit', $team))
        ->assertSee('Members')
        ->assertSee('Danger zone')
        ->assertNoJavaScriptErrors();
});

test('admin_can_change_a_role_and_owner_is_protected', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com', 'name' => 'Olive Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Roles Co');
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($owner);

    $page = visit(route('teams.edit', $team));
    $page->assertSee($member->name)->assertNoJavaScriptErrors();

    expect($team->memberships()->where('user_id', $member->id)->value('role'))->toBe(TeamRole::Member);
});

test('account_and_audit_pages_render_without_errors', function () {
    $owner = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Ada Admin']);
    $team = app(CreateTeam::class)->handle($owner, 'Audit Co');

    test()->actingAs($owner);

    visit(route('account.show'))
        ->assertSee('Account')
        ->assertSee('Linked identities')
        ->assertSee('Delete account')
        ->assertNoJavaScriptErrors();

    visit(route('teams.audit.index', $team))
        ->assertSee('Audit log')
        ->assertNoJavaScriptErrors();

    visit(route('dashboard', $team))
        ->assertSee('Get your team set up')
        ->assertSee('Invite your teammates')
        ->assertNoJavaScriptErrors();
});

test('invite_resend_revoke_flow', function () {
    Notification::fake();
    $owner = User::factory()->create(['email' => 'owner2@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Resend Co');
    $invitation = $team->invitations()->create(['email' => 'pending@example.com', 'role' => TeamRole::Member, 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);
    $oldCode = $invitation->code;

    test()->actingAs($owner);

    $page = visit(route('teams.edit', $team))
        ->assertSee('pending@example.com')
        ->click('Resend')
        ->wait(1);

    expect($invitation->fresh()->code)->not->toBe($oldCode);

    $page->click('Cancel')->wait(1)->assertDontSee('pending@example.com')->assertNoJavaScriptErrors();

    expect($team->invitations()->count())->toBe(0);
});

test('search_and_collections_pages_render_without_errors', function () {
    config(['indexing.enabled' => false]);
    $owner = User::factory()->create(['email' => 'searcher@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Search Co');

    test()->actingAs($owner);

    visit(route('teams.search', $team))
        ->assertSee('Search indexing is turned off')
        ->assertNoJavaScriptErrors();

    visit(route('teams.collections.index', $team))
        ->assertSee('Collections')
        ->assertSee('No collections yet.')
        ->assertNoJavaScriptErrors();
});

test('search_and_open_result', function () {
    config([
        'indexing.enabled' => true,
        'services.artifact_access.token_secret' => 'browser-artifact-secret',
    ]);

    $owner = User::factory()->create(['email' => 'search-result@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Search Results Co');
    $artifactId = 'search-result-artifact';
    $query = 'billing dashboard revenue';

    /** @var ArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $artifactId,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Billing dashboard',
        'description' => 'The quarterly billing dashboard.',
        'content_hash' => md5($artifactId),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => [
            'agent' => 'cursor',
            'repo_url' => 'https://github.com/acme/billing',
            'commit_sha' => 'deadbeef',
        ],
    ]);

    /** @var EmbeddingsContract $embeddings */
    $embeddings = app(EmbeddingsContract::class);
    /** @var VectorIndexContract $vectorIndex */
    $vectorIndex = app(VectorIndexContract::class);
    $vectorIndex->upsertChunks($team->slug, $artifactId, [
        new VectorChunk(
            text: $query,
            vector: $embeddings->embed([$query])[0],
            artifactId: $artifactId,
            orgId: $team->slug,
            createdAt: now()->subDay()->toIso8601String(),
            agent: 'cursor',
            repoUrl: 'https://github.com/acme/billing',
            commitSha: 'deadbeef',
        ),
    ]);

    test()->actingAs($owner);

    $page = visit(route('teams.search', $team))
        ->assertNoJavaScriptErrors()
        ->fill('input[aria-label="Search query"]', $query)
        ->click('button[type="submit"]')
        ->wait(1)
        ->assertSee('Billing dashboard')
        ->assertSee('billing dashboard revenue')
        ->assertSee('cursor')
        ->assertNoJavaScriptErrors();

    $page
        ->assertAttributeContains('@search-result-link', 'href', '/open')
        ->assertAttributeDoesntContain('@search-result-link', 'href', 'token=')
        ->assertAttribute('@search-result-link', 'target', '_blank');
});

test('terms_and_privacy_pages_are_public_and_render', function () {
    visit('/terms')
        ->assertSee('Terms of service')
        ->assertSee('Draft text')
        ->assertNoJavaScriptErrors();

    visit('/privacy')
        ->assertSee('Privacy policy')
        ->assertNoJavaScriptErrors();
});

test('a_new_user_must_accept_the_terms_before_the_app', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'browser-v1']);
    $owner = User::factory()->create(['email' => 'consent@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Consent Co');

    test()->actingAs($owner);

    visit(route('dashboard', $team))
        ->assertSee('One more step')
        ->check('accepted')
        ->click('Accept and continue')
        ->wait(2)
        ->assertSee('Get your team set up')
        ->assertNoJavaScriptErrors();

    expect($owner->fresh()->terms_version)->toBe('browser-v1');
});

test('governance_page_renders_for_admins', function () {
    $owner = User::factory()->create(['email' => 'gov@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Gov Co');

    test()->actingAs($owner);

    visit(route('teams.governance.show', $team))
        ->assertSee('Governance')
        ->assertSee('Preview what would be deleted')
        ->assertSee('Legal hold')
        ->assertNoJavaScriptErrors();
});

test('authentication_page_renders_with_mode_previews', function () {
    config(['services.polis' => []]);
    $owner = User::factory()->create(['email' => 'sso-admin@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'SSO Co');

    test()->actingAs($owner);

    visit(route('teams.authentication.show', $team))
        ->assertSee('Authentication')
        ->assertSee('Verified domains')
        ->assertSee('Enterprise feature')
        ->assertSee('no verified domain')
        ->assertNoJavaScriptErrors();
});
