<?php

namespace App\Services\Synthetic;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Deploys the synthetic corpus through the Worker's real API
 * (`POST /v1/artifacts` then `PUT .../files/{sha}`), so provenance capture,
 * quota accounting and event emission all run. State that only exists in
 * the Worker's database (backdating, expiry) is applied with the seed-only
 * `wrangler d1 execute`, local and staging only.
 */
final class WorkerDeployer
{
    public function __construct(private readonly WorkerTarget $target) {}

    /**
     * Deploys one artifact and returns its Worker-assigned id. A 413/403
     * refusal is returned as an error string instead of thrown, so refusal
     * of the over-ceiling bundle is an expected outcome, not a failure.
     *
     * @param  array<string, mixed>  $spec
     * @return array{id: string|null, error: string|null}
     */
    public function deploy(array $spec): array
    {
        $files = [];
        foreach ($spec['files'] as $file) {
            $bytes = SyntheticFile::content($file);
            $files[] = ['spec' => $file, 'bytes' => $bytes, 'sha256' => hash('sha256', $bytes)];
        }

        $response = $this->sendWithRateLimitRetry(fn () => $this->http()->post($this->target->url.'/v1/artifacts', [
            'mode' => 'permanent',
            'tier' => $spec['tier'],
            'title' => $spec['title'],
            'description' => $spec['description'],
            'thumbnail' => '',
            'preview_blurred' => false,
            'provenance' => $spec['provenance'],
            'manifest' => [
                'entrypoint' => 'index.html',
                'external_origins' => [],
                'files' => array_map(fn (array $file): array => [
                    'path' => $file['spec']['path'],
                    'sha256' => $file['sha256'],
                    'size_bytes' => strlen($file['bytes']),
                    'content_type' => $file['spec']['content_type'],
                ], $files),
            ],
        ]));

        if (! $response->successful()) {
            return ['id' => null, 'error' => (string) ($response->json('error.code') ?? 'http_'.$response->status())];
        }

        $id = (string) $response->json('id');
        $missing = $response->json('missing_files', []);

        foreach ($files as $file) {
            if (! in_array($file['sha256'], $missing, true)) {
                continue;
            }
            $missing = array_values(array_diff($missing, [$file['sha256']]));
            $this->http()->withBody($file['bytes'], 'application/octet-stream')
                ->put($this->target->url."/v1/artifacts/{$id}/files/{$file['sha256']}")
                ->throw();
        }

        return ['id' => $id, 'error' => null];
    }

    public function exists(string $artifactId): bool
    {
        return $this->http()->get($this->target->url."/v1/orgs/{$this->target->orgSlug}/artifacts", ['limit' => 200])
            ->collect('artifacts')->contains('id', $artifactId);
    }

    /**
     * @return list<array{id: string, created_at: string, revoked_at: string|null}>
     */
    public function listArtifacts(): array
    {
        $artifacts = [];
        $cursor = null;
        do {
            $response = $this->http()->get($this->target->url."/v1/orgs/{$this->target->orgSlug}/artifacts", array_filter(['limit' => 200, 'cursor' => $cursor]))->throw();
            foreach ($response->json('artifacts', []) as $artifact) {
                $artifacts[] = $artifact;
            }
            $cursor = $response->json('next_cursor');
        } while ($cursor !== null);

        return $artifacts;
    }

    public function revoke(string $artifactId): void
    {
        $this->http()->patch($this->target->url."/v1/orgs/{$this->target->orgSlug}/artifacts/{$artifactId}", ['revoked_at' => now()->toIso8601String()])->throw();
    }

    public function placeLegalHold(string $artifactId): void
    {
        $this->governance()->put($this->governanceUrl("artifacts/{$artifactId}/legal-hold"))->throw();
    }

    public function releaseLegalHold(string $artifactId): void
    {
        $this->governance()->delete($this->governanceUrl("artifacts/{$artifactId}/legal-hold"))->throw();
    }

    public function hardDelete(string $artifactId): void
    {
        $this->governance()->delete($this->governanceUrl("artifacts/{$artifactId}"))->throw();
    }

    /**
     * Seed-only: rewrites `created_at` (and optionally `expires_at`) so the
     * corpus spans 12 months and retention has candidates. One
     * `wrangler d1 execute` against local persisted state or the remote DB
     * for the whole batch.
     *
     * @param  array<string, array{created_at: \DateTimeInterface, expires_at?: \DateTimeInterface|null}>  $changes  keyed by artifact id
     */
    public function backdate(array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $statements = [];
        foreach ($changes as $artifactId => $change) {
            $expires = $change['expires_at'] ?? null;
            $statements[] = sprintf(
                "UPDATE artifacts SET created_at = '%s'%s WHERE id = '%s'",
                $change['created_at']->format('Y-m-d\TH:i:s\Z'),
                $expires ? sprintf(", expires_at = '%s'", $expires->format('Y-m-d\TH:i:s\Z')) : '',
                preg_replace('/[^a-f0-9]/', '', (string) $artifactId),
            );
        }

        // A deployed app has no dev dependencies, so no wrangler binary; the
        // remote database is reachable through Cloudflare's D1 HTTP API instead.
        if ($this->target->remote && ! is_file(base_path('node_modules/.bin/wrangler'))) {
            $this->backdateThroughD1Api($statements);

            return;
        }

        $command = [base_path('node_modules/.bin/wrangler'), 'd1', 'execute', 'ARTIFACTS_DB', $this->target->remote ? '--remote' : '--local', '--command', implode('; ', $statements)];
        if ($this->target->wranglerConfig) {
            $command[] = '--config';
            $command[] = $this->target->wranglerConfig;
        }
        if (! $this->target->remote && $this->target->persistTo) {
            $command[] = '--persist-to';
            $command[] = $this->target->persistTo;
        }

        // The app's own .env may carry a Cloudflare token scoped for something else,
        // which wrangler would prefer over `wrangler login`. Remote runs use a token
        // dedicated to the seed, or fall back to the logged-in session.
        $process = Process::path(base_path('backend'))->timeout(300);
        if ($this->target->remote) {
            $process = $process->env(['CLOUDFLARE_API_TOKEN' => config('synthetic.cloudflare_api_token') ?: false]);
        }

        $result = $process->run($command);

        if ($result->failed()) {
            throw new RuntimeException('wrangler d1 execute failed: '.$result->errorOutput());
        }
    }

    /**
     * Runs the seed-only statements as one D1 batch over the HTTP API, against
     * the database named in this target's wrangler config.
     *
     * @param  list<string>  $statements
     */
    private function backdateThroughD1Api(array $statements): void
    {
        $configPath = base_path('backend/'.$this->target->wranglerConfig);
        $databaseId = is_file($configPath) && preg_match('/"database_id"\s*:\s*"([0-9a-f-]{36})"/', (string) file_get_contents($configPath), $matches) === 1
            ? $matches[1]
            : null;
        $accountId = config('services.cloudflare.account_id');
        $token = config('synthetic.cloudflare_api_token') ?: config('services.cloudflare.api_token');

        if (! $databaseId || ! $accountId || ! $token) {
            throw new RuntimeException('Backdating without wrangler needs the wrangler config, CLOUDFLARE_ACCOUNT_ID and a Cloudflare API token.');
        }

        $response = Http::withToken((string) $token)->acceptJson()->timeout(120)->post(
            "https://api.cloudflare.com/client/v4/accounts/{$accountId}/d1/database/{$databaseId}/query",
            ['batch' => array_map(fn (string $sql): array => ['sql' => $sql], $statements)],
        );

        if ($response->failed() || $response->json('success') !== true) {
            throw new RuntimeException('D1 API backdating failed: HTTP '.$response->status());
        }
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->target->token)->acceptJson()->timeout(120);
    }

    private function governance(): PendingRequest
    {
        if (! $this->target->governanceSecret) {
            throw new RuntimeException('No governance secret configured for this Worker target.');
        }

        return Http::withToken($this->target->governanceSecret)->acceptJson()->timeout(120);
    }

    private function governanceUrl(string $path): string
    {
        return $this->target->url."/v1/internal/orgs/{$this->target->orgSlug}/governance/{$path}";
    }

    /**
     * The Worker rate-limits creates to 60 per minute per token; wait out a
     * 429 rather than fail the seed.
     *
     * @param  callable(): Response  $send
     */
    private function sendWithRateLimitRetry(callable $send): Response
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $send();
            if ($response->status() !== 429) {
                return $response;
            }
            sleep(max(1, (int) ($response->header('Retry-After') ?: 61)));
        }

        return $response;
    }
}
