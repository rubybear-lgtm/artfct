<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/scripts/worktree-bootstrap.php';

function worktreeBootstrapRemove(string $path): void
{
    (new Process(['rm', '-rf', $path]))->run();
}

function worktreeBootstrapGit(string $cwd, string ...$args): void
{
    (new Process(['git', '-C', $cwd, ...$args]))->mustRun();
}

/**
 * @return array{base: string, main: string, worktree: string, process: Process}
 */
function worktreeBootstrapFixture(bool $withInstall = true): array
{
    $base = sys_get_temp_dir().'/artfct-bootstrap-'.bin2hex(random_bytes(6));
    $main = $base.'/main';
    $worktree = $base.'/worktree';

    mkdir($main, 0777, true);
    file_put_contents($main.'/composer.json', "{}\n");

    worktreeBootstrapGit($main, 'init', '--initial-branch=main');
    worktreeBootstrapGit($main, 'config', 'user.email', 'agent@example.test');
    worktreeBootstrapGit($main, 'config', 'user.name', 'Agent');
    worktreeBootstrapGit($main, 'commit', '--allow-empty', '-m', 'init');
    worktreeBootstrapGit($main, 'worktree', 'add', $worktree, '-b', 'probe');

    if ($withInstall) {
        mkdir($main.'/vendor/laravel/boost', 0777, true);
        mkdir($main.'/vendor/composer', 0777, true);
        mkdir($main.'/vendor/bin', 0777, true);
        file_put_contents($main.'/vendor/autoload.php', "<?php return 'shared';\n");
        file_put_contents($main.'/vendor/laravel/boost/file.txt', 'shared');
        file_put_contents($main.'/vendor/composer/autoload_real.php', '<?php');
        file_put_contents($main.'/vendor/bin/pest', '#!/usr/bin/env php');
    }

    clearstatcache(true);

    return [
        'base' => $base,
        'main' => $main,
        'worktree' => $worktree,
        'process' => new Process(['git', '-C', $main, 'worktree', 'remove', '--force', $worktree]),
    ];
}

it('shares the main checkout install with a worktree', function (): void {
    ['base' => $base, 'main' => $main, 'worktree' => $worktree] = $fixture = worktreeBootstrapFixture();

    try {
        $autoload = artfct_worktree_bootstrap($worktree);

        clearstatcache(true);

        expect($autoload)->toBe($worktree.'/vendor/autoload.php')
            ->and(file_get_contents($autoload))->toBe("<?php return 'shared';\n")
            ->and(fileinode($worktree.'/vendor/laravel/boost/file.txt'))
            ->toBe(fileinode($main.'/vendor/laravel/boost/file.txt'))
            ->and(fileinode($worktree.'/vendor/composer/autoload_real.php'))
            ->not->toBe(fileinode($main.'/vendor/composer/autoload_real.php'))
            ->and(fileinode($worktree.'/vendor/bin/pest'))
            ->not->toBe(fileinode($main.'/vendor/bin/pest'));

        expect(artfct_worktree_bootstrap($worktree))->toBe($autoload);
    } finally {
        $fixture['process']->run();
        worktreeBootstrapRemove($base);
    }
});

it('reports when no checkout has an install to share', function (): void {
    ['base' => $base, 'worktree' => $worktree] = $fixture = worktreeBootstrapFixture(withInstall: false);

    try {
        expect(artfct_worktree_bootstrap($worktree))->toBeNull();
    } finally {
        $fixture['process']->run();
        worktreeBootstrapRemove($base);
    }
});

it('forces a local environment for boost:mcp only when .env is missing', function (): void {
    $base = sys_get_temp_dir().'/artfct-bootstrap-'.bin2hex(random_bytes(6));
    mkdir($base.'/vendor', 0777, true);
    file_put_contents($base.'/vendor/autoload.php', '<?php');

    $argv = $_SERVER['argv'] ?? null;
    $env = $_ENV['APP_ENV'] ?? null;
    $server = $_SERVER['APP_ENV'] ?? null;
    $putenv = getenv('APP_ENV');

    try {
        $_SERVER['argv'] = ['artisan', 'boost:mcp'];
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        putenv('APP_ENV=production');

        expect(artfct_worktree_bootstrap($base))->toBe($base.'/vendor/autoload.php')
            ->and($_ENV['APP_ENV'])->toBe('local')
            ->and($_SERVER['APP_ENV'])->toBe('local')
            ->and(getenv('APP_ENV'))->toBe('local');

        file_put_contents($base.'/.env', "APP_ENV=production\n");
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        putenv('APP_ENV=production');

        artfct_worktree_bootstrap($base);

        expect($_ENV['APP_ENV'])->toBe('production');

        unlink($base.'/.env');
        $_SERVER['argv'] = ['artisan', 'about'];
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        putenv('APP_ENV=production');

        artfct_worktree_bootstrap($base);

        expect($_ENV['APP_ENV'])->toBe('production');
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
