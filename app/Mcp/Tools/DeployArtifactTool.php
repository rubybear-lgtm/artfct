<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpArtifactLink;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Services\Billing\BundleTooLargeException;
use App\Services\Billing\QuotaExceededException;
use App\Services\Billing\QuotaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

#[Description('Publish a self-contained HTML document as a permanent artifact in the authenticated workspace. The workspace can then search, retrieve, collect and count it. Re-publishing identical content returns the same artifact. The returned view_url is where a person opens the artifact: for a secure artifact it is the app\'s own open route, which mints a short-lived signed link bound to the viewer at click time; for a public artifact it is the workspace\'s public artifact URL. Call get_artifact for a fresh view_url.')]
#[Name('deploy_artifact')]
#[IsReadOnly(false)]
#[IsIdempotent(true)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
final class DeployArtifactTool extends Tool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['artifacts:deploy'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Publish a generated report to the team so it can be found and reused.',
                'arguments' => ['html' => '<!doctype html><title>Q3 report</title><p>Summary</p>', 'tier' => 'secure'],
            ]],
        ],
    ];

    private const MAX_HTML_BYTES = 1024 * 1024;

    private const CONTENT_TYPE = 'text/html; charset=utf-8';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, QuotaService $quotaService): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('artifacts:deploy', 'deploy_artifact');
        $validated = $request->validate([
            'html' => ['required', 'string', 'max:1048576'],
            'tier' => ['nullable', 'string', 'in:public,secure'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $html = trim($validated['html']);
        if ($html === '' || strlen($html) > self::MAX_HTML_BYTES) {
            return McpErrorResponse::error('The HTML payload must be between 1 byte and 1 MB.', 'payload_too_large');
        }

        $team = McpContext::team();

        try {
            $quotaService->assertCanCreateArtifact($team, strlen($html));
        } catch (QuotaExceededException|BundleTooLargeException $exception) {
            app(McpTelemetry::class)->record('deploy_artifact', $exception->errorCode, $startedAt);

            return McpErrorResponse::error(
                $exception->getMessage().' Use get_usage to inspect current limits and usage, then retry after remediation.',
                $exception->errorCode,
                false,
                'get_usage',
            );
        } catch (RequestException|ConnectionException|RuntimeException) {
            app(McpTelemetry::class)->record('deploy_artifact', 'upstream_unavailable', $startedAt);

            return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        $workerBaseUrl = config('services.worker.base_url');
        if (! is_string($workerBaseUrl) || $workerBaseUrl === '') {
            return McpErrorResponse::error('The artifact service is not configured.', 'configuration_error');
        }

        $baseUrl = rtrim($workerBaseUrl, '/');
        $token = McpContext::httpRequest()->bearerToken();
        $sha256 = hash('sha256', $html);
        $title = $validated['title'] ?? $this->extractTitle($html);
        $description = $validated['description'] ?? $title;

        try {
            $created = Http::withToken($token)->post($baseUrl.'/v1/artifacts', [
                'mode' => 'permanent',
                'tier' => $validated['tier'] ?? 'secure',
                'title' => $title,
                'description' => $description,
                'thumbnail' => 'https://artfct.dev/og-image.svg',
                'preview_blurred' => false,
                'manifest' => [
                    'entrypoint' => 'index.html',
                    'files' => [[
                        'path' => 'index.html',
                        'content_type' => self::CONTENT_TYPE,
                        'size_bytes' => strlen($html),
                        'sha256' => $sha256,
                    ]],
                    'external_origins' => [],
                ],
                'provenance' => $this->provenance($request, $validated['model'] ?? null),
            ]);

            if ($created->successful() && in_array($sha256, (array) $created->json('missing_files', []), true)) {
                $upload = Http::withToken($token)
                    ->withBody($html, self::CONTENT_TYPE)
                    ->put($baseUrl.'/v1/artifacts/'.$created->json('id').'/files/'.$sha256);

                if (! $upload->successful()) {
                    app(McpTelemetry::class)->record('deploy_artifact', 'error', $startedAt);

                    return McpErrorResponse::error('The artifact service could not store the artifact content.', 'deployment_rejected', true);
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('deploy_artifact', 'error', $startedAt);

            return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        if ($created->status() === 429) {
            app(McpTelemetry::class)->record('deploy_artifact', 'rate_limited', $startedAt);

            return McpErrorResponse::error('The workspace is publishing too quickly. Retry shortly.', 'rate_limited', true);
        }

        if ($created->status() === 403 && $created->json('error.code') === 'quota_exceeded') {
            app(McpTelemetry::class)->record('deploy_artifact', 'quota_exceeded', $startedAt);

            return McpErrorResponse::error('This workspace is over its plan limits. Use get_usage to inspect them.', 'quota_exceeded', false, 'get_usage');
        }

        if (! $created->successful()) {
            app(McpTelemetry::class)->record('deploy_artifact', 'error', $startedAt);

            return McpErrorResponse::error('The artifact service could not accept this deployment.', 'deployment_rejected');
        }

        $artifactId = (string) $created->json('id');
        $link = McpArtifactLink::forArtifact($team->slug, $artifactId, $created->json('tier'), $created->json('url'));

        if ($link instanceof Response) {
            app(McpTelemetry::class)->record('deploy_artifact', McpArtifactLink::ERROR_CODE, $startedAt, $artifactId);

            return $link;
        }

        app(McpTelemetry::class)->record('deploy_artifact', 'success', $startedAt, $artifactId);

        return Response::structured([
            'id' => $artifactId,
            'view_url' => $link,
            'tier' => $created->json('tier'),
            'title' => $title,
            'organization' => $team->slug,
        ]);
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
            'tier' => $schema->string()->enum(['public', 'secure'])->description('Who can open the link: secure (default, signed-in workspace members) or public.')->nullable(),
            'title' => $schema->string()->max(200)->description('Optional title; defaults to the document title.')->nullable(),
            'description' => $schema->string()->max(1000)->description('Optional summary used in search results.')->nullable(),
            'model' => $schema->string()->max(100)->description('Optional model name for provenance.')->nullable(),
        ];
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) === 1) {
            $title = trim(strip_tags($matches[1]));

            if ($title !== '') {
                return Str::limit($title, 200, '');
            }
        }

        return 'Untitled artifact';
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
            'tool' => 'deploy_artifact',
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
}
