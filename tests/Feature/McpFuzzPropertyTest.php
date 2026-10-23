<?php

use App\Mcp\Servers\ArtfctServer;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * Seeded generated-input coverage for hosted MCP JSON-RPC framing and tool
 * arguments (RUB-382). Replaces the CLI crate's proptest properties deleted with
 * RUB-432; no new Composer dependency — a deterministic xorshift64* generator
 * keeps the case count locked and CI-cheap.
 */
const MCP_FUZZ_ENVELOPE_CASES = 256;

const MCP_FUZZ_TOOL_ARG_CASES = 64;

/** Hard ceiling so a runaway response fails the property instead of hanging CI. */
const MCP_FUZZ_RESPONSE_BYTE_BOUND = 262_144;

/**
 * Seed mt_rand for a deterministic case. Callers treat the process RNG as
 * owned for the duration of one generated value tree.
 */
function mcpFuzzSeed(int $seed): void
{
    mt_srand($seed === 0 ? 1 : $seed);
}

function mcpFuzzInt(int $min, int $max): int
{
    if ($max < $min) {
        return $min;
    }

    return mt_rand($min, $max);
}

function mcpFuzzBool(): bool
{
    return mt_rand(0, 1) === 1;
}

function mcpFuzzString(int $maxLength = 64): string
{
    $length = mcpFuzzInt(0, $maxLength);
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789 ._-/';
    $alphabetLength = strlen($alphabet);
    $out = '';

    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[mcpFuzzInt(0, $alphabetLength - 1)];
    }

    return $out;
}

/**
 * Bounded recursive JSON value, mirroring the retired proptest leaf/recursive
 * strategy (depth ≤ 3, ≤ 4 children per container).
 */
function mcpFuzzJsonValue(int $depth = 0): mixed
{
    $roll = mcpFuzzInt(0, $depth >= 3 ? 3 : 5);

    if ($roll === 0) {
        return null;
    }

    if ($roll === 1) {
        return mcpFuzzBool();
    }

    if ($roll === 2) {
        return mcpFuzzInt(-1_000_000, 1_000_000);
    }

    if ($roll === 3) {
        return mcpFuzzString();
    }

    if ($roll === 4) {
        $items = [];
        $count = mcpFuzzInt(0, 4);

        for ($i = 0; $i < $count; $i++) {
            $items[] = mcpFuzzJsonValue($depth + 1);
        }

        return $items;
    }

    $object = [];
    $count = mcpFuzzInt(0, 4);

    for ($i = 0; $i < $count; $i++) {
        $object[mcpFuzzString(6)] = mcpFuzzJsonValue($depth + 1);
    }

    return $object;
}

/**
 * Non-null JSON-RPC id (requests without an id are notifications and may omit a body).
 */
function mcpFuzzRequestId(): mixed
{
    do {
        $id = mcpFuzzJsonValue();
    } while ($id === null);

    return $id;
}

function mcpFuzzMethod(): string
{
    return match (mcpFuzzInt(0, 3)) {
        0 => 'tools/list',
        1 => 'initialize',
        2 => 'unknown/fuzz-method',
        default => mcpFuzzString(24),
    };
}

/**
 * @return list<array{name: string, schema: array<string, mixed>}>
 */
function mcpFuzzHostedToolSchemas(): array
{
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();

    return $server->createContext()->tools()
        ->map(fn ($tool): array => [
            'name' => $tool->name(),
            'schema' => $tool->toArray()['inputSchema'],
        ])
        ->values()
        ->all();
}

/**
 * @param  array<string, mixed>  $schema
 * @return array{name: string, type: string, nullable: bool}|null
 */
function mcpFuzzFirstTypedField(array $schema): ?array
{
    $properties = $schema['properties'] ?? [];

    if (! is_array($properties) || $properties === []) {
        return null;
    }

    $required = $schema['required'] ?? [];
    $candidates = is_array($required) && $required !== []
        ? $required
        : array_keys($properties);

    foreach ($candidates as $field) {
        if (! is_string($field) || ! isset($properties[$field]) || ! is_array($properties[$field])) {
            continue;
        }

        $rawType = $properties[$field]['type'] ?? null;
        $nullable = false;
        $type = $rawType;

        if (is_array($rawType)) {
            $nullable = in_array('null', $rawType, true);
            $type = collect($rawType)->first(fn ($entry): bool => is_string($entry) && $entry !== 'null');
        }

        if (! is_string($type) || $type === '') {
            continue;
        }

        return ['name' => $field, 'type' => $type, 'nullable' => $nullable];
    }

    return null;
}

function mcpFuzzValueMatchesType(mixed $value, string $type, bool $nullable): bool
{
    if ($value === null) {
        return $nullable;
    }

    return match ($type) {
        'string' => is_string($value),
        'integer' => is_int($value),
        'number' => is_int($value) || is_float($value),
        'boolean' => is_bool($value),
        'array' => is_array($value) && array_is_list($value),
        'object' => is_array($value) && ! array_is_list($value),
        default => true,
    };
}

/**
 * @return array<string, mixed>
 */
function mcpFuzzOutOfSchemaArguments(array $schema): array
{
    $field = mcpFuzzFirstTypedField($schema);

    if ($field === null) {
        return ['unexpected' => mcpFuzzJsonValue()];
    }

    do {
        $junk = mcpFuzzJsonValue();
    } while (mcpFuzzValueMatchesType($junk, $field['type'], $field['nullable']));

    return [$field['name'] => $junk];
}

/**
 * @param  array<string, mixed>|null  $decoded
 */
function mcpFuzzAssertWellFormedJsonRpc(int $status, string $body, ?array $decoded): void
{
    expect($status)->toBeLessThan(500)
        ->and(strlen($body))->toBeLessThanOrEqual(MCP_FUZZ_RESPONSE_BYTE_BOUND);

    if ($decoded === null) {
        return;
    }

    // Empty notification-style bodies and non-object JSON are allowed as long as
    // they stay under the status and size bounds; object bodies must be JSON-RPC.
    if ($decoded === [] || array_is_list($decoded)) {
        return;
    }

    expect($decoded['jsonrpc'] ?? null)->toBe('2.0');
    expect(array_key_exists('result', $decoded) || array_key_exists('error', $decoded))->toBeTrue();
}

test('arbitrary JSON-RPC envelopes against /mcp never 500 and stay well-formed when JSON', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();

    $failures = [];

    for ($case = 0; $case < MCP_FUZZ_ENVELOPE_CASES; $case++) {
        mcpFuzzSeed(0xC0FFEE00 ^ ($case * 0x9E3779B9));
        $request = [
            'jsonrpc' => mcpFuzzJsonValue(),
            'id' => mcpFuzzRequestId(),
            'method' => mcpFuzzMethod(),
            'params' => mcpFuzzJsonValue(),
        ];

        // postJson (not call) so withToken's Authorization header is applied;
        // MakesHttpRequests::call with only CONTENT_TYPE/ACCEPT drops it and
        // the suite would only ever see unauthenticated 401 bodies.
        $response = $this->withToken($token)->postJson('/mcp', $request);

        $body = $response->getContent();
        $decoded = json_decode($body, true);

        try {
            mcpFuzzAssertWellFormedJsonRpc(
                $response->getStatusCode(),
                $body,
                is_array($decoded) ? $decoded : null,
            );
        } catch (Throwable $exception) {
            $failures[] = 'case '.$case.': '.$exception->getMessage().' request='.json_encode($request);
        }
    }

    expect($failures)->toBe([]);
    Http::assertNothingSent();
});

test('out-of-schema tool arguments are rejected for every hosted tool without a Worker call', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    $tools = mcpFuzzHostedToolSchemas();

    expect($tools)->toHaveCount(15);

    $failures = [];

    foreach ($tools as $toolIndex => $tool) {
        for ($case = 0; $case < MCP_FUZZ_TOOL_ARG_CASES; $case++) {
            Http::fake();
            mcpFuzzSeed(0xBEEF0000 ^ (($toolIndex + 1) * 0x10000) ^ ($case * 0x9E3779B9));
            $arguments = mcpFuzzOutOfSchemaArguments($tool['schema']);
            $typed = mcpFuzzFirstTypedField($tool['schema']);

            $response = $this->withToken($token)->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => $tool['name'],
                    'arguments' => $arguments,
                ],
            ]);

            $body = $response->getContent();
            $decoded = json_decode($body, true);

            try {
                mcpFuzzAssertWellFormedJsonRpc(
                    $response->getStatusCode(),
                    $body,
                    is_array($decoded) ? $decoded : null,
                );

                Http::assertNothingSent();

                // Tools that declare a typed field must refuse the wrong type
                // before running. Empty-schema tools (get_connection, get_usage)
                // may ignore unknown keys; those only get the no-crash / no-HTTP
                // half of the property.
                if ($typed !== null && is_array($decoded) && ! array_is_list($decoded)) {
                    $isToolError = ($decoded['result']['isError'] ?? false) === true;
                    $isRpcError = array_key_exists('error', $decoded);

                    if (! $isToolError && ! $isRpcError) {
                        throw new RuntimeException(
                            $tool['name'].' accepted out-of-schema arguments '.json_encode($arguments),
                        );
                    }
                }
            } catch (Throwable $exception) {
                $failures[] = $tool['name'].' case '.$case.': '.$exception->getMessage()
                    .' arguments='.json_encode($arguments);
            }
        }
    }

    expect($failures)->toBe([]);
});

test('shrunk counterexample: search_artifacts rejects a non-string query before any Worker call', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'search_artifacts',
            'arguments' => ['query' => ['nested' => true]],
        ],
    ]);

    expect($response->getStatusCode())->toBeLessThan(500);
    $payload = $response->json();
    expect($payload['jsonrpc'] ?? null)->toBe('2.0');
    expect(($payload['result']['isError'] ?? false) === true || array_key_exists('error', $payload))->toBeTrue();
    Http::assertNothingSent();
});

test('shrunk counterexample: numeric method never causes a server error', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 7,
        'params' => [],
    ]);

    expect($response->getStatusCode())->toBeLessThan(500)
        ->and(strlen($response->getContent()))->toBeLessThanOrEqual(MCP_FUZZ_RESPONSE_BYTE_BOUND);
});
