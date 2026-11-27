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
                'object' => is_array($value) && ! array_is_list($value),
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

        if ($mcpName === 'deploy_artifact') {
            unset($schema['properties']['collection'], $schema['properties']['db']);
            $schema['properties']['files']['minItems'] = 1;

            expect($oneOf)->not->toBeNull()
                ->and($declared['properties'])->not->toHaveKey('collection')
                ->and($declared['properties'])->not->toHaveKey('db');
        } else {
            expect($oneOf)->toBeNull();
        }

        expect($declared)->toEqual($schema)
            ->and($tool['readOnly'])->toBe($mcp['annotations']['readOnlyHint'])
            ->and($tool['idempotent'])->toBe($mcp['annotations']['idempotentHint'])
            ->and($tool['scopes'])->toBe($mcp['_meta']['artfct']['requiredScopes'])
            ->and($tool['description'])->not->toBeEmpty();
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

test('the search date filters validate like the server date rule', function () {
    $search = composioToolInputSchema('artfct_search_artifacts');

    expect($search['properties']['since'])->not->toHaveKey('format')
        ->and($search['properties']['changed_since'])->not->toHaveKey('format')
        ->and(composioSchemaError($search, ['query' => 'launch', 'since' => '2026-11-01']))->toBeNull()
        ->and(composioSchemaError($search, ['query' => 'launch', 'since' => '2026-11-01T09:30:00Z']))->toBeNull()
        ->and(composioSchemaError($search, ['query' => 'launch', 'since' => null]))->toBeNull()
        ->and(composioSchemaError($search, []))->not->toBeNull();
});
