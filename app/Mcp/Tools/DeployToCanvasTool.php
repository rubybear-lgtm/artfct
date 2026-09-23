<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Billing\BundleTooLargeException;
use App\Services\Billing\QuotaExceededException;
use App\Services\Billing\QuotaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[Description('Deprecated: use deploy_artifact. Encrypts and publishes an anonymous, expiring HTML artifact that the workspace cannot search, retrieve, collect or count. The returned view_url follows the same rule as the other tools: a secure artifact opens through the app\'s own open route, a public one through the workspace\'s public artifact URL.')]
#[Name('deploy_to_canvas')]
#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
final class DeployToCanvasTool extends Tool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['artifacts:deploy'],
            'compatibility' => 'deprecated',
            'replacedBy' => 'deploy_artifact',
            'examples' => [[
                'description' => 'Publish a generated dashboard for review.',
                'arguments' => ['html' => '<!doctype html><title>Dashboard</title>', 'tier' => 'public'],
            ]],
        ],
    ];

    private const MAX_HTML_BYTES = 1024 * 1024;

    private const SHARE_CODE_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, QuotaService $quotaService): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('artifacts:deploy', 'deploy_to_canvas');
        $validated = $request->validate([
            'html' => ['required', 'string', 'max:1048576'],
            'tier' => ['nullable', 'string', 'in:public,secure'],
            'ttl_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $html = trim($validated['html']);
        if (strlen($html) > self::MAX_HTML_BYTES) {
            return McpErrorResponse::error('The HTML payload exceeds the 1 MB limit.', 'payload_too_large');
        }

        $team = McpContext::team();
        $idempotency = $this->idempotencyContext($validated);
        $lockKey = null;

        if ($idempotency !== null) {
            $cached = Cache::get($idempotency['cache_key']);

            if (is_array($cached)) {
                if (($cached['fingerprint'] ?? null) !== $idempotency['fingerprint']) {
                    return Response::error('This request ID was already used with a different deployment payload.')
                        ->withMeta('artfct', [
                            'errorCode' => 'idempotency_key_reused',
                            'retryable' => false,
                        ]);
                }

                app(McpTelemetry::class)->record('deploy_to_canvas', 'duplicate', $startedAt);

                return Response::structured($cached['result']);
            }

            $lockKey = $idempotency['lock_key'];
            if (! Cache::add($lockKey, $idempotency['fingerprint'], 30)) {
                return Response::error('A deployment with this request ID is already in progress. Retry shortly.')
                    ->withMeta('artfct', [
                        'errorCode' => 'idempotency_in_progress',
                        'retryable' => true,
                    ]);
            }
        }

        try {
            $quotaService->assertCanCreateArtifact($team, strlen($html));
        } catch (QuotaExceededException|BundleTooLargeException $exception) {
            app(McpTelemetry::class)->record('deploy_to_canvas', $exception->errorCode, $startedAt);
            if ($lockKey !== null) {
                Cache::forget($lockKey);
            }

            return Response::error(
                $exception->getMessage().' Use get_usage to inspect current limits and usage, then retry after remediation.',
            )->withMeta('artfct', [
                'errorCode' => $exception->errorCode,
                'retryable' => false,
                'nextAction' => 'get_usage',
            ]);
        } catch (RequestException|ConnectionException|RuntimeException) {
            app(McpTelemetry::class)->record('deploy_to_canvas', 'upstream_unavailable', $startedAt);
            if ($lockKey !== null) {
                Cache::forget($lockKey);
            }

            return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        $shareCode = $this->shareCode();
        $iv = random_bytes(12);
        $key = hash('sha256', $shareCode, true);
        $tag = '';
        $ciphertext = openssl_encrypt($html, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            if ($lockKey !== null) {
                Cache::forget($lockKey);
            }

            return McpErrorResponse::error('Failed to encrypt the artifact.', 'encryption_failed');
        }

        $workerBaseUrl = config('services.worker.base_url');
        if (! is_string($workerBaseUrl) || $workerBaseUrl === '') {
            if ($lockKey !== null) {
                Cache::forget($lockKey);
            }

            return McpErrorResponse::error('The artifact service is not configured.', 'configuration_error');
        }

        $provenance = $this->provenance($request, $validated['model'] ?? null);

        try {
            $response = Http::withToken(McpContext::httpRequest()->bearerToken())
                ->post(rtrim($workerBaseUrl, '/').'/v1/artifacts', [
                    'mode' => 'ephemeral',
                    'body_ciphertext_b64' => $this->base64Url($ciphertext.$tag),
                    'body_iv_b64' => $this->base64Url($iv),
                    'tier' => $validated['tier'] ?? 'public',
                    'ttl_minutes' => $validated['ttl_minutes'] ?? null,
                    'title' => $this->extractTitle($html),
                    'description' => 'Encrypted HTML preview on artfct.',
                    'thumbnail' => 'https://artfct.dev/og-image.svg',
                    'preview_blurred' => true,
                    'provenance' => $provenance,
                ]);
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('deploy_to_canvas', 'error', $startedAt);
            if ($lockKey !== null) {
                Cache::forget($lockKey);
            }

            return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        if (! $response->successful()) {
            app(McpTelemetry::class)->record('deploy_to_canvas', 'error', $startedAt);
            if ($lockKey !== null) {
                Cache::forget($lockKey);
            }

            return McpErrorResponse::error('The artifact service could not accept this deployment.', 'deployment_rejected');
        }

        $artifactId = (string) $response->json('id');
        $tier = $response->json('tier');
        $tierAwareViewUrl = ArtifactViewLink::forArtifact($team->slug, $artifactId, is_string($tier) ? $tier : null, (string) $response->json('url'));

        $resultPayload = [
            'id' => $artifactId,
            // The share fragment is client-side only — the Worker never sees it
            // — so it rides along on whichever link the tier rule picks: the
            // Worker's own /p/{id} URL for an anonymous artifact (public or
            // ephemeral), the app's open route for a secure one. `canonical_url`
            // stays the Worker's canonical form; it is the resource's identity,
            // not a link a person opens.
            'view_url' => $tierAwareViewUrl.'#'.$shareCode,
            'canonical_url' => $response->json('url'),
            'tier' => $response->json('tier'),
            'expires_at' => $response->json('expires_at'),
            'title' => $response->json('title') ?: $this->extractTitle($html),
            'description' => $response->json('description') ?: 'Encrypted HTML preview on artfct.',
            'thumbnail' => $response->json('thumbnail') ?: 'https://artfct.dev/og-image.svg',
            'preview_blurred' => $response->json('preview_blurred', true),
            'organization' => $team->slug,
        ];

        if ($idempotency !== null) {
            Cache::put(
                $idempotency['cache_key'],
                ['fingerprint' => $idempotency['fingerprint'], 'result' => $resultPayload],
                now()->addSeconds((int) config('auth.mcp_idempotency_ttl_seconds', 600)),
            );
        }
        if ($lockKey !== null) {
            Cache::forget($lockKey);
        }

        app(McpTelemetry::class)->record('deploy_to_canvas', 'success', $startedAt);

        return Response::structured($resultPayload);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'html' => $schema->string()->min(1)->max(self::MAX_HTML_BYTES)->description('A self-contained HTML document.')->required(),
            'tier' => $schema->string()->enum(['public', 'secure'])->description('Visibility tier.')->nullable(),
            'ttl_minutes' => $schema->integer()->description('Optional lifetime in minutes.')->nullable(),
            'model' => $schema->string()->max(100)->description('Optional model name for provenance.')->nullable(),
        ];
    }

    private function shareCode(): string
    {
        return collect(range(1, 10))
            ->map(fn (): string => self::SHARE_CODE_ALPHABET[random_int(0, strlen(self::SHARE_CODE_ALPHABET) - 1)])
            ->implode('');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\\/title>/is', $html, $matches) === 1) {
            $title = trim(strip_tags($matches[1]));

            if ($title !== '') {
                return Str::limit($title, 255, '');
            }
        }

        return 'Encrypted artifact';
    }

    /** @return array<string, mixed> */
    private function provenance(Request $request, ?string $model): array
    {
        $sessionId = $request->sessionId();

        return [
            'agent' => null,
            'agent_raw' => null,
            'agent_version' => null,
            'model' => $model,
            'session_id' => $sessionId,
            'tool' => 'deploy_to_canvas',
            'repo_url' => null,
            'branch' => null,
            'commit_sha' => null,
            'dirty' => null,
            'source_path' => null,
            'client' => 'artfct-remote-mcp',
            'client_version' => '1.0.0',
            'sources' => [
                'agent' => 'absent',
                'agent_raw' => 'absent',
                'agent_version' => 'absent',
                'model' => $model === null ? 'absent' : 'self_reported',
                'session_id' => $sessionId === null ? 'absent' : 'process',
                'tool' => 'config',
                'repo_url' => 'absent',
                'branch' => 'absent',
                'commit_sha' => 'absent',
                'dirty' => 'absent',
                'source_path' => 'absent',
            ],
        ];
    }

    /**
     * Build a tenant- and credential-scoped cache identity for opt-in retry
     * deduplication. The request ID and payload never enter cache keys raw.
     *
     * @param  array{html: string, tier?: string|null, ttl_minutes?: int|null, model?: string|null}  $validated
     * @return array{cache_key: string, lock_key: string, fingerprint: string}|null
     */
    private function idempotencyContext(array $validated): ?array
    {
        $request = McpContext::httpRequest();
        $requestId = $request->header('MCP-Request-Id') ?? $request->header('Idempotency-Key');

        if (! is_string($requestId) || trim($requestId) === '' || strlen($requestId) > 128) {
            return null;
        }

        $claims = McpContext::claims();
        $team = McpContext::team();
        $fingerprint = hash('sha256', serialize([
            $validated['html'],
            $validated['tier'] ?? null,
            $validated['ttl_minutes'] ?? null,
            $validated['model'] ?? null,
        ]));

        $identity = hash('sha256', implode('|', [
            $team->slug,
            $claims['jti'],
            'deploy_to_canvas',
            trim($requestId),
        ]));

        return [
            'cache_key' => 'mcp:idempotency:'.$identity,
            'lock_key' => 'mcp:idempotency:'.$identity.':lock',
            'fingerprint' => $fingerprint,
        ];
    }
}
