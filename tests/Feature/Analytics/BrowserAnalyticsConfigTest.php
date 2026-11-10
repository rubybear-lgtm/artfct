<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

test('D1 inertia shares analytics config and it is disabled without a key', function () {
    config([
        'analytics.enabled' => false,
        'analytics.api_key' => null,
        'analytics.host' => 'https://us.i.posthog.com',
        'analytics.environment' => 'local',
    ]);

    $team = Team::factory()->create();
    $user = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('analytics.enabled', false)
            ->where('analytics.apiKey', null)
            ->where('analytics.host', 'https://us.i.posthog.com')
            ->has('currentTeam.slug')
            ->has('auth.user.id'));
});

test('D1 analytics config is exposed when enabled with a public key', function () {
    config([
        'analytics.enabled' => true,
        'analytics.api_key' => 'phc_public_test_key',
        'analytics.host' => 'https://us.i.posthog.com',
        'analytics.environment' => 'staging',
    ]);

    $team = Team::factory()->create();
    $user = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('analytics.enabled', true)
            ->where('analytics.apiKey', 'phc_public_test_key')
            ->where('analytics.environment', 'staging')
            ->where('currentTeam.id', $team->id)
            ->where('currentTeam.isPersonal', false));
});

test('D2 Worker public artifact path sources do not reference posthog', function () {
    $paths = [
        base_path('backend/src'),
        base_path('backend/wrangler.jsonc'),
    ];

    foreach ($paths as $path) {
        $iterator = File::isDirectory($path)
            ? File::allFiles($path)
            : [new SplFileInfo($path)];

        foreach ($iterator as $file) {
            $contents = File::get($file->getPathname());
            expect(strtolower($contents))->not->toContain('posthog');
        }
    }

    expect(File::get(base_path('resources/js/lib/analytics.ts')))
        ->toContain('Never load this on Worker')
        ->toContain("persistence: 'memory'")
        ->toContain('disable_session_recording: true');
});

test('D3 browser init defaults keep cookieless memory persistence and replay off', function () {
    $source = File::get(base_path('resources/js/lib/analytics.ts'));

    expect($source)->toContain("persistence: 'memory'")
        ->and($source)->toContain('disable_session_recording: true')
        ->and($source)->toContain('person_profiles: \'identified_only\'')
        ->and($source)->toContain('autocapture:')
        ->and($source)->toContain("dom_event_allowlist: ['click']");
});

test('D1 identify and team group update after first init (Inertia team switch)', function () {
    $source = File::get(base_path('resources/js/lib/analytics.ts'));

    // Init is gated once; identify/group must run on later prop changes.
    expect($source)->toContain('if (!initialized)')
        ->and($source)->toContain('initialized = true')
        ->and($source)->toContain('posthog.identify(String(user.id))')
        ->and($source)->toContain("posthog.group('team', team.slug");

    $initBlockEnd = strpos($source, 'initialized = true');
    $identifyPos = strpos($source, 'posthog.identify(String(user.id))');
    $groupPos = strpos($source, "posthog.group('team', team.slug");

    expect($initBlockEnd)->not->toBeFalse()
        ->and($identifyPos)->toBeGreaterThan($initBlockEnd)
        ->and($groupPos)->toBeGreaterThan($initBlockEnd);
});
