<?php

use App\Mcp\Servers\ArtfctServer;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * Minimal JSON Schema 2020-12 instance check for the keywords the Composio
 * toolkit uses. Keeps this contract test free of a new dependency; the real
 * dialect check is the hosted MCP tool schema it is compared against below.
 */
/**
 * @param  array<mixed, mixed>  $schema
 */
function composioSchemaError(array $schema, mixed $value, string $path = '$'): ?string
{
    if (isset($schema['type'])) {
        $matched = false;
        foreach ((array) $schema['type'] as $type) {
            $matched = $matched || match ($type) {
                // PHP's [] is both an empty list and an empty object; treat it as
                // an object when the schema allows one, so {} reaches required,
                // additionalProperties and oneOf instead of failing the type.
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && ($value === [] || array_is_list($value)),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => true,
            };
        }

        if (! $matched) {
            return "{$path}: expected ".implode('|', (array) $schema['type']);
        }
    }

    if ($value === null) {
        return null;
    }

    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        return "{$path}: not in enum";
    }

    if (is_string($value)) {
        if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
            return "{$path}: shorter than minLength";
        }
        if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            return "{$path}: longer than maxLength";
        }
        if (isset($schema['pattern']) && preg_match('/'.$schema['pattern'].'/u', $value) !== 1) {
            return "{$path}: does not match pattern";
        }
    }

    if (is_int($value) || is_float($value)) {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            return "{$path}: below minimum";
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            return "{$path}: above maximum";
        }
    }

    if (is_array($value)) {
        if ($value === [] || ! array_is_list($value)) {
            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    return "{$path}: missing required {$required}";
                }
            }

            foreach ($schema['properties'] ?? [] as $name => $property) {
                if (array_key_exists($name, $value)) {
                    $error = composioSchemaError($property, $value[$name], "{$path}.{$name}");
                    if ($error !== null) {
                        return $error;
                    }
                }
            }

            if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] === false) {
                $extra = array_diff(array_keys($value), array_keys($schema['properties'] ?? []));
                if ($extra !== [] && ! array_is_list($value)) {
                    return "{$path}: unexpected ".implode(',', $extra);
                }
            }
        }

        if (array_is_list($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                return "{$path}: fewer than minItems";
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                return "{$path}: more than maxItems";
            }

            foreach ($value as $index => $item) {
                $error = composioSchemaError($schema['items'] ?? [], $item, "{$path}[{$index}]");
                if ($error !== null) {
                    return $error;
                }
            }
        }
    }

    if (isset($schema['oneOf'])) {
        $passing = 0;
        foreach ($schema['oneOf'] as $branch) {
            if (composioSchemaError($branch, $value, $path) === null) {
                $passing++;
            }
        }

        if ($passing !== 1) {
            return "{$path}: matched {$passing} oneOf branches";
        }
    }

    if (isset($schema['not']) && composioSchemaError($schema['not'], $value, $path) === null) {
        return "{$path}: matched a forbidden shape";
    }

    return null;
}

function composioWithoutDescriptions(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $stripped = [];
    foreach ($value as $key => $child) {
        if ($key === 'description') {
            continue;
        }
        $stripped[$key] = composioWithoutDescriptions($child);
    }

    return $stripped;
}

/**
 * @return array<mixed, mixed>
 */
function composioToolkit(): array
{
    $data = json_decode(
        (string) file_get_contents(base_path('resources/composio/toolkit.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (! is_array($data)) {
        throw new RuntimeException('resources/composio/toolkit.json must be a JSON object.');
    }

    return $data;
}

/**
 * @return array<mixed, mixed>
 */
function composioToolInputSchema(string $name): array
{
    foreach (composioToolkit()['tools'] as $candidate) {
        if (! is_array($candidate) || ($candidate['name'] ?? null) !== $name) {
            continue;
        }

        $schema = $candidate['inputSchema'] ?? null;

        if (is_array($schema)) {
            return $schema;
        }
    }

    throw new RuntimeException("No input schema for {$name} in toolkit.json.");
}

/** @return array<string, array<string, mixed>> */
function composioHostedTools(): array
{
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();

    return $server->createContext()->tools()
        ->mapWithKeys(fn ($tool): array => [$tool->name() => $tool->toArray()])
        ->all();
}

test('the Composio toolkit mirrors the hosted MCP tool contract', function () {
    $toolkit = composioToolkit();
    $hosted = composioHostedTools();

    expect($toolkit['name'])->toBe('artfct')
        ->and($toolkit['spec'])->toBe('2020-12')
        ->and($toolkit['scopes'])->toBe(['artifacts:deploy', 'artifacts:read', 'comments:write']);

    $expected = [
        'artfct_deploy_artifact' => 'deploy_artifact',
        'artfct_search_artifacts' => 'search_artifacts',
        'artfct_get_artifact' => 'get_artifact',
        'artfct_read_artifact' => 'read_artifact',
        'artfct_list_artifact_comments' => 'list_artifact_comments',
        'artfct_add_artifact_comment' => 'add_artifact_comment',
        'artfct_get_connection' => 'get_connection',
    ];

    expect(array_column($toolkit['tools'], 'name'))->toBe(array_keys($expected));

    foreach ($toolkit['tools'] as $tool) {
        $mcpName = $expected[$tool['name']];
        $mcp = $hosted[$mcpName];

        // Normalise stdClass empty objects (get_connection's properties) to arrays.
        $schema = json_decode((string) json_encode($mcp['inputSchema']), true);
        $declared = $tool['inputSchema'];
        $oneOf = $declared['oneOf'] ?? null;
        unset($declared['oneOf']);

        // Every tool input is closed; undeclared arguments never reach the server.
        expect($declared['additionalProperties'])->toBeFalse();
        $schema['additionalProperties'] = false;

        if ($mcpName === 'deploy_artifact') {
            unset($schema['properties']['collection'], $schema['properties']['db']);
            $schema['properties']['files']['minItems'] = 1;

            expect($oneOf)->not->toBeNull()
                ->and($declared['properties'])->not->toHaveKey('collection')
                ->and($declared['properties'])->not->toHaveKey('db')
                ->and($declared['properties']['artifact_id']['description'])->not->toContain('collection')
                ->and($declared['properties']['artifact_id']['description'])->not->toContain('db');
        } else {
            expect($oneOf)->toBeNull();
        }

        // Descriptions are curated; structure, types, enums and limits must match.
        expect(composioWithoutDescriptions($declared))->toEqual(composioWithoutDescriptions($schema))
            ->and($tool['readOnly'])->toBe($mcp['annotations']['readOnlyHint'])
            ->and($tool['idempotent'])->toBe($mcp['annotations']['idempotentHint'])
            ->and($tool['scopes'])->toBe($mcp['_meta']['artfct']['requiredScopes'])
            ->and($tool['description'])->not->toBeEmpty();

        foreach ($declared['properties'] as $name => $property) {
            expect($property['description'] ?? '')->not->toBeEmpty("{$tool['name']}.{$name} has no description");
        }
    }
});

test('the toolkit never requires a scope the connection does not request', function () {
    $toolkit = composioToolkit();

    $required = [];
    foreach ($toolkit['tools'] as $tool) {
        if (! is_array($tool) || ! is_array($tool['scopes'] ?? null)) {
            continue;
        }
        foreach ($tool['scopes'] as $scope) {
            if (is_string($scope)) {
                $required[$scope] = true;
            }
        }
    }
    $required = array_keys($required);

    expect(array_diff($required, $toolkit['scopes']))->toBe([])
        ->and(array_diff($toolkit['scopes'], $required))->toBe([]);

    $names = array_column($toolkit['tools'], 'name');
    expect($names)->not->toContain('artfct_list_collections', 'artfct_list_templates');
});

test('the deploy schema rejects fields outside v1', function () {
    $deploy = composioToolInputSchema('artfct_deploy_artifact');
    $html = ['html' => '<p>x</p>'];

    expect(composioSchemaError($deploy, $html))->toBeNull()
        ->and(composioSchemaError($deploy, $html + ['collection' => 'Roadmap']))->not->toBeNull()
        ->and(composioSchemaError($deploy, $html + ['db' => ['collections' => ['cards' => []]]]))->not->toBeNull()
        ->and(composioSchemaError($deploy, $html + ['unknown_argument' => 'x']))->not->toBeNull();
});

test('the deploy schema rejects what the server rejects and accepts explicit nulls', function () {
    $deploy = composioToolInputSchema('artfct_deploy_artifact');

    $file = ['path' => 'index.html', 'content' => '<p>x</p>'];

    expect(composioSchemaError($deploy, ['html' => '<p>x</p>']))->toBeNull()
        ->and(composioSchemaError($deploy, ['html' => '<p>x</p>', 'files' => null]))->toBeNull()
        ->and(composioSchemaError($deploy, ['files' => [$file]]))->toBeNull()
        ->and(composioSchemaError($deploy, ['html' => null, 'files' => [$file]]))->toBeNull()
        ->and(composioSchemaError($deploy, ['files' => []]))->not->toBeNull()
        ->and(composioSchemaError($deploy, []))->not->toBeNull()
        ->and(composioSchemaError($deploy, ['html' => '<p>x</p>', 'files' => [$file]]))->not->toBeNull()
        ->and(composioSchemaError($deploy, ['html' => null, 'files' => null]))->not->toBeNull();
});

test('blank html is treated as absent when files is given', function () {
    $deploy = composioToolInputSchema('artfct_deploy_artifact');
    $file = ['path' => 'index.html', 'content' => '<p>x</p>'];

    expect(composioSchemaError($deploy, ['html' => '', 'files' => [$file]]))->toBeNull()
        ->and(composioSchemaError($deploy, ['html' => '   ', 'files' => [$file]]))->toBeNull()
        ->and(composioSchemaError($deploy, ['html' => '']))->not->toBeNull()
        ->and(composioSchemaError($deploy, ['html' => '   ']))->not->toBeNull()
        ->and(composioSchemaError($deploy, ['html' => '', 'files' => []]))->not->toBeNull();
});

test('the validator treats an empty object as an object', function () {
    expect(composioSchemaError(composioToolInputSchema('artfct_get_connection'), []))->toBeNull()
        ->and(composioSchemaError(composioToolInputSchema('artfct_get_connection'), ['x' => 1]))->not->toBeNull()
        ->and(composioSchemaError(composioToolInputSchema('artfct_deploy_artifact'), []))->toContain('oneOf')
        ->and(composioSchemaError(composioToolInputSchema('artfct_search_artifacts'), []))->toContain('missing required query');
});

test('the toolkit docs distinguish daily quota details from other quota errors', function () {
    $doc = preg_replace('/\s+/', ' ', (string) file_get_contents(base_path('docs/composio-toolkit.md')));

    expect($doc)->toContain('Only the daily publish cap returns `reset_at`, `limit`, and `scope`.')
        ->toContain('Past-due payment, storage or artifact-count quota, and the Worker quota refusal return only `code`, `retryable`, and `next_action`.')
        ->toContain('For those three cases, the error message text is the only signal.')
        ->toContain('The `next_action: get_usage` names a tool outside this v1 toolkit, so an executor must not call it.');
});

test('search date filters and bounds validate like the server rules', function () {
    $search = composioToolInputSchema('artfct_search_artifacts');

    expect($search['properties']['since'])->not->toHaveKey('format')
        ->and($search['properties']['changed_since'])->not->toHaveKey('format')
        ->and(composioSchemaError($search, ['query' => 'launch', 'since' => '2026-11-01']))->toBeNull()
        ->and(composioSchemaError($search, ['query' => 'launch', 'since' => '2026-11-01T09:30:00Z']))->toBeNull()
        ->and(composioSchemaError($search, ['query' => 'launch', 'since' => null]))->toBeNull()
        ->and(composioSchemaError($search, []))->not->toBeNull()
        ->and(composioSchemaError($search, ['query' => 'q', 'limit' => 0]))->not->toBeNull()
        ->and(composioSchemaError($search, ['query' => 'q', 'limit' => 51]))->not->toBeNull()
        ->and(composioSchemaError($search, ['query' => 'q', 'limit' => 1]))->toBeNull()
        ->and(composioSchemaError($search, ['query' => 'q', 'limit' => 50]))->toBeNull()
        ->and(composioSchemaError($search, ['query' => 'q', 'limit' => null]))->toBeNull()
        ->and(composioSchemaError($search, ['query' => '']))->not->toBeNull()
        ->and(composioSchemaError($search, ['query' => str_repeat('a', 501)]))->not->toBeNull()
        ->and(composioSchemaError($search, ['query' => str_repeat('a', 500)]))->toBeNull();
});
