<?php

use App\Models\McpActivity;
use Illuminate\Support\Carbon;

test('prunes activity older than the configured retention window', function () {
    Carbon::setTestNow('2026-09-20 12:00:00');

    $old = McpActivity::factory()->create(['created_at' => now()->subDays(91)]);
    $recent = McpActivity::factory()->create(['created_at' => now()->subDays(89)]);

    test()->artisan('mcp:prune-activity')
        ->expectsOutputToContain('Pruned 1 MCP activity record(s) older than 90 days.')
        ->assertSuccessful();

    expect(McpActivity::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(McpActivity::query()->whereKey($recent->id)->exists())->toBeTrue();
});

test('prune activity rejects unsafe retention windows', function () {
    test()->artisan('mcp:prune-activity', ['--days' => 0])
        ->expectsOutputToContain('between 1 and 3650 days')
        ->assertFailed();
});
