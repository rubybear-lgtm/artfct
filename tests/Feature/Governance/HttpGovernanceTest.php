<?php

use App\Models\Team;
use App\Services\Governance\ArtifactGovernanceContract;
use App\Services\Governance\ArtifactUnderLegalHoldException;
use App\Services\Governance\FakeArtifactGovernance;
use App\Services\Governance\HttpArtifactGovernance;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.worker.base_url' => 'https://worker.test',
        'services.worker.governance_secret' => 'gov-secret',
    ]);
});

test('http_governance_lists_and_follows_cursor', function () {
    Http::fake([
        'worker.test/*' => Http::sequence()
            ->push(['artifacts' => [['id' => 'a1', 'created_at' => '2025-01-01T00:00:00Z', 'legal_hold' => false]], 'next_cursor' => 'c1'])
            ->push(['artifacts' => [['id' => 'a2', 'created_at' => '2025-01-02T00:00:00Z', 'legal_hold' => true]], 'next_cursor' => null]),
    ]);

    $artifacts = (new HttpArtifactGovernance)->listArtifactsOlderThan('acme', '2026-01-01T00:00:00Z');

    expect(array_column($artifacts, 'id'))->toBe(['a1', 'a2'])
        ->and($artifacts[1]['legal_hold'])->toBeTrue();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer gov-secret')
        && str_contains($request->url(), '/v1/internal/orgs/acme/governance/artifacts')
        && str_contains($request->url(), 'older_than='));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'cursor=c1'));
});

test('http_governance_hard_delete_maps_409_to_legal_hold_exception', function () {
    Http::fake(['worker.test/*' => Http::response(['error' => ['code' => 'forbidden']], 409)]);

    expect(fn () => (new HttpArtifactGovernance)->hardDeleteArtifact('acme', 'art-held'))
        ->toThrow(ArtifactUnderLegalHoldException::class);
});

test('http_governance_fails_closed_without_secret', function () {
    config(['services.worker.governance_secret' => null]);
    Http::fake();

    expect(fn () => (new HttpArtifactGovernance)->listAllArtifacts('acme'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('retention_apply_aborts_when_worker_reports_hold_placed_after_listing', function () {
    Team::factory()->create(['slug' => 'acme', 'retention_days' => 30]);
    Http::fake([
        'worker.test/*artifacts?*' => Http::response(['artifacts' => [['id' => 'raced', 'created_at' => '2020-01-01T00:00:00Z', 'legal_hold' => false]], 'next_cursor' => null]),
        'worker.test/*artifacts/raced' => Http::response(['error' => ['code' => 'forbidden']], 409),
    ]);
    app()->instance(ArtifactGovernanceContract::class, new HttpArtifactGovernance);

    test()->artisan('governance:retention', ['org' => 'acme', '--apply' => true])
        ->expectsOutputToContain('under legal hold')
        ->assertFailed();
});

test('erase_apply_refuses_wholly_when_any_artifact_is_held', function () {
    Team::factory()->create(['slug' => 'acme']);
    $fake = new FakeArtifactGovernance;
    $fake->seedArtifact('acme', ['id' => 'held', 'created_at' => '2020-01-01T00:00:00Z']);
    $fake->seedArtifact('acme', ['id' => 'free', 'created_at' => '2020-01-01T00:00:00Z']);
    $fake->placeLegalHold('acme', 'held');
    app()->instance(ArtifactGovernanceContract::class, $fake);

    test()->artisan('governance:erase', ['org' => 'acme', '--apply' => true])->assertFailed();

    expect(array_column($fake->listAllArtifacts('acme'), 'id'))->toEqualCanonicalizing(['held', 'free']);
});

test('worker_unreachable_aborts_without_partial_delete', function () {
    Team::factory()->create(['slug' => 'acme', 'retention_days' => 30]);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    app()->instance(ArtifactGovernanceContract::class, new HttpArtifactGovernance);

    test()->artisan('governance:retention', ['org' => 'acme', '--apply' => true])
        ->expectsOutputToContain('Aborted')
        ->assertFailed();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});
