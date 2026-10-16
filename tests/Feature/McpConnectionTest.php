<?php

use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

test('creates an auditable connection with a public identifier and typed metadata', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    $connection = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'scopes' => ['artifacts:read'],
        'metadata' => ['editor' => 'cursor'],
    ]);

    expect($connection->public_id)
        ->toBeString()
        ->not->toBeEmpty()
        ->and($connection->scopes)->toBe(['artifacts:read'])
        ->and($connection->metadata)->toBe(['editor' => 'cursor'])
        ->and($connection->team->is($team))->toBeTrue()
        ->and($connection->user->is($user))->toBeTrue();
});

test('connection records do not have a raw credential column', function () {
    expect(Schema::hasColumn('mcp_connections', 'token'))->toBeFalse()
        ->and(Schema::hasColumn('mcp_connections', 'access_token'))->toBeFalse()
        ->and(Schema::hasColumn('mcp_connections', 'refresh_token'))->toBeFalse();
});

test('active connections exclude revoked and expired records', function () {
    $active = McpConnection::factory()->create();
    $revoked = McpConnection::factory()->revoked()->create();
    $expired = McpConnection::factory()->expired()->create();

    expect(McpConnection::query()->active()->pluck('id')->all())
        ->toBe([$active->id])
        ->and($active->isRevoked())->toBeFalse()
        ->and($active->isExpired())->toBeFalse()
        ->and($revoked->isRevoked())->toBeTrue()
        ->and($expired->isExpired())->toBeTrue();
});

test('touching usage updates the last used timestamp without changing lifecycle state', function () {
    $connection = McpConnection::factory()->create([
        'last_used_at' => null,
    ]);

    Carbon::setTestNow('2026-09-20 12:00:00');

    $connection->touchUsage();

    expect($connection->fresh()->last_used_at?->toDateTimeString())
        ->toBe('2026-09-20 12:00:00')
        ->and($connection->fresh()->revoked_at)->toBeNull();
});
