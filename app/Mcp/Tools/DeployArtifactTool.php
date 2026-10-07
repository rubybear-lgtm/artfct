<?php

namespace App\Mcp\Tools;

use App\Enums\AuditEventType;
use App\Mcp\Support\McpArtifactLink;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Services\Artifacts\ArtifactIdShape;
use App\Services\Billing\BundleTooLargeException;
use App\Services\Billing\QuotaExceededException;
use App\Services\Billing\QuotaService;
use App\Services\Governance\AuditLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\JsonSchema\Types\Type;
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

#[Description('Publish a self-contained HTML document, or a multi-file bundle (an entrypoint plus its CSS, scripts, images and other assets, each file given in `files`), as a permanent artifact in the authenticated workspace. The workspace can then search, retrieve, collect and count it. Re-publishing identical content returns the same artifact. The returned view_url is where a person opens the artifact: for a secure artifact it is the app\'s own open route, which mints a short-lived signed link bound to the viewer at click time; for a public artifact it is the workspace\'s public artifact URL. Call get_artifact for a fresh view_url.')]
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
                'arguments' => ['html' => '<!doctype html><title>Q3 report</title><p>Summary</p>', 'sharing' => 'team'],
            ]],
        ],
    ];

    private const MAX_HTML_BYTES = 1024 * 1024;

    private const MAX_BUNDLE_FILES = 500;

    private const MAX_BUNDLE_BYTES = 8 * 1024 * 1024;

    private const DEFAULT_ENTRYPOINT = 'index.html';

    private const CONTENT_TYPES = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript',
        'json' => 'application/json', 'wasm' => 'application/wasm', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];

    private const CONTENT_TYPE = 'text/html; charset=utf-8';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, QuotaService $quotaService): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('artifacts:deploy', 'deploy_artifact');
        $validated = $request->validate([
            'html' => ['nullable', 'string', 'max:1048576'],
            'files' => ['nullable', 'array', 'min:1', 'max:'.self::MAX_BUNDLE_FILES],
            'files.*.path' => ['required', 'string', 'max:255'],
            'files.*.content' => ['required', 'string'],
            'files.*.encoding' => ['nullable', 'string', 'in:utf8,base64'],
            'files.*.content_type' => ['nullable', 'string', 'max:100'],
            'entrypoint' => ['nullable', 'string', 'max:255'],
            'artifact_id' => ['nullable', 'string', 'max:128'],
            'sharing' => ['nullable', 'string', 'in:private,team,public'],
            'edit_access' => ['nullable', 'string', 'in:view,edit'],
            'tier' => ['nullable', 'string', 'in:public,secure'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $artifactIdInput = $validated['artifact_id'] ?? null;
        if ($artifactIdInput !== null && ! ArtifactIdShape::isPermanent($artifactIdInput)) {
            return McpErrorResponse::error('`artifact_id` must be the permanent artifact id deploy_artifact returned: 13 lowercase base36 characters or 32 hexadecimal characters.', 'invalid_request');
        }
        $versionPublish = $artifactIdInput !== null;

        $hasHtml = isset($validated['html']) && trim($validated['html']) !== '';
        $hasFiles = ! empty($validated['files']);
        if ($hasHtml === $hasFiles) {
            return McpErrorResponse::error('Provide either `html` (one document) or `files` (a bundle), not both and not neither.', 'invalid_request');
        }

        $bundle = $hasFiles
            ? $this->bundleFromFiles($validated['files'], $validated['entrypoint'] ?? self::DEFAULT_ENTRYPOINT)
            : $this->bundleFromHtml(trim($validated['html']));

        if (is_string($bundle)) {
            return McpErrorResponse::error($bundle, 'invalid_request');
        }

        $entrypoint = $bundle['entrypoint'];
        $files = $bundle['files'];
        $totalBytes = 0;
        foreach ($files as $file) {
            $totalBytes += strlen($file['bytes']);
        }
        $entryBytes = $files[$entrypoint]['bytes'];

        if ($totalBytes < 1 || $totalBytes > ($hasFiles ? self::MAX_BUNDLE_BYTES : self::MAX_HTML_BYTES)) {
            return McpErrorResponse::error($hasFiles ? 'The bundle must be between 1 byte and 8 MB in total.' : 'The HTML payload must be between 1 byte and 1 MB.', 'payload_too_large');
        }

        $team = McpContext::team();

        try {
            $quotaService->assertCanCreateArtifact($team, $totalBytes);
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
        $title = $validated['title'] ?? $this->extractTitle($entryBytes);
        $description = $validated['description'] ?? $title;

        try {
            $payload = [
                'title' => $title,
                'description' => $description,
                'thumbnail' => 'https://artfct.dev/og-image.svg',
                'preview_blurred' => false,
                'manifest' => [
                    'entrypoint' => $entrypoint,
                    'files' => array_values(array_map(fn (array $file): array => [
                        'path' => $file['path'],
                        'content_type' => $file['content_type'],
                        'size_bytes' => strlen($file['bytes']),
                        'sha256' => $file['sha256'],
                    ], $files)),
                    'external_origins' => [],
                ],
                'provenance' => $this->provenance($request, $validated['model'] ?? null),
            ];

            // A new version keeps the artifact's existing sharing, so those
            // fields are deliberately absent from a version request.
            if (! $versionPublish) {
                $sharing = $validated['sharing']
                    ?? $this->sharingForTier($validated['tier'] ?? null)
                    ?? 'team';

                $payload = [
                    'mode' => 'permanent',
                    // `tier` is the deprecated alias: `sharing` wins when both
                    // are given, and the tier sent always matches the sharing
                    // so an older Worker that only knows `tier` still accepts it.
                    'tier' => $this->tierForSharing($sharing),
                    'sharing' => $sharing,
                    'edit_access' => $validated['edit_access'] ?? 'view',
                ] + $payload;
            }

            $created = Http::withToken($token)->post(
                $versionPublish ? $baseUrl.'/v1/artifacts/'.$artifactIdInput.'/versions' : $baseUrl.'/v1/artifacts',
                $payload,
            );

            if ($created->successful()) {
                $uploaded = [];
                foreach ((array) $created->json('missing_files', []) as $missing) {
                    $file = collect($files)->firstWhere('sha256', $missing);
                    if ($file === null || isset($uploaded[$missing])) {
                        continue;
                    }
                    $uploaded[$missing] = true;

                    $upload = Http::withToken($token)
                        ->withBody($file['bytes'], $file['content_type'])
                        ->put($baseUrl.'/v1/artifacts/'.$created->json('id').'/files/'.$missing);

                    if (! $upload->successful()) {
                        app(McpTelemetry::class)->record('deploy_artifact', 'error', $startedAt);

                        return McpErrorResponse::error('The artifact service could not store the artifact content.', 'deployment_rejected', true);
                    }
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

        if ($created->status() === 403 && $created->json('error.code') === 'public_sharing_disabled') {
            app(McpTelemetry::class)->record('deploy_artifact', 'public_sharing_disabled', $startedAt);

            return McpErrorResponse::error('Your team has turned off public links. Publish it with sharing set to team or private instead.', 'public_sharing_disabled');
        }

        if ($versionPublish && $created->status() === 403 && $created->json('error.code') === 'forbidden') {
            app(McpTelemetry::class)->record('deploy_artifact', 'edit_forbidden', $startedAt);

            return McpErrorResponse::error('You can only publish new versions of artifacts you own or that are shared with you for editing. Publish it as a new artifact instead by calling deploy_artifact without artifact_id.', 'edit_forbidden');
        }

        if ($versionPublish && $created->status() === 404) {
            app(McpTelemetry::class)->record('deploy_artifact', 'artifact_not_found', $startedAt);

            return McpErrorResponse::error('That artifact was not found in the authenticated workspace.', 'artifact_not_found');
        }

        if ($versionPublish && $created->status() === 409) {
            app(McpTelemetry::class)->record('deploy_artifact', 'version_conflict', $startedAt);

            return McpErrorResponse::error('Another version was being published at the same time. Retry the deployment.', 'version_conflict', true);
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

        $tier = $created->json('tier');
        $version = (int) $created->json('version');

        app(McpTelemetry::class)->record('deploy_artifact', 'success', $startedAt, $artifactId);
        $this->audit->recordForRequest(
            McpContext::httpRequest(),
            AuditEventType::ArtifactDeployed,
            $team,
            McpContext::actor(),
            // A version publish is audited against the version, so the log
            // records which publish happened rather than only the artifact.
            $versionPublish ? "artifact:{$artifactId} version:{$version}" : "artifact:{$artifactId}",
        );

        $result = [
            'id' => $artifactId,
            'view_url' => $link,
            // `tier` is the deprecated alias, kept for one release; `sharing`
            // is the name to use.
            'tier' => $tier,
            'sharing' => $this->sharingFromResponse($created->json('sharing'), is_string($tier) ? $tier : null),
            'version' => $version,
            'title' => $title,
            'organization' => $team->slug,
        ];

        if ($versionPublish) {
            $result['created'] = (bool) $created->json('created');
        }

        return Response::structured($result);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'html' => $schema->string()->max(self::MAX_HTML_BYTES)->description('A self-contained HTML document. Give this or `files`, not both.')->nullable(),
            'files' => $schema->array()->items($schema->object([
                'path' => $schema->string()->max(255)->description('Relative path inside the bundle, e.g. `index.html` or `assets/app.css`. No leading slash, `..` or backslashes.')->required(),
                'content' => $schema->string()->description('The file content.')->required(),
                'encoding' => $schema->string()->enum(['utf8', 'base64'])->description('How `content` is encoded: utf8 (default, for text) or base64 (for images, fonts and other binary files).')->nullable(),
                'content_type' => $schema->string()->max(100)->description('Optional media type; inferred from the file extension when omitted.')->nullable(),
            ]))->max(self::MAX_BUNDLE_FILES)->description('A multi-file bundle (up to 500 files, 8 MB in total) in place of `html`. Pages reference other files by relative path.')->nullable(),
            'entrypoint' => $schema->string()->max(255)->description('Path of the file people open first; defaults to `index.html`. Must be one of `files`.')->nullable(),
            'artifact_id' => $schema->string()->max(128)->description('To update an artifact you published before, pass its id; this publishes a new version with the same link instead of a new artifact.')->nullable(),
            'sharing' => $schema->string()->enum(['private', 'team', 'public'])->description('Who can open it: team (default, everyone on your team), private (only you and team admins) or public (anyone with the link). A version published with `artifact_id` never changes sharing.')->nullable(),
            'edit_access' => $schema->string()->enum(['view', 'edit'])->description('Whether the people it is shared with can publish new versions. Defaults to view. A version published with `artifact_id` never changes it.')->nullable(),
            'tier' => $schema->string()->enum(['public', 'secure'])->description('Deprecated, use sharing.')->nullable(),
            'title' => $schema->string()->max(200)->description('Optional title; defaults to the document title.')->nullable(),
            'description' => $schema->string()->max(1000)->description('Optional summary used in search results.')->nullable(),
            'model' => $schema->string()->max(100)->description('Optional model name for provenance.')->nullable(),
        ];
    }

    /**
     * @param  list<array{path: string, content: string, encoding?: ?string, content_type?: ?string}>  $input
     * @return array{entrypoint: string, files: array<string, array{path: string, bytes: string, content_type: string, sha256: string}>}|string An error message when the bundle is invalid.
     */
    private function bundleFromFiles(array $input, string $entrypoint): array|string
    {
        $files = [];
        foreach ($input as $file) {
            $path = $file['path'];
            if (! $this->isValidPath($path)) {
                return "Invalid file path [{$path}]: use a relative path without a leading slash, `..`, empty segments, backslashes or colons.";
            }
            if (isset($files[$path])) {
                return "Duplicate file path [{$path}].";
            }

            $bytes = ($file['encoding'] ?? 'utf8') === 'base64'
                ? base64_decode($file['content'], true)
                : $file['content'];
            if ($bytes === false) {
                return "File [{$path}] is not valid base64.";
            }

            $files[$path] = [
                'path' => $path,
                'bytes' => $bytes,
                'content_type' => $file['content_type'] ?? $this->contentTypeFor($path),
                'sha256' => hash('sha256', $bytes),
            ];
        }

        if (! $this->isValidPath($entrypoint) || ! isset($files[$entrypoint])) {
            return "The entrypoint [{$entrypoint}] must be one of the files in the bundle.";
        }

        return ['entrypoint' => $entrypoint, 'files' => $files];
    }

    /**
     * @return array{entrypoint: string, files: array<string, array{path: string, bytes: string, content_type: string, sha256: string}>}
     */
    private function bundleFromHtml(string $html): array
    {
        return [
            'entrypoint' => self::DEFAULT_ENTRYPOINT,
            'files' => [self::DEFAULT_ENTRYPOINT => [
                'path' => self::DEFAULT_ENTRYPOINT,
                'bytes' => $html,
                'content_type' => self::CONTENT_TYPE,
                'sha256' => hash('sha256', $html),
            ]],
        ];
    }

    /** Mirrors the Worker's `is_valid_relative_path`, so a bad path is named here instead of refused opaquely there. */
    private function isValidPath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 255
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '\\')
            && ! str_contains($path, ':')
            && ! in_array('..', explode('/', $path), true)
            && ! in_array('', explode('/', $path), true);
    }

    private function contentTypeFor(string $path): string
    {
        return self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /** The sharing a deprecated `tier` alias names, or null when it names none. */
    private function sharingForTier(?string $tier): ?string
    {
        return match ($tier) {
            'secure' => 'team',
            'public' => 'public',
            'private' => 'private',
            default => null,
        };
    }

    /** The `tier` an older Worker still understands, derived from `sharing`. */
    private function tierForSharing(string $sharing): string
    {
        return match ($sharing) {
            'public' => 'public',
            'private' => 'private',
            default => 'secure',
        };
    }

    /**
     * The sharing to report: the Worker's own value when it returned one, else
     * the tier mapped to its sharing name (`secure` = `team`).
     */
    private function sharingFromResponse(mixed $sharing, ?string $tier): ?string
    {
        return is_string($sharing) && $sharing !== '' ? $sharing : $this->sharingForTier($tier);
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
