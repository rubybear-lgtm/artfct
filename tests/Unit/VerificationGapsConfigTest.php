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
    $order = file_get_contents($repoRoot.'/scripts/test-wayfinder-hook-order.sh');
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
        ->and($hook)->toContain('npm run test:skills')
        ->and($order)->toContain('assert_before .githooks/pre-push')
        ->and($order)->toContain('APP_ENV=production npm run build:ssr');
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

test('ci_runs_the_hydration_browser_tests_against_the_inertia_ssr_daemon', function () use ($repoRoot) {
    $workflow = file_get_contents($repoRoot.'/.github/workflows/ci.yml');

    expect($workflow)->toContain('pest_browser_ssr')
        ->and($workflow)->toContain('npm run build:ssr')
        ->and($workflow)->toContain('php artisan inertia:start-ssr')
        ->and($workflow)->toContain('php artisan inertia:check-ssr')
        ->and($workflow)->toContain('TEST_INERTIA_SSR')
        ->and($workflow)->toContain('--testsuite=Browser');
});

test('ssr_hydration_tests_fail_closed_when_the_daemon_is_missing', function () use ($repoRoot) {
    $testCase = file_get_contents($repoRoot.'/tests/TestCase.php');

    expect($testCase)->toContain("getenv('TEST_INERTIA_SSR') !== 'true'")
        ->and($testCase)->toContain('inertia.ssr.throw_on_error');
});
