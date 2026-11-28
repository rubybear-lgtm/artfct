<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Enums\FeatureReleaseState;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Collection;
use App\Models\ExternalIdentity;
use App\Models\FeatureRelease;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\FeatureFlags;
use App\Services\Templates\TemplateFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('rub_632_and_rub_633_ux_fixes_work_end_to_end_in_browser', function () {
    config([
        'services.artifact_access.token_secret' => 'browser-artifact-secret',
        'services.artifact_access.origin_suffix' => '.localhost',
        'services.stripe.secret' => 'sk_test_fake',
        'services.polis' => [],
    ]);

    FeatureRelease::query()->updateOrCreate(
        ['key' => 'unified-rail', 'environment' => app()->environment()],
        ['state' => FeatureReleaseState::Released, 'selected_team_ids' => []],
    );
    FeatureRelease::query()->updateOrCreate(
        ['key' => TemplateFeatures::ARTIFACT_TEMPLATES, 'environment' => app()->environment()],
        ['state' => FeatureReleaseState::Released, 'selected_team_ids' => []],
    );
    FeatureRelease::query()->updateOrCreate(
        ['key' => 'artifact-db', 'environment' => app()->environment()],
        ['state' => FeatureReleaseState::Released, 'selected_team_ids' => []],
    );
    app(FeatureFlags::class)->flush();

    $owner = User::factory()->create(['name' => 'Hoku', 'email' => 'hoku@artfct.dev']);
    $team = app(CreateTeam::class)->handle($owner, 'Artfct Dev');
    $team->forceFill(['plan' => Plan::Pro, 'trial_started_at' => null, 'trial_ends_at' => null])->save();
    $owner->forceFill(['current_team_id' => $team->id])->save();

    $botHoku = User::factory()->bot()->create(['name' => 'Hoku', 'email' => 'hoku-bot@artfct.internal']);
    $team->memberships()->create(['user_id' => $botHoku->id, 'role' => TeamRole::Member]);

    ExternalIdentity::query()->create([
        'user_id' => $owner->id,
        'provider' => 'GoogleOAuth',
        'external_id' => 'ext-hoku-1',
        'email' => $owner->email,
        'verified_at' => now(),
    ]);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);

    $directory->seedArtifact([
        'id' => 'o4zi6apaugzey',
        'org_id' => $team->slug,
        'user_id' => (string) $owner->id,
        'title' => 'Artfct UX Walkthrough',
        'description' => 'Audit report',
        'content_hash' => md5('o4zi6apaugzey'),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'can_change_sharing' => true,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);
    $content->seed(
        $team->slug,
        'o4zi6apaugzey',
        '<!doctype html><html><body><h1>Report</h1><script>console.error("should be stripped")</script></body></html>',
        sharing: 'team',
    );

    $col1 = Collection::create(['team_id' => $team->id, 'name' => 'Design & UX', 'canonical' => true, 'created_by_user_id' => $owner->id]);
    $col1->artifacts()->create(['artifact_id' => 'o4zi6apaugzey', 'added_at' => now()]);
    foreach (['Engineering Specs', 'Product Briefs', 'Onboarding Guides', 'Architecture Notes', 'Release Logs', 'Customer Interviews', 'AI Answer Question Maps', 'Search Reports'] as $name) {
        Collection::create(['team_id' => $team->id, 'name' => $name, 'created_by_user_id' => $owner->id]);
    }

    McpConnection::factory()->count(2)->create(['team_id' => $team->id, 'user_id' => $owner->id]);
    McpConnection::factory()->revoked()->create(['team_id' => $team->id, 'user_id' => $owner->id]);

    AuditEvent::create([
        'team_id' => $team->id,
        'event_type' => AuditEventType::MemberAdded,
        'actor' => (string) $owner->id,
        'target' => 'member',
        'ip' => '127.0.0.1',
        'user_agent' => 'pest',
        'outcome' => 'success',
    ]);
    AuditEvent::create([
        'team_id' => $team->id,
        'event_type' => AuditEventType::TokenCreated,
        'actor' => (string) $botHoku->id,
        'target' => 'token',
        'ip' => '127.0.0.1',
        'user_agent' => 'pest',
        'outcome' => 'success',
    ]);

    test()->actingAs($owner);

    // 1. /console at 1280x800: primary nav not compressed, artifacts above the fold, AI tool label, /a/<id> links, no sandbox console errors.
    $page = visit("/settings/teams/{$team->slug}/console")
        ->resize(1280, 800)
        ->wait(0.6)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertVisible('[data-testid="artifact-grid"]')
        ->assertSee('Artfct UX Walkthrough')
        ->assertDontSee('Start from a reviewed page instead of a blank one.')
        ->assertSeeIn('[data-testid="console-rail-facets"]', 'AI tool')
        ->assertPresent('a[data-testid="open-artifact-title"][href$="/a/o4zi6apaugzey"]')
        ->assertAttributeDoesntContain('@open-artifact-title', 'href', '/open');

    $footerText = $page->script("document.querySelector('footer')?.textContent ?? ''");
    expect($footerText)->toContain('Docs')->not->toContain('Documentation');

    $primaryHeight = $page->script("document.querySelector('nav[aria-label=\"Primary\"]')?.getBoundingClientRect().height ?? 0");
    $adminVisible = $page->script("(() => { const el = document.querySelector('[data-testid=\"nav-admin\"]'); const r = el?.getBoundingClientRect(); return Boolean(r && r.height > 20 && r.bottom <= 800); })()");
    expect($primaryHeight)->toBeGreaterThan(150)
        ->and($adminVisible)->toBeTrue();

    // 2. /collections: primary nav remains visible, bottom rail item reachable, no redundant modal toggle, dark-mode link contrast uses --accent.
    $page->navigate("/settings/teams/{$team->slug}/collections")
        ->resize(1280, 800)
        ->wait(0.5)
        ->assertNoJavaScriptErrors()
        ->assertVisible('nav[aria-label="Primary"]')
        ->assertVisible('[data-testid="collections-rail-index"]')
        ->assertSee('Search Reports')
        ->assertMissing("[data-testid=\"collection-toggle-{$col1->id}\"]");

    // 3. /mcp-connections: balanced grid (top of "What each tool can do" matches "Your Artfct address"), complete ON THIS PAGE menu, active vs total label.
    $page->navigate("/settings/teams/{$team->slug}/mcp-connections")
        ->resize(1280, 800)
        ->wait(0.4)
        ->assertNoJavaScriptErrors()
        ->assertSee('2 active · 3 total (1 revoked or expired)')
        ->assertSeeIn('nav[aria-label="On this page"]', 'Connected AI tools')
        ->assertSeeIn('nav[aria-label="On this page"]', 'Recent activity');

    $topsAligned = $page->script("(() => { const a = document.evaluate(\"//p[text()='Your Artfct address']/parent::div\", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue?.getBoundingClientRect().top; const b = document.evaluate(\"//p[text()='What each tool can do']/parent::div\", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue?.getBoundingClientRect().top; return a !== undefined && b !== undefined && Math.abs(a - b) < 4; })()");
    expect($topsAligned)->toBeTrue();

    // 4. /admin: Pro KPI populated, Members nav has no Upgrade badge, Active AI tools + total shown.
    $page->navigate("/settings/teams/{$team->slug}/admin")
        ->resize(1280, 800)
        ->wait(0.4)
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('[data-testid="admin-kpi-plan"]', 'Pro')
        ->assertSee('3 total registered')
        ->assertDontSee('Members · Upgrade');

    // 5. /billing: 1 person grammar, Pro badge, Upgrade to Team CTA, 4 tiers including Pro & Enterprise.
    $page->navigate("/settings/teams/{$team->slug}/billing")
        ->resize(1280, 800)
        ->wait(0.4)
        ->assertNoJavaScriptErrors()
        ->assertSee('1 person on this workspace')
        ->assertSee('Upgrade to Team')
        ->assertSee('Enterprise')
        ->assertSee('Pro');

    // 6. /settings/teams/:team: 1 person and 1 bot grammar, no duplicated Authentication form.
    $page->navigate("/settings/teams/{$team->slug}")
        ->resize(1280, 800)
        ->wait(0.4)
        ->assertNoJavaScriptErrors()
        ->assertSee('1 person and 1 bot on this team.')
        ->assertSee('Open sign-in and security')
        ->assertMissing('#auth-mode');

    // 7. /audit: deduplicated Person dropdown options.
    $page->navigate("/settings/teams/{$team->slug}/audit")
        ->resize(1280, 800)
        ->wait(0.4)
        ->assertNoJavaScriptErrors();

    $personOptions = $page->script("Array.from(document.querySelectorAll('select')[1]?.querySelectorAll('option') ?? []).map(o => o.textContent?.trim())");
    expect($personOptions)->toContain('Hoku (bot)', 'Hoku (hoku@artfct.dev)')
        ->and(count($personOptions))->toBe(count(array_unique($personOptions)));

    // 8. /settings/account: real identity provider name.
    $page->navigate('/settings/account')
        ->resize(1280, 800)
        ->wait(0.4)
        ->assertNoJavaScriptErrors()
        ->assertSee('Google · hoku@artfct.dev')
        ->assertDontSee('unknown · hoku@artfct.dev');
});
