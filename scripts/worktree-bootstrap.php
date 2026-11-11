<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shared Composer install for git worktrees
|--------------------------------------------------------------------------
|
| Every worktree is a separate project root, so `php artisan` there expects its
| own `vendor/` directory. A symlinked vendor cannot work: Composer resolves the
| application root relative to `vendor/composer/`, and PHP resolves symlinks, so
| `App\` would load the main checkout's classes.
|
| Hard links keep a separate path per worktree while sharing the installed
| bytes, so the ~150 MB install is reused instead of copied or reinstalled.
| Composer's generated directories are copied instead of linked, because
| `composer install` rewrites them in place and a shared inode would leak one
| checkout's autoloader into every other checkout.
|
*/

if (! function_exists('artfct_worktree_bootstrap')) {
    /**
     * Ensure the checkout at $root has a Composer install and return its autoloader.
     *
     * Returns null when $root has no install and the main checkout has none to
     * share, so the caller can fail with an actionable message.
     */
    function artfct_worktree_bootstrap(string $root): ?string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $autoload = $root.'/vendor/autoload.php';

        if (! is_file($autoload)) {
            $lock = artfct_vendor_lock($root);

            if (! is_file($autoload)) {
                $main = artfct_main_checkout($root);

                if ($main === null || ! artfct_link_vendor($main.'/vendor', $root.'/vendor')) {
                    artfct_vendor_unlock($lock);

                    return null;
                }
            }

            artfct_vendor_unlock($lock);
        }

        artfct_local_environment($root);

        return $autoload;
    }
}

if (! function_exists('artfct_main_checkout')) {
    /**
     * Resolve the main checkout that owns the shared git directory.
     */
    function artfct_main_checkout(string $root): ?string
    {
        if (! function_exists('exec')) {
            return null;
        }

        $output = [];
        $status = 0;

        exec('git -C '.escapeshellarg($root).' rev-parse --path-format=absolute --git-common-dir 2>/dev/null', $output, $status);

        if ($status !== 0 || ! isset($output[0])) {
            return null;
        }

        $main = dirname(rtrim(trim($output[0]), '/'));

        return is_file($main.'/composer.json') ? $main : null;
    }
}

if (! function_exists('artfct_link_vendor')) {
    /**
     * Hard-link an install into $target, falling back to a real copy off-volume.
     */
    function artfct_link_vendor(string $source, string $target): bool
    {
        if (! is_file($source.'/autoload.php')) {
            return false;
        }

        if (is_dir($target) && ! is_file($target.'/autoload.php')) {
            artfct_run('rm -rf '.escapeshellarg($target));
        }

        if (! is_dir($target)) {
            $status = artfct_run('cp -al '.escapeshellarg($source).' '.escapeshellarg($target));

            if ($status !== 0) {
                artfct_run('rm -rf '.escapeshellarg($target));
                $status = artfct_run('cp -a '.escapeshellarg($source).' '.escapeshellarg($target));
            }

            if ($status !== 0 || ! is_file($target.'/autoload.php')) {
                artfct_run('rm -rf '.escapeshellarg($target));

                return false;
            }
        }

        foreach (['composer', 'bin'] as $generated) {
            artfct_copy_directory($target.'/'.$generated);
        }

        return true;
    }
}

if (! function_exists('artfct_copy_directory')) {
    /**
     * Replace a directory of hard links with independent copies.
     */
    function artfct_copy_directory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }

        $copy = $path.'.copy';

        artfct_run('rm -rf '.escapeshellarg($copy));
        artfct_run('cp -a '.escapeshellarg($path).' '.escapeshellarg($copy));
        artfct_run('rm -rf '.escapeshellarg($path));

        rename($copy, $path);
    }
}

if (! function_exists('artfct_local_environment')) {
    /**
     * Laravel Boost registers its commands only when the app is local or
     * debugging. A fresh worktree has no .env, so without this `boost:mcp`
     * exits before it can speak MCP and clients report "Connection closed".
     * Scoped to the MCP command: no other command gets a different APP_ENV.
     */
    function artfct_local_environment(string $root): void
    {
        $argv = $_SERVER['argv'] ?? [];

        if (is_file($root.'/.env') || ! in_array('boost:mcp', $argv, true)) {
            return;
        }

        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';
        putenv('APP_ENV=local');
    }
}

if (! function_exists('artfct_vendor_lock')) {
    /**
     * Serialise provisioning when several MCP clients start at once.
     *
     * @return resource|null
     */
    function artfct_vendor_lock(string $root)
    {
        $handle = @fopen(sys_get_temp_dir().'/artfct-vendor-'.md5($root).'.lock', 'c');

        if ($handle !== false) {
            flock($handle, LOCK_EX);
        }

        return $handle === false ? null : $handle;
    }
}

if (! function_exists('artfct_vendor_unlock')) {
    /**
     * @param  resource|null  $handle
     */
    function artfct_vendor_unlock($handle): void
    {
        if ($handle === null) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

if (! function_exists('artfct_run')) {
    function artfct_run(string $command): int
    {
        if (! function_exists('exec')) {
            return 1;
        }

        $output = [];
        $status = 0;

        exec($command.' 2>/dev/null', $output, $status);

        return $status;
    }
}
