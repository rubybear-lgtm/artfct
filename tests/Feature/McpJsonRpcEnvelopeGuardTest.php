<?php

use App\Models\Team;
use Illuminate\Support\Facades\Http;

test('boolean request ids return a JSON-RPC invalid request instead of a 500', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    Http::fake();

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => true,
        'method' => 'initialize',
        'params' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('error.code', -32600);
    expect($response->json('id'))->toBeNull();
    Http::assertNothingSent();
});

test('non-array params return a JSON-RPC invalid params instead of a 500', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    Http::fake();

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 7,
        'method' => 'initialize',
        'params' => 589023,
    ]);

    $response->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 7)
        ->assertJsonPath('error.code', -32602);
    Http::assertNothingSent();
});

test('a well-formed initialize still succeeds through the envelope guard', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ])->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 1)
        ->assertJsonMissingPath('error');
});

test('tools/call with a non-string name returns invalid params instead of a 500', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    Http::fake();

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => ['x']],
    ])->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('error.code', -32602);
    Http::assertNothingSent();
});

test('tools/call with string arguments returns invalid params instead of a 500', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    Http::fake();

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'get_usage', 'arguments' => 'x'],
    ])->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('error.code', -32602);
    Http::assertNothingSent();
});
