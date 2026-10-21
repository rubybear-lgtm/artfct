<?php

/**
 * Read a Worker config's `build.command`. The configs are JSONC, so this
 * matches the line rather than decoding the file.
 */
function workerBuildCommand(string $config): string
{
    $source = file_get_contents(dirname(__DIR__, 2).'/backend/'.$config);

    expect(preg_match('/^\s*"command": (".*"),\s*$/m', $source, $matches))->toBe(1);

    return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
}

test('every_worker_config_builds_with_the_same_self_installing_command', function (string $config) {
    // Staging deploys with `--config wrangler.staging.jsonc`, so fixing only
    // wrangler.jsonc left staging on a command that needs a toolchain the
    // Workers Builds image does not have (`cargo: not found`).
    expect(workerBuildCommand($config))->toBe(workerBuildCommand('wrangler.jsonc'));
})->with(['wrangler.staging.jsonc', 'wrangler.staging-b.jsonc']);

test('the_worker_build_command_installs_rust_only_when_cargo_is_missing_and_pins_worker_build', function () {
    $command = workerBuildCommand('wrangler.jsonc');

    expect($command)
        ->toContain('command -v cargo')
        ->toContain('sh.rustup.rs')
        ->toContain('worker-build --version 0.8.3 --locked');
});
