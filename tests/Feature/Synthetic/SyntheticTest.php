<?php

use App\Enums\AuditEventType;
use App\Enums\PaymentStatus;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactUsageEvent;
use App\Models\AuditEvent;
use App\Models\Collection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Synthetic\SyntheticCorpus;
use App\Services\Synthetic\SyntheticFile;
use App\Services\Synthetic\SyntheticSeeder;
use App\Services\Synthetic\WorkerTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const SYNTH_CEILING = 10 * 1024 * 1024;

/**
 * Fakes the two synthetic Workers: creates return a stable id per title,
 * lists return what was created, the bundle ceiling is enforced, and every
 * request is recorded so tests can assert which Worker saw what.
 */
function fakeSyntheticWorkers(): object
{
    $state = new stdClass;
    $state->artifacts = ['8801' => [], '8802' => []];
    $state->limits = [];
    Process::fake();
    Http::fake(function (Request $request) use ($state) {
        $port = (string) parse_url($request->url(), PHP_URL_PORT);
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && $path === '/v1/artifacts') {
            $total = array_sum(array_column($request['manifest']['files'], 'size_bytes'));
            if ($total > SYNTH_CEILING) {
                return Http::response(['error' => ['code' => 'bundle_too_large']], 413);
            }
            $id = substr(hash('sha256', $port.$request['title'].$request->body()), 0, 32);
            $state->artifacts[$port][$id] = ['id' => $id, 'created_at' => '2026-01-01T00:00:00Z', 'revoked_at' => null, 'title' => $request['title']];

            return Http::response(['id' => $id, 'missing_files' => []], 201);
        }
        if ($request->method() === 'GET' && str_ends_with($path, '/artifacts')) {
            return Http::response(['artifacts' => array_values($state->artifacts[$port]), 'next_cursor' => null]);
        }
        if ($request->method() === 'GET' && str_ends_with($path, '/usage')) {
            return Http::response(['storage_bytes' => 1_000_000, 'artifacts_this_period' => 60]);
        }
        if ($request->method() === 'POST' && $path === '/v1/internal/org-limits') {
            $state->limits[$request['org']] = $request->data();

            return Http::response(['org' => $request['org']]);
        }
        if ($request->method() === 'DELETE' && str_contains($path, '/governance/artifacts/') && ! str_ends_with($path, '/legal-hold')) {
            unset($state->artifacts[$port][basename($path)]);

            return Http::response('', 204);
        }

        return Http::response('', 204);
    });

    return $state;
}

beforeEach(function () {
    File::deleteDirectory(storage_path('app/synthetic'));
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/synthetic'));
});

test('corpus_is_deterministic_and_covers_required_shapes', function () {
    $a = SyntheticCorpus::generate(1, 60, SYNTH_CEILING);
    $b = SyntheticCorpus::generate(1, 60, SYNTH_CEILING);
    $other = SyntheticCorpus::generate(2, 60, SYNTH_CEILING);

    expect($a)->toBe($b)->and(array_column($a, 'key'))->toBe(array_column($other, 'key'));

    $deployable = array_filter($a, fn ($spec) => $spec['key'] !== 'bundle-just-over');
    expect($deployable)->toHaveCount(60);

    $jsOnly = array_filter($a, function ($spec) {
        $html = SyntheticFile::content($spec['files'][0]);

        return in_array($spec['kind'], ['react', 'vue'], true) && trim(strip_tags(preg_replace('#<(script|title)\b.*?</\1>#s', '', $html))) === '';
    });
    expect(count($jsOnly))->toBeGreaterThanOrEqual(3);

    $sizes = fn (string $key) => array_sum(array_column(collect($a)->firstWhere('key', $key)['files'], 'size'));
    expect($sizes('bundle-just-under'))->toBeLessThan(SYNTH_CEILING)
        ->and($sizes('bundle-just-over'))->toBeGreaterThan(SYNTH_CEILING);

    $hash = fn (string $key) => hash('sha256', SyntheticFile::content(collect(collect($a)->firstWhere('key', $key)['files'])->firstWhere('path', 'vendor/shared.js')));
    expect($hash('dup-a'))->toBe($hash('dup-b'));

    expect(collect($a)->pluck('provenance.agent')->unique())->toHaveCount(3)
        ->and(collect($a)->pluck('provenance.repo_url')->filter()->unique())->toHaveCount(5)
        ->and(collect($a)->whereNotNull('state')->pluck('state')->sort()->values()->all())->toBe(['expired', 'legal_hold', 'revoked']);
});

test('seed_creates_org_users_roles_and_tokens', function () {
    fakeSyntheticWorkers();

    test()->artisan('synthetic:seed')->assertSuccessful();

    $team = Team::query()->where('slug', 'zz-northwind')->firstOrFail();
    expect(Team::query()->where('slug', 'zz-northwind-b')->exists())->toBeTrue()
        ->and($team->memberships()->count())->toBe(7)
        ->and($team->memberships()->pluck('role')->map->value->unique()->sort()->values()->all())->toBe(['admin', 'member', 'viewer'])
        ->and(User::query()->whereNotNull('deactivated_at')->count())->toBe(1)
        ->and($team->invitations()->count())->toBe(2)
        ->and($team->domains()->whereNotNull('verified_at')->count())->toBe(1)
        ->and(OrgToken::query()->where('team_id', $team->id)->whereNull('revoked_at')->count())->toBe(3)
        ->and(User::query()->where('email', 'not like', '%@northwind.example')->count())->toBe(0);
});

test('seed_is_idempotent', function () {
    $workers = fakeSyntheticWorkers();

    test()->artisan('synthetic:seed')->assertSuccessful();
    $counts = fn () => [
        User::query()->count(), Team::query()->count(), OrgToken::query()->count(), AuditEvent::query()->count(),
        Collection::query()->count(), ArtifactUsageEvent::query()->count(), count($workers->artifacts['8801']), count($workers->artifacts['8802']),
    ];
    $first = $counts();
    $map = File::get(SyntheticSeeder::mapPath('zz-northwind'));

    test()->artisan('synthetic:seed')->assertSuccessful();

    expect($counts())->toBe($first)->and(File::get(SyntheticSeeder::mapPath('zz-northwind')))->toBe($map)
        ->and($first[6])->toBe(60)->and($first[7])->toBe(8);
});

test('seed_refuses_in_production', function () {
    fakeSyntheticWorkers();
    app()->detectEnvironment(fn () => 'production');

    test()->artisan('synthetic:seed')->assertFailed();

    expect(Team::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('seed_refuses_slug_without_zz_prefix', function () {
    fakeSyntheticWorkers();

    test()->artisan('synthetic:seed', ['--slug' => 'northwind'])->assertFailed();

    expect(Team::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('purge_removes_only_synthetic_orgs', function () {
    $workers = fakeSyntheticWorkers();
    $real = Team::factory()->create(['slug' => 'acme']);
    $realUser = User::factory()->create(['email' => 'person@acme.example']);
    test()->artisan('synthetic:seed')->assertSuccessful();

    test()->artisan('synthetic:seed', ['--purge' => true])->assertSuccessful();

    expect(Team::query()->pluck('slug')->all())->toBe(['acme'])
        ->and(User::query()->where('email', 'like', '%@northwind.example')->count())->toBe(0)
        ->and(User::query()->whereKey($realUser->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('team_id', $real->id)->count())->toBe(0)
        ->and($workers->artifacts['8801'])->toBe([])->and($workers->artifacts['8802'])->toBe([])
        ->and(File::exists(SyntheticSeeder::mapPath('zz-northwind')))->toBeFalse();
});

test('seeded_audit_events_come_from_real_actions', function () {
    fakeSyntheticWorkers();
    test()->artisan('synthetic:seed')->assertSuccessful();
    $team = Team::query()->where('slug', 'zz-northwind')->firstOrFail();

    $types = AuditEvent::query()->where('team_id', $team->id)->pluck('event_type')->map->value->unique()->sort()->values()->all();

    // Written through AuditLogger (the path controllers use), never inserted raw.
    expect($types)->toBe(collect([AuditEventType::MemberAdded, AuditEventType::RoleChanged, AuditEventType::TokenCreated, AuditEventType::TokenRevoked])->map->value->sort()->values()->all())
        ->and(OrgToken::query()->where('team_id', $team->id)->whereNotNull('revoked_at')->count())->toBe(1);
});

test('org_b_cannot_see_org_a_artifacts', function () {
    $workers = fakeSyntheticWorkers();
    test()->artisan('synthetic:seed')->assertSuccessful();

    $a = json_decode(File::get(SyntheticSeeder::mapPath('zz-northwind')), true);
    $b = json_decode(File::get(SyntheticSeeder::mapPath('zz-northwind-b')), true);

    // Each org's artifacts live only on its own Worker, and B's overlapping titles never appear on A.
    expect(array_intersect($a, $b))->toBe([])
        ->and(array_diff(array_keys($workers->artifacts['8802']), array_values($b)))->toBe([])
        ->and(collect($workers->artifacts['8801'])->pluck('title')->filter(fn ($t) => str_contains($t, 'Contoso')))->toBeEmpty();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), ':8802') && $request->hasHeader('Authorization', 'Bearer synthetic-b-token'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), ':8802') && $request->hasHeader('Authorization', 'Bearer synthetic-a-token'));
});

test('scenario_over_quota_refuses_create_and_keeps_serving', function () {
    $workers = fakeSyntheticWorkers();
    Team::factory()->create(['slug' => 'zz-northwind']);

    test()->artisan('synthetic:scenario', ['org' => 'zz-northwind', 'scenario' => 'over-quota'])->assertSuccessful();

    // Limit at or below current usage so the Worker refuses new creates; reads are untouched
    // (the gate is never on the read path — spec 14).
    expect($workers->limits['zz-northwind']['storage_bytes'])->toBeLessThanOrEqual(1_000_000)
        ->and($workers->limits['zz-northwind']['read_only'])->toBeFalse();
});

test('scenario_past_due_refuses_create', function () {
    $workers = fakeSyntheticWorkers();
    $team = Team::factory()->create(['slug' => 'zz-northwind']);

    test()->artisan('synthetic:scenario', ['org' => 'zz-northwind', 'scenario' => 'past-due'])->assertSuccessful();

    expect($workers->limits['zz-northwind']['read_only'])->toBeTrue()
        ->and($team->fresh()->payment_status)->toBe(PaymentStatus::PastDue);
});

test('scenario_healthy_restores_create', function () {
    $workers = fakeSyntheticWorkers();
    $team = Team::factory()->create(['slug' => 'zz-northwind', 'payment_status' => PaymentStatus::PastDue]);

    test()->artisan('synthetic:scenario', ['org' => 'zz-northwind', 'scenario' => 'healthy'])->assertSuccessful();

    expect($workers->limits['zz-northwind']['read_only'])->toBeFalse()
        ->and($workers->limits['zz-northwind']['storage_bytes'])->toBeGreaterThan(1_000_000)
        ->and($team->fresh()->payment_status)->toBe(PaymentStatus::Active);
});

test('scenario_refuses_non_synthetic_org', function () {
    fakeSyntheticWorkers();
    Team::factory()->create(['slug' => 'acme']);

    test()->artisan('synthetic:scenario', ['org' => 'acme', 'scenario' => 'past-due'])->assertFailed();

    Http::assertNothingSent();
});

test('golden_queries_reference_existing_artifacts', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/synthetic/queries.json')), true);
    $keys = array_column(SyntheticCorpus::generate(1, 60, SYNTH_CEILING), 'key');

    expect(count($fixture['queries']))->toBeGreaterThanOrEqual(20);
    foreach ($fixture['queries'] as $entry) {
        expect($entry['expected_keys'])->not->toBeEmpty();
        foreach ($entry['expected_keys'] as $key) {
            expect($keys)->toContain($key);
        }
    }
});

test('factories_produce_valid_collection_audit_and_usage_rows', function () {
    $collection = Collection::factory()->create();
    $audit = AuditEvent::factory()->create();
    $usage = ArtifactUsageEvent::factory()->create();
    $entry = ArtifactIndexEntry::factory()->create();

    expect($collection->exists)->toBeTrue()->and($collection->canonical)->toBeFalse()
        ->and($audit->event_type)->toBeInstanceOf(AuditEventType::class)
        ->and($usage->artifact_id)->toHaveLength(32)
        ->and($entry->rendered)->toBeFalse();
});

test('worker_target_requires_configuration', function () {
    config(['synthetic.targets.staging.a.url' => null]);

    expect(fn () => WorkerTarget::for('staging', 'a', 'zz-northwind'))->toThrow(RuntimeException::class);
});
