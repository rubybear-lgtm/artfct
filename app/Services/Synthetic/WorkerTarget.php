<?php

namespace App\Services\Synthetic;

use RuntimeException;

/**
 * One Worker instance a synthetic org deploys to: URL, org token and the
 * secrets for the internal routes, plus how `wrangler d1 execute` reaches
 * its database (seed-only backdating).
 */
final readonly class WorkerTarget
{
    public function __construct(
        public string $orgSlug,
        public string $url,
        public string $token,
        public ?string $governanceSecret,
        public ?string $limitsSecret,
        public ?string $persistTo,
        public bool $remote,
        public ?string $wranglerConfig = null,
    ) {}

    /**
     * @param  'a'|'b'  $which
     */
    public static function for(string $target, string $which, string $orgSlug): self
    {
        $config = config("synthetic.targets.{$target}.{$which}");

        if (! is_array($config) || empty($config['url']) || empty($config['token'])) {
            throw new RuntimeException("No Worker configured for target [{$target}] org [{$which}]; see config/synthetic.php.");
        }

        return new self(
            $orgSlug,
            rtrim($config['url'], '/'),
            $config['token'],
            $config['governance_secret'] ?? null,
            $config['limits_secret'] ?? null,
            $config['persist_to'] ?? null,
            (bool) ($config['remote'] ?? false),
            $config['wrangler_config'] ?? null,
        );
    }
}
