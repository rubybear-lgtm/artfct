<?php

namespace App\Console\Commands;

use App\Services\Auth\OrgJwtService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * RUB-343: publishes the org-token public key to the Worker
 * (`POST /v1/internal/jwks`, own shared secret), so Laravel needs no
 * Cloudflare API token. Idempotent; run on deploy and after key rotation.
 * `--extra-jwks` keeps a previous key live during rotation.
 */
#[Signature('auth:publish-jwks {--extra-jwks= : Path to a JSON file of additional JWK keys to publish alongside (rotation)}')]
#[Description('Publishes the org-token signing key to the Worker as a JWKS')]
class AuthPublishJwksCommand extends Command
{
    public function handle(): int
    {
        $baseUrl = config('services.org_jwt.worker_base_url');
        $secret = config('services.org_jwt.jwks_write_secret');

        if (! $baseUrl || ! $secret) {
            $this->components->warn('ARTFCT_WORKER_BASE_URL and ARTFCT_JWKS_WRITE_SECRET are not both set; skipping.');

            return self::SUCCESS;
        }

        try {
            $keys = [OrgJwtService::default()->jwk()];
        } catch (RuntimeException $exception) {
            $this->components->warn('No org-token signing key configured; skipping.');

            return self::SUCCESS;
        }

        if ($path = $this->option('extra-jwks')) {
            $extra = json_decode((string) file_get_contents((string) $path), true);
            $keys = array_merge($keys, is_array($extra) ? $extra : []);
        }

        try {
            $response = Http::withToken($secret)->post(rtrim($baseUrl, '/').'/v1/internal/jwks', ['keys' => $keys]);
        } catch (Throwable $exception) {
            $this->components->error('Publish failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error("Worker refused the JWKS (HTTP {$response->status()}).");

            return self::FAILURE;
        }

        $this->components->info('Published '.count($keys).' key(s) to the Worker.');

        return self::SUCCESS;
    }
}
