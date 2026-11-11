<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/scripts/worktree-bootstrap.php';

/**
 * @return array<string, false>
 */
function worktreeBootstrapGitEnvironment(): array
{
    // Git exports GIT_DIR (and friends) to hooks. A fixture that inherited it
    // would run every `git -C` against the checkout under test instead.
    return [
        'GIT_DIR' => false,
        'GIT_WORK_TREE' => false,
        'GIT_COMMON_DIR' => false,
        'GIT_INDEX_FILE' => false,
        'GIT_OBJECT_DIRECTORY' => false,
        'GIT_ALTERNATE_OBJECT_DIRECTORIES' => false,
    ];
}

/**
 * @param  array<int, string>  $command
 */
function worktreeBootstrapProcess(array $command): Process
{
    return new Process($command, env: worktreeBootstrapGitEnvironment());
}

function worktreeBootstrapRemove(string $path): void
{
    worktreeBootstrapProcess(['rm', '-rf', $path])->run();
}

function worktreeBootstrapGit(string $cwd, string ...$args): void
{
    worktreeBootstrapProcess(['git', '-C', $cwd, ...$args])->mustRun();
}

/**
 * @return array{base: string, main: string, worktree: string, marker: string, process: Process}
 */
function worktreeBootstrapFixture(bool $withInstall = true): array
{
    $base = sys_get_temp_dir().'/artfct-bootstrap-'.bin2hex(random_bytes(6));

    mkdir($base, 0777, true);

    $base = realpath($base);
    $repo = realpath(dirname(__DIR__, 2));

    // Never let a fixture land in the checkout under test.
    if ($base === false || ! str_starts_with($base.'/', realpath(sys_get_temp_dir()).'/') || str_starts_with($base.'/', $repo.'/')) {
        throw new RuntimeException("Fixture must live outside the repository: {$base}");
    }

    $main = $base.'/main';
    $worktree = $base.'/worktree';
    $marker = bin2hex(random_bytes(8));

    mkdir($main, 0777, true);
    file_put_contents($main.'/composer.json', "{}\n");

    worktreeBootstrapProcess(['git', 'init', '--initial-branch=main', $main])->mustRun();

    $gitDir = trim(worktreeBootstrapProcess(['git', '-C', $main, 'rev-parse', '--absolute-git-dir'])->mustRun()->getOutput());

    if (realpath($gitDir) !== realpath($main.'/.git')) {
        throw new RuntimeException("Fixture git repository escaped: {$gitDir}");
    }

    worktreeBootstrapGit($main, 'config', 'user.email', 'agent@example.test');
    worktreeBootstrapGit($main, 'config', 'user.name', 'Agent');
    worktreeBootstrapGit($main, 'commit', '--allow-empty', '-m', 'init');
    worktreeBootstrapGit($main, 'worktree', 'add', $worktree, '-b', 'probe');

    if ($withInstall) {
        mkdir($main.'/vendor/laravel/boost', 0777, true);
        mkdir($main.'/vendor/composer', 0777, true);
        mkdir($main.'/vendor/bin', 0777, true);
        file_put_contents($main.'/vendor/autoload.php', "<?php return '{$marker}';\n");
        file_put_contents($main.'/vendor/pest-plugins.json', '[]');
        file_put_contents($main.'/vendor/laravel/boost/file.txt', 'shared');
        file_put_contents($main.'/vendor/composer/autoload_real.php', '<?php');
        file_put_contents($main.'/vendor/bin/pest', '#!/usr/bin/env php');
    }

    clearstatcache(true);

    return [
        'base' => $base,
        'main' => $main,
        'worktree' => $worktree,
        'marker' => $marker,
        'process' => worktreeBootstrapProcess(['git', '-C', $main, 'worktree', 'remove', '--force', $worktree]),
    ];
}

it('shares the main checkout install with a worktree', function (): void {
    $fixture = worktreeBootstrapFixture();
    ['base' => $base, 'main' => $main, 'worktree' => $worktree, 'marker' => $marker] = $fixture;

    try {
        expect(artfct_main_checkout($worktree))->toBe(realpath($main));

        $autoload = artfct_worktree_bootstrap($worktree);

        clearstatcache(true);

        expect($autoload)->toBe($worktree.'/vendor/autoload.php')
            ->and(file_get_contents($autoload))->toBe("<?php return '{$marker}';\n")
            ->and(file_get_contents($worktree.'/vendor/composer/autoload_real.php'))->toBe('<?php')
            ->and(fileinode($worktree.'/vendor/laravel/boost/file.txt'))
            ->toBe(fileinode($main.'/vendor/laravel/boost/file.txt'))
            ->and(fileinode($worktree.'/vendor/autoload.php'))
            ->not->toBe(fileinode($main.'/vendor/autoload.php'))
            ->and(fileinode($worktree.'/vendor/pest-plugins.json'))
            ->not->toBe(fileinode($main.'/vendor/pest-plugins.json'))
            ->and(fileinode($worktree.'/vendor/composer/autoload_real.php'))
            ->not->toBe(fileinode($main.'/vendor/composer/autoload_real.php'))
            ->and(fileinode($worktree.'/vendor/bin/pest'))
            ->not->toBe(fileinode($main.'/vendor/bin/pest'));

        // An already-provisioned worktree is a no-op: generated files must not
        // be replaced on every artisan invocation.
        file_put_contents($worktree.'/vendor/composer/autoload_real.php', '<?php // sentinel');

        expect(artfct_worktree_bootstrap($worktree))->toBe($autoload)
            ->and(file_get_contents($worktree.'/vendor/composer/autoload_real.php'))->toBe('<?php // sentinel');
    } finally {
        $fixture['process']->run();
        worktreeBootstrapRemove($base);
    }
});

it('reports when no checkout has an install to share', function (): void {
    $fixture = worktreeBootstrapFixture(withInstall: false);
    ['base' => $base, 'worktree' => $worktree] = $fixture;

    try {
        expect(artfct_worktree_bootstrap($worktree))->toBeNull();
    } finally {
        $fixture['process']->run();
        worktreeBootstrapRemove($base);
    }
});

it('does not use the main checkout as its own install source', function (): void {
    $fixture = worktreeBootstrapFixture(withInstall: false);
    ['base' => $base, 'main' => $main] = $fixture;

    try {
        expect(artfct_main_checkout($main))->toBeNull()
            ->and(artfct_worktree_bootstrap($main))->toBeNull()
            ->and(is_dir($main.'/vendor'))->toBeFalse();
    } finally {
        $fixture['process']->run();
        worktreeBootstrapRemove($base);
    }
});

it('forces a local environment for boost commands only when .env is missing', function (): void {
    $base = sys_get_temp_dir().'/artfct-bootstrap-'.bin2hex(random_bytes(6));
    mkdir($base.'/vendor', 0777, true);
    file_put_contents($base.'/vendor/autoload.php', '<?php');

    $argv = $_SERVER['argv'] ?? null;
    $env = $_ENV['APP_ENV'] ?? null;
    $server = $_SERVER['APP_ENV'] ?? null;
    $putenv = getenv('APP_ENV');

    $forceProduction = function (): void {
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        putenv('APP_ENV=production');
    };

    $assertProduction = function (): void {
        expect($_ENV['APP_ENV'])->toBe('production')
            ->and($_SERVER['APP_ENV'])->toBe('production')
            ->and(getenv('APP_ENV'))->toBe('production');
    };

    try {
        $_SERVER['argv'] = ['artisan', 'boost:mcp'];
        $forceProduction();

        expect(artfct_worktree_bootstrap($base))->toBe($base.'/vendor/autoload.php')
            ->and($_ENV['APP_ENV'])->toBe('local')
            ->and($_SERVER['APP_ENV'])->toBe('local')
            ->and(getenv('APP_ENV'))->toBe('local');

        $_SERVER['argv'] = ['artisan', 'boost:execute-tool', 'ExampleTool', 'e30='];
        $forceProduction();

        artfct_worktree_bootstrap($base);

        expect($_ENV['APP_ENV'])->toBe('local');

        file_put_contents($base.'/.env', "APP_ENV=production\n");
        $forceProduction();

        artfct_worktree_bootstrap($base);

        $assertProduction();

        unlink($base.'/.env');
        $_SERVER['argv'] = ['artisan', 'about'];
        $forceProduction();

        artfct_worktree_bootstrap($base);

        $assertProduction();
    } finally {
        worktreeBootstrapRemove($base);

        if ($argv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $argv;
        }

        restore_env('APP_ENV', $env, $server, $putenv);
    }
});

function restore_env(string $key, ?string $env, ?string $server, string|false $putenv): void
{
    if ($env === null) {
        unset($_ENV[$key]);
    } else {
        $_ENV[$key] = $env;
    }

    if ($server === null) {
        unset($_SERVER[$key]);
    } else {
        $_SERVER[$key] = $server;
    }

    $putenv === false ? putenv($key) : putenv($key.'='.$putenv);
}
