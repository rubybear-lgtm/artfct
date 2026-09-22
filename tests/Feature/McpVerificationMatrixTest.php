<?php

/**
 * Guards the MCP verification matrix defined in
 * tests/Fixtures/mcp-verification-matrix.php.
 *
 * RUB-363 asked for a verification gate rather than a prose document, so the
 * matrix is asserted here instead of trusted: every capability the issue names
 * must be present, every covered one must point at a test that actually exists
 * in the file it names, and every gap must point at a filed issue with a
 * reason. Renaming or deleting a mapped test fails this suite rather than
 * quietly leaving a capability uncovered -- which is the failure mode the issue
 * was written in response to.
 */

/** @return array<string, array{surface: string, evidence?: array{kind: string, file: string, name: string}, gap?: array{issue: string, reason: string}}> */
function mcpVerificationMatrix(): array
{
    return require base_path('tests/Fixtures/mcp-verification-matrix.php');
}

/**
 * The capabilities RUB-363 names, in the issue's own order. Kept explicit so
 * that dropping one from the matrix is a visible failure rather than a silent
 * reduction in coverage.
 *
 * @return list<string>
 */
function mcpVerificationCapabilities(): array
{
    return [
        // Scope: transports.
        'transport.stdio',
        'transport.streamable_http',
        // Scope: protocol operations.
        'protocol.initialize',
        'protocol.initialize_tolerates_empty_params',
        'protocol.version_negotiation',
        'protocol.tool_discovery',
        'protocol.tool_call',
        'protocol.errors',
        'protocol.cancellation',
        'protocol.retries',
        'protocol.reconnection',
        // Scope: OAuth.
        'oauth.discovery',
        'oauth.pkce',
        'oauth.consent',
        'oauth.refresh',
        'oauth.revocation',
        'oauth.scope_enforcement',
        'oauth.redirect_uri_hardening',
        // Scope: tenancy, in units and live.
        'tenancy.isolation_units',
        'tenancy.isolation_live',
        // Scope: policy, quota, degraded states.
        'policy.rate_limits',
        'policy.quota',
        'policy.degraded_service',
        // Scope: client setup paths.
        'clients.agent_discovery',
        'clients.config_writers',
        'clients.live_compatibility',
        // Scope: dashboard onboarding.
        'ui.connection_onboarding',
        // Scope: robustness and secrets.
        'robustness.malformed_input',
        'robustness.fuzz_property',
        'robustness.secret_persistence',
        'robustness.secret_redaction',
        // Scope: load.
        'load.concurrent_sessions',
    ];
}

test('the matrix covers exactly the capabilities the issue names', function () {
    $declared = array_keys(mcpVerificationMatrix());
    $required = mcpVerificationCapabilities();

    expect(array_values(array_diff($required, $declared)))->toBe([], 'capabilities missing from the matrix')
        ->and(array_values(array_diff($declared, $required)))->toBe([], 'capabilities in the matrix that the issue does not name');
});

test('the matrix declares no duplicate capability keys', function () {
    // A duplicate key in the PHP literal is overwritten silently, which would
    // leave the list looking complete while one entry quietly vanished.
    $source = (string) file_get_contents(base_path('tests/Fixtures/mcp-verification-matrix.php'));
    preg_match_all("/^ {4}'([a-z_]+\.[a-z_]+)' =>/m", $source, $matches);

    $keys = $matches[1];

    expect($keys)->not->toBe([], 'the capability-key pattern matched nothing, so this guard is inert')
        ->and(count($keys))->toBe(count(array_unique($keys)), 'the matrix declares a duplicate capability key');
});

test('every capability is either covered or filed, never both and never neither', function () {
    $problems = [];

    foreach (mcpVerificationMatrix() as $capability => $entry) {
        $hasEvidence = array_key_exists('evidence', $entry);
        $hasGap = array_key_exists('gap', $entry);

        if ($hasEvidence === $hasGap) {
            $problems[] = $capability.' declares evidence='.var_export($hasEvidence, true).' gap='.var_export($hasGap, true);
        }

        if (! is_string($entry['surface'] ?? null) || ! in_array($entry['surface'], ['transport', 'protocol', 'oauth', 'tenancy', 'policy', 'clients', 'ui', 'robustness', 'load'], true)) {
            $problems[] = $capability.' declares unknown surface '.var_export($entry['surface'] ?? null, true);
        }
    }

    expect($problems)->toBe([]);
});

test('every covered capability points at a test that exists', function () {
    $problems = [];

    foreach (mcpVerificationMatrix() as $capability => $entry) {
        if (! array_key_exists('evidence', $entry)) {
            continue;
        }

        $evidence = $entry['evidence'];
        $path = base_path($evidence['file']);

        if (! file_exists($path)) {
            $problems[] = $capability.' -> '.$evidence['file'].' does not exist';

            continue;
        }

        $source = (string) file_get_contents($path);
        $name = $evidence['name'];

        $found = match ($evidence['kind']) {
            'php' => str_contains($source, "test('{$name}'") || str_contains($source, "it('{$name}'"),
            'rust' => str_contains($source, "fn {$name}("),
            'marker' => str_contains($source, $name),
            default => false,
        };

        if (! $found) {
            $problems[] = $capability.' -> '.$evidence['kind']." evidence '{$name}' is not present in ".$evidence['file'];
        }
    }

    expect($problems)->toBe([]);
});

test('every gap names a filed issue and a reason why it is not automated', function () {
    $problems = [];

    foreach (mcpVerificationMatrix() as $capability => $entry) {
        if (! array_key_exists('gap', $entry)) {
            continue;
        }

        $gap = $entry['gap'];

        if (! is_string($gap['issue'] ?? null) || preg_match('/^RUB-\d+$/', $gap['issue']) !== 1) {
            $problems[] = $capability.' must name the issue it was filed as, got '.var_export($gap['issue'] ?? null, true);
        }

        if (strlen(trim((string) ($gap['reason'] ?? ''))) <= 40) {
            $problems[] = $capability.' must say why the capability is not automated, in more than a phrase';
        }
    }

    expect($problems)->toBe([]);
});

test('the matrix itself carries no credential-shaped strings', function () {
    // The DoD clause requiring no credential in fixtures. This file is the one
    // artefact RUB-363 adds, so it is checked like any other.
    $source = (string) file_get_contents(base_path('tests/Fixtures/mcp-verification-matrix.php'));

    expect($source)->not->toMatch('/\bsk_[A-Za-z0-9]{8,}/', 'an API-key-shaped literal is present')
        ->not->toMatch('/\bBearer\s+[A-Za-z0-9._-]{20,}/', 'a bearer token is present')
        ->not->toMatch('/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/', 'a JWT-shaped literal is present');
});
