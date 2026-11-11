<?php

use App\Enums\McpScope;
use App\Enums\OrgTokenKind;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\BillingService;
use App\Services\Billing\PlanGate;
use App\Services\Billing\PlanGateException;

/**
 * RUB-529: Free has no bot access; Pro/Team/trial/Business do. Human user
 * OAuth stays on Free. Downgrade suspends bots (calls fail); upgrade restores.
 */
test('free_workspace_cannot_create_a_bot', function () {
    configureOrgJwt();
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->postJson(route('teams.bots.store', $team), [
        'name' => 'BlockedBot',
        'scopes' => [McpScope::ArtifactsRead->value],
    ])
        ->assertForbidden()
        ->assertJsonPath('errorCode', 'plan_required')
        ->assertJsonPath('nextAction', 'upgrade_plan');

    expect(User::query()->where('name', 'BlockedBot')->where('is_bot', true)->exists())->toBeFalse();
});

test('free_workspace_rejects_bot_token_mcp_calls_with_plan_required', function () {
    configureOrgJwt();
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-suspend']);
    $member = memberOfTeam($team, TeamRole::Member);

    $created = test()->actingAs($member)->postJson(route('teams.bots.store', $team), [
        'name' => 'Hoku',
        'scopes' => [McpScope::ArtifactsRead->value],
    ])->assertCreated();

    $token = $created->json('token');
    expect($token)->toBeString();

    // Downgrade to Free without deleting the bot or token rows.
    $team->forceFill(['plan' => Plan::Free])->save();
    expect(User::query()->where('name', 'Hoku')->where('is_bot', true)->exists())->toBeTrue()
        ->and(OrgToken::query()->where('kind', OrgTokenKind::Agent)->whereNull('revoked_at')->exists())->toBeTrue();

    test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])
        ->assertForbidden()
        ->assertJsonPath('error.data.artfct.errorCode', 'plan_required');
});

test('free_user_human_oauth_mcp_still_works', function () {
    configureOrgJwt();
    $team = Team::factory()->create(['plan' => Plan::Free, 'slug' => 'human-oauth-free']);
    $human = memberOfTeam($team, TeamRole::Admin);
    $minted = OrgJwtService::default()->mint($team, $human, TeamRole::Admin);
    OrgToken::query()->create([
        'team_id' => $team->id,
        'user_id' => $human->id,
        'name' => 'MCP OAuth (test-client)',
        'kind' => OrgTokenKind::Api,
        'jti' => $minted['jti'],
        'role' => TeamRole::Admin,
        'scopes' => [McpScope::ArtifactsRead->value],
        'last_four' => 'abcd',
        'expires_at' => $minted['expires_at'],
    ]);

    test()->withToken($minted['token'])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'cursor', 'version' => '1'],
        ],
    ])->assertOk();
});

test('pro_and_team_can_create_and_use_bots', function (Plan $plan) {
    configureOrgJwt();
    $team = Team::factory()->create(['plan' => $plan, 'slug' => 'bots-'.$plan->value]);
    $member = memberOfTeam($team, TeamRole::Member);

    $created = test()->actingAs($member)->postJson(route('teams.bots.store', $team), [
        'name' => 'ProTeamBot',
        'scopes' => [McpScope::ArtifactsRead->value],
    ])->assertCreated();

    expect(PlanGate::hasBotAccess($team))->toBeTrue();

    test()->withToken($created->json('token'))->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();
})->with([
    Plan::Pro,
    Plan::Team,
]);

test('downgrade_suspends_bots_and_upgrade_restores_them', function () {
    configureOrgJwt();
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'restore-bots']);
    $member = memberOfTeam($team, TeamRole::Member);

    $created = test()->actingAs($member)->postJson(route('teams.bots.store', $team), [
        'name' => 'KeepMe',
        'scopes' => [McpScope::ArtifactsRead->value],
    ])->assertCreated();
    $token = $created->json('token');
    $botId = User::query()->where('name', 'KeepMe')->where('is_bot', true)->value('id');
    $tokenId = OrgToken::query()->where('user_id', $botId)->value('id');

    app(BillingService::class)->applySubscriptionEnded($team);
    expect($team->fresh()->plan)->toBe(Plan::Free)
        ->and(User::query()->whereKey($botId)->exists())->toBeTrue()
        ->and(OrgToken::query()->whereKey($tokenId)->value('revoked_at'))->toBeNull();

    test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])
        ->assertForbidden()
        ->assertJsonPath('error.data.artfct.errorCode', 'plan_required');

    $team->forceFill(['plan' => Plan::Pro])->save();
    expect(PlanGate::hasBotAccess($team->fresh()))->toBeTrue();

    test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();
});

test('free_plan_require_bot_access_throws_upgrade_message', function () {
    $team = Team::factory()->create(['plan' => Plan::Free]);

    expect(fn () => PlanGate::requireBotAccess($team))
        ->toThrow(PlanGateException::class, 'Upgrade');
});

test('pricing_copy_lists_ai_bot_teammates_on_pro_and_team_not_free', function () {
    $landing = file_get_contents(resource_path('js/pages/landing.tsx'));

    expect($landing)->toContain('AI bot teammates')
        ->and($landing)->toMatch('/Pro[\s\S]*AI bot teammates/')
        ->and($landing)->toMatch('/Team[\s\S]*AI bot teammates/');

    // Free block must not advertise bot teammates.
    preg_match('/<div className="nm">Free<\/div>[\s\S]*?<div className="nm">Pro<\/div>/', $landing, $freeBlock);
    expect($freeBlock[0] ?? '')->not->toContain('AI bot teammates');
});
