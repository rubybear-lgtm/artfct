<?php

use App\Enums\McpScope;
use App\Enums\OrgTokenKind;
use App\Enums\TeamRole;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('a member can add a bot teammate without email and receive a token once', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    $response = test()->actingAs($member)->postJson(route('teams.bots.store', $team), [
        'name' => 'Hoku',
        'scopes' => [
            McpScope::ArtifactsRead->value,
            McpScope::ArtifactsDeploy->value,
        ],
    ])->assertCreated();

    expect($response->json('name'))->toBe('Hoku')
        ->and($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('authorization_header'))->toStartWith('Bearer ');

    $bot = User::query()->where('name', 'Hoku')->where('is_bot', true)->sole();
    expect($bot->email)->toBeNull()
        ->and($bot->belongsToTeam($team))->toBeTrue()
        ->and(OrgToken::query()->where('user_id', $bot->id)->where('kind', OrgTokenKind::Agent)->exists())->toBeTrue();
});

test('creating a bot without an avatar accepts application/json', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)
        ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
        ->postJson(route('teams.bots.store', $team), [
            'name' => 'JsonBot',
            'scopes' => [McpScope::ArtifactsRead->value],
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'JsonBot');

    expect(User::query()->where('name', 'JsonBot')->where('is_bot', true)->exists())->toBeTrue();
});

test('bots appear on the members page with a bot flag and no email', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $created = test()->actingAs($admin)->postJson(route('teams.bots.store', $team), [
        'name' => 'Hoku',
        'scopes' => [McpScope::ArtifactsRead->value],
    ])->assertCreated();

    $lastFour = substr((string) $created->json('token'), -4);

    test()->actingAs($admin)->get(route('teams.edit', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/edit')
            ->where('canAddBot', true)
            ->has('mcpEndpoint')
            ->has('members')
            ->where('members', function ($members) use ($lastFour) {
                return collect($members)->contains(
                    fn ($member): bool => $member['name'] === 'Hoku'
                        && $member['isBot'] === true
                        && $member['email'] === null
                        && $member['agentTokenLastFour'] === $lastFour,
                );
            }));
});

test('rolling a bot token revokes the old credential and returns a new one once', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create(['slug' => 'roll-bot-team']);
    $member = memberOfTeam($team, TeamRole::Member);
    $created = test()->actingAs($member)->postJson(route('teams.bots.store', $team), [
        'name' => 'Hoku',
        'scopes' => [McpScope::ArtifactsRead->value, McpScope::UsageRead->value],
    ])->assertCreated();

    $oldToken = $created->json('token');
    $bot = User::query()->where('name', 'Hoku')->where('is_bot', true)->sole();
    $oldTokenId = OrgToken::query()->where('user_id', $bot->id)->value('id');

    $rolled = test()->actingAs($member)->postJson(route('teams.bots.roll', [$team, $bot]))
        ->assertOk();

    expect($rolled->json('token'))->toBeString()->not->toBeEmpty()
        ->and($rolled->json('token'))->not->toBe($oldToken)
        ->and($rolled->json('revoked_count'))->toBe(1)
        ->and(OrgToken::query()->whereKey($oldTokenId)->value('revoked_at'))->not->toBeNull();

    $initialize = test()->withToken($rolled->json('token'))->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();

    test()->withToken($rolled->json('token'))
        ->withHeader('MCP-Session-Id', $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_connection', 'arguments' => []],
        ])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.credential_source', 'agent_token');

    test()->withToken($oldToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertUnauthorized();
});

test('tokens page lists bots so the agent tab can explain an empty state', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->get(route('teams.tokens.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/tokens')
            ->where('bots', []));
});

test('bots do not count toward billed seats', function () {
    $team = Team::factory()->create();
    memberOfTeam($team, TeamRole::Admin);
    memberOfTeam($team, TeamRole::Member);
    $bot = User::factory()->bot()->create();
    $team->memberships()->create(['user_id' => $bot->id, 'role' => TeamRole::Member]);

    expect(app(BillingService::class)->activeSeatCount($team))->toBe(2)
        ->and($team->memberships()->count())->toBe(3);
});

test('a human can upload a bot avatar from team settings', function () {
    Storage::fake('public');
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $bot = User::factory()->bot()->create(['name' => 'Hoku']);
    $team->memberships()->create(['user_id' => $bot->id, 'role' => TeamRole::Member]);

    $pngPath = sys_get_temp_dir().'/hoku-avatar-'.uniqid().'.png';
    file_put_contents($pngPath, hex2bin(
        '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c63000100000500010d0a2db40000000049454e44ae426082'
    ));

    test()->actingAs($admin)->post(route('teams.bots.avatar', [$team, $bot]), [
        'avatar' => new UploadedFile($pngPath, 'hoku.png', 'image/png', null, true),
    ])->assertRedirect();

    expect($bot->fresh()->avatar)->toBeString()->toContain('/storage/avatars/bots/');
});

test('set_avatar mcp tool updates the authenticated bot avatar', function () {
    configureOrgJwt();
    Storage::fake('public');
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    $created = test()->actingAs($member)->postJson(route('teams.bots.store', $team), [
        'name' => 'Hoku',
        'scopes' => [McpScope::ArtifactsRead->value],
    ])->assertCreated();

    $png = base64_encode(hex2bin(
        '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c63000100000500010d0a2db40000000049454e44ae426082'
    ));

    $initialize = test()->withToken($created->json('token'))->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();

    $response = test()->withToken($created->json('token'))
        ->withHeader('MCP-Session-Id', $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'set_avatar',
                'arguments' => ['image_base64' => $png],
            ],
        ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse();
    $result = $response->json('result.structuredContent');

    expect($result['bot']['name'])->toBe('Hoku')
        ->and($result['bot']['bot'])->toBeTrue()
        ->and($result['avatar_url'])->toContain('/storage/avatars/bots/');

    $bot = User::query()->where('name', 'Hoku')->where('is_bot', true)->sole();
    expect($bot->avatar)->toBe($result['avatar_url']);
});

test('removing a bot revokes its tokens', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $created = test()->actingAs($admin)->postJson(route('teams.bots.store', $team), [
        'name' => 'Hoku',
        'scopes' => [McpScope::ArtifactsRead->value],
    ])->assertCreated();

    $bot = User::query()->where('name', 'Hoku')->where('is_bot', true)->sole();
    $tokenId = OrgToken::query()->where('user_id', $bot->id)->value('id');

    test()->actingAs($admin)->delete(route('teams.bots.destroy', [$team, $bot]))
        ->assertRedirect(route('teams.edit', $team));

    expect(User::query()->whereKey($bot->id)->exists())->toBeFalse()
        ->and(OrgToken::query()->whereKey($tokenId)->exists())->toBeFalse();

    test()->withToken($created->json('token'))->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ])->assertUnauthorized();
});
