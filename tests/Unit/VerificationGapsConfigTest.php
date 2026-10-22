<?php

$repoRoot = dirname(__DIR__, 2);

test('phpunit_pgsql_targets_the_e2e_postgres_port', function () use ($repoRoot) {
    $xml = file_get_contents($repoRoot.'/phpunit.pgsql.xml');

    expect($xml)->toContain('name="DB_PORT" value="55432"')
        ->and($xml)->not->toContain('name="DB_PORT" value="5433"');
});

test('mcp_e2e_skip_list_still_runs_for_guidance_and_runbook_markdown', function () use ($repoRoot) {
    $workflow = file_get_contents($repoRoot.'/.github/workflows/ci.yml');

    expect($workflow)->toContain('resources/guidance/')
        ->and($workflow)->toContain('docs/mcp-runbook\.md')
        ->and($workflow)->toContain('mcp_md=');
});

test('pre_push_builds_production_ssr_before_lint_and_types', function () use ($repoRoot) {
    $hook = file_get_contents($repoRoot.'/.githooks/pre-push');
    $buildAt = strpos($hook, 'APP_ENV=production npm run build:ssr');
    $lintAt = strpos($hook, 'npm run lint:check');
    $typesAt = strpos($hook, 'npm run types:check');

    expect($buildAt)->not->toBeFalse()
        ->and($lintAt)->not->toBeFalse()
        ->and($typesAt)->not->toBeFalse()
        ->and($buildAt)->toBeLessThan($lintAt)
        ->and($buildAt)->toBeLessThan($typesAt)
        ->and($hook)->toContain('composer analyse')
        ->and($hook)->toContain('npm run routes:check')
        ->and($hook)->toContain('npm run test:skills');
});

test('pre_commit_treats_routes_controllers_and_skill_scripts_as_frontend', function () use ($repoRoot) {
    $hook = file_get_contents($repoRoot.'/.githooks/pre-commit');

    expect($hook)->toContain('routes/*.php')
        ->and($hook)->toContain('app/Http/Controllers/*')
        ->and($hook)->toContain('skills/*.mjs');
});

test('storage_integration_fails_closed_when_member_tokens_are_missing', function () use ($repoRoot) {
    $source = file_get_contents($repoRoot.'/backend/tests/storage_integration.rs');

    expect($source)->toContain('fn require_context()')
        ->and($source)->toContain('fn require_member_pair(')
        ->and($source)->toContain('fn require_sharing_actors(')
        ->and($source)->not->toContain('Every test skips with `Ok(())` when its optional token is absent');
});
