<?php

/**
 * Live local smoke for RUB-511 against the running app DB (not Pest's
 * RefreshDatabase). Mints a throwaway bot, hits /mcp with Bearer auth,
 * set_avatar + get_connection, asserts seat count excludes bots.
 *
 * Usage: php scripts/rub-511-live-mcp-smoke.php
 */

use App\Actions\Teams\CreateBotTeammate;
use App\Enums\McpScope;
use App\Models\Team;
use App\Services\Billing\BillingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$baseUrl = rtrim((string) (getenv('RUB511_BASE_URL') ?: config('app.url')), '/');
if ($baseUrl === '' || $baseUrl === 'http://localhost') {
    // php artisan serve binds ::1 in this environment; prefer localhost over 127.0.0.1.
    $baseUrl = 'http://localhost:8000';
}

$team = Team::query()->where('slug', 'demo-team')->firstOrFail();
$creator = $team->members()->where('users.is_bot', false)->orderBy('users.id')->firstOrFail();

$seatsBefore = app(BillingService::class)->activeSeatCount($team);
$membershipsBefore = $team->memberships()->count();

$botName = 'HokuLive'.substr((string) time(), -4);
$created = app(CreateBotTeammate::class)->handle(
    $team,
    $creator,
    $botName,
    [
        McpScope::ArtifactsRead->value,
        McpScope::ArtifactsDeploy->value,
        McpScope::CollectionsRead->value,
        McpScope::UsageRead->value,
    ],
);

$token = $created['token'];
$bot = $created['bot'];

if (! is_string($token) || $token === '') {
    fwrite(STDERR, "FAIL: CreateBotTeammate did not return a token\n");
    exit(1);
}

$seatsAfter = app(BillingService::class)->activeSeatCount($team);
$membershipsAfter = $team->memberships()->count();

$report = [
    'base_url' => $baseUrl,
    'team' => $team->slug,
    'bot' => [
        'id' => $bot->id,
        'name' => $bot->name,
        'email' => $bot->email,
        'is_bot' => $bot->is_bot,
    ],
    'seats_before' => $seatsBefore,
    'seats_after_add' => $seatsAfter,
    'memberships_before' => $membershipsBefore,
    'memberships_after' => $membershipsAfter,
    'seat_unchanged' => $seatsBefore === $seatsAfter,
    'membership_grew' => $membershipsAfter === $membershipsBefore + 1,
];

if (! $report['seat_unchanged'] || ! $report['membership_grew']) {
    fwrite(STDERR, "FAIL: seat/membership invariant broken\n".json_encode($report, JSON_PRETTY_PRINT)."\n");
    exit(1);
}

$png = base64_encode(hex2bin(
    '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c63000100000500010d0a2db40000000049454e44ae426082'
));

$initialize = Http::withToken($token)
    ->acceptJson()
    ->post($baseUrl.'/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'rub-511-live-smoke', 'version' => '1.0'],
        ],
    ]);

$sessionId = $initialize->header('MCP-Session-Id');
$report['initialize_status'] = $initialize->status();
$report['initialize_ok'] = $initialize->successful();
$report['session_id'] = $sessionId;

if (! $initialize->successful() || ! is_string($sessionId) || $sessionId === '') {
    fwrite(STDERR, "FAIL: MCP initialize\n".json_encode([
        'status' => $initialize->status(),
        'body' => $initialize->json() ?? $initialize->body(),
        'report' => $report,
    ], JSON_PRETTY_PRINT)."\n");
    exit(1);
}

$connection = Http::withToken($token)
    ->withHeaders(['MCP-Session-Id' => $sessionId])
    ->acceptJson()
    ->post($baseUrl.'/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'get_connection', 'arguments' => []],
    ]);

$connectionBody = $connection->json('result.structuredContent') ?? $connection->json();
$report['get_connection_status'] = $connection->status();
$report['get_connection'] = $connectionBody;

$avatar = Http::withToken($token)
    ->withHeaders(['MCP-Session-Id' => $sessionId])
    ->acceptJson()
    ->post($baseUrl.'/mcp', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'set_avatar',
            'arguments' => ['image_base64' => $png],
        ],
    ]);

$avatarBody = $avatar->json('result.structuredContent') ?? $avatar->json();
$report['set_avatar_status'] = $avatar->status();
$report['set_avatar'] = $avatarBody;
$report['bot_avatar_after'] = $bot->fresh()->avatar;

$tools = Http::withToken($token)
    ->withHeaders(['MCP-Session-Id' => $sessionId])
    ->acceptJson()
    ->post($baseUrl.'/mcp', [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/list',
        'params' => [],
    ]);

$toolNames = collect($tools->json('result.tools') ?? [])->pluck('name')->values()->all();
$report['tools_count'] = count($toolNames);
$report['has_set_avatar'] = in_array('set_avatar', $toolNames, true);
$report['has_deploy_artifact'] = in_array('deploy_artifact', $toolNames, true);

$ok = $report['initialize_ok']
    && ($connectionBody['credential_source'] ?? null) === 'agent_token'
    && ($connectionBody['agent']['name'] ?? null) === $botName
    && ($connectionBody['agent']['bot'] ?? null) === true
    && ($connectionBody['user_id'] ?? null) === (string) $bot->id
    && $avatar->successful()
    && ($avatarBody['bot']['bot'] ?? null) === true
    && is_string($report['bot_avatar_after'])
    && str_contains((string) $report['bot_avatar_after'], '/storage/avatars/bots/')
    && $report['has_set_avatar']
    && $report['has_deploy_artifact'];

$report['pass'] = $ok;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

// Keep the bot for GUI inspection (Bot badge + avatar). Do not delete.
exit($ok ? 0 : 1);
