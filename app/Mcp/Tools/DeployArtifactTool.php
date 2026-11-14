<?php

namespace App\Mcp\Tools;

use App\Enums\ArtifactType;
use App\Mcp\Support\McpArtifactLink;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Models\Collection;
use App\Services\Artifacts\ArtifactIdShape;
use App\Services\Artifacts\ArtifactPublish;
use App\Services\Artifacts\ArtifactPublisher;
use App\Services\Artifacts\PublishFailedException;
use App\Services\Collections\CollectionService;
use App\Services\Indexing\ExtractionHeuristics;
use App\Services\Templates\TeamFeatureGates;
use App\Support\ClientIp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Rules in get_guidance (topic publish) apply even if you have not read them. Publish a self-contained HTML document, or a multi-file bundle (an entrypoint plus its CSS, scripts, images and other assets, each file given in `files`), as a permanent artifact in the authenticated workspace. For markdown, pass `files` with `entrypoint` set to a `.md` or `.markdown` path such as `README.md` (the `html` parameter rejects markdown). Inline `<script>` blocks, `onclick=` handlers, `data:` images and resources from other sites do not load on the served page: put scripts, images and fonts in `files`. Publishing content that still contains an inline `<script>`, `onclick=` or `data:image` returns a `warnings` array. Optional `collection` (name or id) adds a newly created artifact to that workspace collection in the same call and requires collections:write; on a version publish with `artifact_id`, omitted fields (title, description, sharing, edit_access, collection) keep their previous values. Optional `base_version` with `artifact_id` checks for conflicts; omitting it overwrites without a conflict check (`base_version_checked: false`). Re-publishing identical content returns the same artifact. Optional `db` declares per-artifact database collections plus seed rows written once on first deploy (requires the artifact-db feature); on a version publish, explicit `db: null` removes the endpoint. The returned view_url is where a person opens the artifact: for a secure artifact it is the app\'s own open route, which mints a short-lived signed link bound to the viewer at click time; for a public artifact it is the workspace\'s public artifact URL. Call get_artifact for a fresh view_url.')]
#[Name('deploy_artifact')]
#[Title('Publish artifact')]
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
            'guidanceVersion' => '1.2.0',
            'toolVersion' => '1.1.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['artifacts:deploy'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Publish a report into an existing collection in one call.',
                'arguments' => [
                    'html' => '<!doctype html><title>Q3 report</title><p>Summary</p>',
                    'sharing' => 'team',
                    'collection' => 'Launch',
                ],
            ], [
                'description' => 'Publish markdown via files so list kind stays markdown.',
                'arguments' => [
                    'files' => [['path' => 'README.md', 'content' => "# Notes\n\nHello"]],
                    'entrypoint' => 'README.md',
                ],
            ]],
        ],
    ];

    private const MAX_HTML_BYTES = 1024 * 1024;

    private const MAX_BUNDLE_FILES = 500;

    private const MAX_BUNDLE_BYTES = 8 * 1024 * 1024;

    private const DEFAULT_ENTRYPOINT = 'index.html';

    private const CONTENT_TYPE = 'text/html; charset=utf-8';

    public function __construct(private readonly ArtifactPublisher $publisher) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, CollectionService $collections): ResponseFactory
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
            'base_version' => ['nullable', 'integer', 'min:1'],
            'artifact_type' => ['nullable', 'string', 'in:'.implode(',', ArtifactType::values())],
            'sharing' => ['nullable', 'string', 'in:private,team,public'],
            'edit_access' => ['nullable', 'string', 'in:view,edit'],
            'tier' => ['nullable', 'string', 'in:public,secure'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'model' => ['nullable', 'string', 'max:100'],
            'collection' => ['nullable', 'string', 'max:100'],
            'db' => ['nullable', 'array'],
            'db.collections' => ['nullable', 'array'],
            'db.seed' => ['nullable', 'array', 'max:1000'],
            'db.seed.*.collection' => ['required', 'string', 'regex:/\A[A-Za-z0-9._-]{1,64}\z/'],
            'db.seed.*.doc_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9._-]{1,128}\z/'],
            'db.seed.*.body' => ['required', 'array'],
        ]);

        $artifactIdInput = $validated['artifact_id'] ?? null;
        if ($artifactIdInput !== null && ! ArtifactIdShape::isPermanent($artifactIdInput)) {
            return McpErrorResponse::error('`artifact_id` must be the permanent artifact id deploy_artifact returned: 13 lowercase base36 characters or 32 hexadecimal characters.', 'invalid_request');
        }
        $versionPublish = $artifactIdInput !== null;
        $baseVersion = isset($validated['base_version']) ? (int) $validated['base_version'] : null;
        if ($baseVersion !== null && ! $versionPublish) {
            return McpErrorResponse::error('`base_version` only applies when `artifact_id` is given.', 'invalid_request');
        }
        // The db manifest is opt-in per publish. An explicit `db: null` on a
        // version publish removes the endpoint; omitting `db` keeps whatever
        // the artifact already declares. Seed rows travel with a template:
        // they are written once on first deploy, never copied from live rows.
        $dbGiven = array_key_exists('db', $validated);
        $dbManifest = new \stdClass;
        if ($dbGiven && $validated['db'] !== null) {
            if (! app(TeamFeatureGates::class)->enabled(TeamFeatureGates::ARTIFACT_DB, McpContext::team())) {
                app(McpTelemetry::class)->record('deploy_artifact', 'feature_not_enabled', $startedAt);

                return McpErrorResponse::error('Artifact databases are not enabled in this workspace yet.', 'feature_not_enabled');
            }
            $dbManifest = $this->dbManifest($validated['db']);
            if (is_string($dbManifest)) {
                app(McpTelemetry::class)->record('deploy_artifact', 'invalid_request', $startedAt);

                return McpErrorResponse::error($dbManifest, 'invalid_request');
            }
        } elseif ($dbGiven) {
            // Explicit null: remove the endpoint on a version publish, no-op
            // (no manifest) on a new artifact.
            $dbManifest = null;
        }

        // Collection membership only applies to a new artifact; a version
        // publish keeps the existing collections and ignores this field.
        $targetCollection = null;
        $collectionInput = isset($validated['collection']) ? trim($validated['collection']) : '';
        if ($collectionInput !== '' && ! $versionPublish) {
            McpContext::requireScope('collections:write', 'deploy_artifact');
            $targetCollection = $collections->findForTeam(McpContext::team(), $collectionInput);
            if ($targetCollection === null) {
                app(McpTelemetry::class)->record('deploy_artifact', 'collection_not_found', $startedAt);

                return McpErrorResponse::error('That collection was not found in the authenticated workspace. Pass its id or exact name from list_collections, or create it first with create_collection.', 'collection_not_found', false, 'list_collections');
            }
        }

        $hasHtml = isset($validated['html']) && trim($validated['html']) !== '';
        $hasFiles = ! empty($validated['files']);
        if ($hasHtml === $hasFiles) {
            return McpErrorResponse::error('Provide either `html` (one document) or `files` (a bundle), not both and not neither.', 'invalid_request');
        }

        // Choice (RUB-515): reject markdown in `html` rather than convert it.
        // Agents must use `files` + `entrypoint: README.md` so list kind stays
        // `markdown`. Raw markdown is stored as text/markdown; the Worker is
        // the sole HTML renderer (strips raw HTML on serve).
        if ($hasHtml && ExtractionHeuristics::looksLikeMarkdown(trim($validated['html']))) {
            return McpErrorResponse::error(
                'The `html` parameter expects HTML, not markdown. Publish markdown with `files` and `entrypoint` set to a `.md` path such as `README.md`.',
                'invalid_request',
            );
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
        $warnings = $this->contentWarnings($files);

        if ($totalBytes < 1 || $totalBytes > ($hasFiles ? self::MAX_BUNDLE_BYTES : self::MAX_HTML_BYTES)) {
            return McpErrorResponse::error($hasFiles ? 'The bundle must be between 1 byte and 8 MB in total.' : 'The HTML payload must be between 1 byte and 1 MB.', 'payload_too_large');
        }

        $team = McpContext::team();
        $actor = McpContext::actor();
        $httpRequest = McpContext::httpRequest();
        $titleProvided = array_key_exists('title', $validated);
        $descriptionProvided = array_key_exists('description', $validated);
        // A version publish without an explicit title keeps the previous one;
        // the publisher derives the create-time fallback from the entry bytes.
        $title = $titleProvided
            ? ($validated['title'] ?? ArtifactPublisher::extractTitle($entryBytes))
            : null;
        $description = $descriptionProvided
            ? ($validated['description'] ?? $title)
            : null;

        try {
            $published = $this->publisher->publish(new ArtifactPublish(
                team: $team,
                actor: $actor,
                credentialToken: (string) $httpRequest->bearerToken(),
                files: $files,
                entrypoint: $entrypoint,
                startedAt: $startedAt,
                title: $title,
                description: $description,
                sharing: $validated['sharing']
                    ?? $this->sharingForTier($validated['tier'] ?? null)
                    ?? 'team',
                editAccess: $validated['edit_access'] ?? 'view',
                artifactId: $artifactIdInput,
                baseVersion: $baseVersion,
                collection: $targetCollection,
                provenance: $this->provenance($request, $validated['model'] ?? null),
                declaredType: isset($validated['artifact_type']) ? ArtifactType::from($validated['artifact_type']) : null,
                db: $dbGiven && $validated['db'] !== null && is_array($dbManifest) ? $dbManifest : null,
                dbGiven: $dbGiven,
                recordTelemetry: true,
                source: 'mcp',
                tool: 'deploy_artifact',
                ip: ClientIp::for($httpRequest),
                userAgent: $httpRequest->userAgent() ?? 'unknown',
                clientName: is_string($clientName = $httpRequest->header('MCP-Client-Name')) && trim($clientName) !== '' ? trim($clientName) : null,
            ));
        } catch (PublishFailedException $exception) {
            return McpErrorResponse::error(
                $exception->getMessage(),
                $exception->errorCode,
                $exception->retryable,
                $exception->nextAction,
                $exception->details,
            );
        }

        $artifactId = $published['id'];
        $link = McpArtifactLink::forArtifact($team->slug, $artifactId, $published['tier'], $published['url']);

        if ($link instanceof ResponseFactory) {
            app(McpTelemetry::class)->record('deploy_artifact', McpArtifactLink::ERROR_CODE, $startedAt, $artifactId);

            return $link;
        }

        $result = [
            'id' => $artifactId,
            'artifact_type' => $published['artifact_type'],
            'view_url' => $link,
            'sharing' => $published['sharing'],
            'version' => $published['version'],
            'created' => $published['created'],
            'title' => $published['title'],
            'organization' => $team->slug,
        ];

        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        if ($targetCollection instanceof Collection) {
            $result['collection_id'] = $targetCollection->id;
            $result['collection'] = $targetCollection->name;
        }

        if ($versionPublish) {
            $result['base_version_checked'] = $baseVersion !== null;
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
            'html' => $schema->string()->max(self::MAX_HTML_BYTES)->description('A self-contained HTML document, without inline scripts, onclick handlers, data: images or outside resources (they do not load; put scripts, images and fonts in `files`). Markdown is refused here — use `files` with `entrypoint: README.md` instead. Give this or `files`, not both.')->nullable(),
            'files' => $schema->array()->items($schema->object([
                'path' => $schema->string()->max(255)->description('Relative path inside the bundle, e.g. `index.html`, `README.md` or `assets/app.css`. No leading slash, `..` or backslashes.')->required(),
                'content' => $schema->string()->description('The file content.')->required(),
                'encoding' => $schema->string()->enum(['utf8', 'base64'])->description('How `content` is encoded: utf8 (default, for text) or base64 (for images, fonts and other binary files).')->nullable(),
                'content_type' => $schema->string()->max(100)->description('Optional media type; inferred from the file extension when omitted.')->nullable(),
            ]))->max(self::MAX_BUNDLE_FILES)->description('A multi-file bundle (up to 500 files, 8 MB in total) in place of `html`. For markdown, include a `.md`/`.markdown` entrypoint such as `README.md`. Pages reference other files by relative path.')->nullable(),
            'entrypoint' => $schema->string()->max(255)->description('Path of the file people open first; defaults to `index.html`. Must be one of `files`. Use `README.md` (or another `.md`/`.markdown` path) to publish markdown.')->nullable(),
            'artifact_id' => $schema->string()->max(128)->description('To update an artifact you published before, pass its id; this publishes a new version with the same link instead of a new artifact. Omitted title, description, sharing, edit_access and collection keep their previous values.')->nullable(),
            'artifact_type' => $schema->string()->enum(ArtifactType::values())->description('What this is: report, table, document, mockup, slide_deck, dashboard or other. Optional; inferred from the content when omitted, and a later version keeps a type you declared.')->nullable(),
            'base_version' => $schema->integer()->min(1)->description('Optional. With `artifact_id`: the version your change is built on (from get_artifact or read_artifact). When omitted, the publish overwrites without a conflict check and the response sets base_version_checked to false. When given and the artifact has moved on, nothing is published and a stale_base error returns live_version so you can read it, merge and publish again. A base_version ahead of the live version is rejected as invalid_request.')->nullable(),
            'sharing' => $schema->string()->enum(['private', 'team', 'public'])->description('Who can open it: team (default, everyone on your team), private (only you and team admins) or public (anyone with the link). A version published with `artifact_id` never changes sharing.')->nullable(),
            'edit_access' => $schema->string()->enum(['view', 'edit'])->description('Whether the people it is shared with can publish new versions. Defaults to view. A version published with `artifact_id` never changes it.')->nullable(),
            'tier' => $schema->string()->enum(['public', 'secure'])->description('Deprecated alias for sharing on input only; use sharing. Not returned in the response.')->nullable(),
            'title' => $schema->string()->max(200)->description('Optional title; defaults to the document title on create. On a version publish, omit to keep the previous title.')->nullable(),
            'description' => $schema->string()->max(1000)->description('Optional summary used in search results. On a version publish, omit to keep the previous description.')->nullable(),
            'model' => $schema->string()->max(100)->description('Optional model name for provenance.')->nullable(),
            'collection' => $schema->string()->max(100)->description('Optional workspace collection name or numeric id. On a new publish, adds the artifact to that collection (requires collections:write). Ignored when publishing a new version with `artifact_id` (membership is kept).')->nullable(),
            'db' => $schema->object()->description('Optional database manifest: `collections` maps names to `{index}` declarations and `seed` lists `{collection, doc_id, body}` rows written once on first deploy (requires the artifact-db feature). On a version publish, explicit null removes the endpoint; omitting keeps the current manifest.')->nullable(),
        ];
    }

    /**
     * Normalize the `db` argument into the Worker manifest shape, or an
     * error message when it is malformed. Collections declare an optional
     * `index` field; seed rows are written once on first deploy and are
     * never copied from the source artifact's live rows on pull.
     *
     * @param  array<string, mixed>  $db
     * @return array<string, mixed>|string
     */
    private function dbManifest(array $db): array|string
    {
        $manifest = [];

        $collections = $db['collections'] ?? null;
        if ($collections !== null) {
            if (! is_array($collections)) {
                return '`db.collections` must be an object mapping collection names to their declaration.';
            }
            $declared = [];
            foreach ($collections as $name => $declaration) {
                if (! is_string($name) || preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $name) !== 1) {
                    return 'Collection names must be 1-64 characters of letters, numbers, dot, underscore or dash.';
                }
                if (! is_array($declaration)) {
                    return "The declaration for collection [{$name}] must be an object.";
                }
                $index = $declaration['index'] ?? null;
                if ($index !== null && (! is_string($index) || preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $index) !== 1)) {
                    return "The index for collection [{$name}] must be 1-64 characters of letters, numbers, dot, underscore or dash.";
                }
                $declared[$name] = $index === null ? [] : ['index' => $index];
            }
            $manifest['collections'] = $declared;
        }

        $seed = $db['seed'] ?? null;
        if ($seed !== null) {
            if (! is_array($seed)) {
                return '`db.seed` must be an array of {collection, doc_id, body} rows.';
            }
            foreach ($seed as $row) {
                if (! is_array($row) || strlen((string) json_encode($row['body'] ?? null)) > 65536) {
                    return 'Every `db.seed` row needs a JSON object body of at most 64 KiB.';
                }
            }
            $manifest['seed'] = array_values($seed);
        }

        if ($manifest === []) {
            return 'Pass `db.collections` or `db.seed` (or explicit null to remove the endpoint on a version publish).';
        }

        return $manifest;
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
            // Spec 20 reserves `__artfct/` for the artifact database endpoint on
            // the artifact origin; a bundle path there would shadow the db.
            if (str_starts_with($path, '__artfct/')) {
                return "File path [{$path}] is reserved: paths under `__artfct/` serve the artifact database, so pages cannot bundle files there.";
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
        return ArtifactPublisher::contentTypeFor($path);
    }

    /**
     * @param  array<string, array{path: string, bytes: string, content_type: string, sha256: string}>  $files
     * @return list<string>
     */
    private function contentWarnings(array $files): array
    {
        $warnings = [];
        $joined = implode("\n", array_map(fn (array $file): string => $file['bytes'], $files));

        if (preg_match('/onclick\s*=/i', $joined) === 1) {
            $warnings[] = 'This content includes `onclick=` handlers, which do not run on the served page. Move interactivity into a separate `.js` file in `files`.';
        }

        if (preg_match('/data:image\//i', $joined) === 1) {
            $warnings[] = 'This content includes `data:image` URIs, which are blocked on the served page. Put images in `files` and reference them by relative path.';
        }

        foreach ($files as $file) {
            if (str_contains(strtolower($file['content_type']), 'text/html') && $this->htmlHasInlineScript($file['bytes'])) {
                $warnings[] = 'Inline `<script>` blocks in '.$file['path'].' do not run on the published page: CSP is `script-src \'self\'`. Put the script in its own file (for example `app.js`) and load it with `<script src="app.js"></script>`. See get_guidance topic publish.';

                break;
            }
        }

        return $warnings;
    }

    /** True when the HTML has a `<script>` element without a `src` attribute. */
    private function htmlHasInlineScript(string $html): bool
    {
        if (preg_match_all('/<script\b([^>]*)>/i', $html, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $attributes) {
            if (preg_match('/\bsrc\s*=/i', $attributes) !== 1) {
                return true;
            }
        }

        return false;
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

    /** @return array<string, mixed> */
    private function provenance(Request $request, ?string $model): array
    {
        $sessionId = $request->sessionId();
        [$agent, $agentVersion, $agentSource] = self::publishingAgent();

        return [
            'agent' => $agent,
            'agent_raw' => $agent,
            'agent_version' => $agentVersion,
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
                'agent' => $agentSource,
                'agent_raw' => $agentSource,
                'agent_version' => $agentVersion === null ? 'absent' : $agentSource,
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
     * The agent recorded as the publisher. An agent token's name is set by a
     * workspace admin and authenticated, so it wins; the self-reported MCP
     * client identity is only the fallback when the credential names no agent.
     *
     * @return array{0: ?string, 1: ?string, 2: string} agent, version, provenance source
     */
    public static function publishingAgent(): array
    {
        $credentialName = McpContext::agentName();

        if ($credentialName !== null) {
            return [$credentialName, null, 'credential'];
        }

        return self::clientAgent();
    }

    /**
     * Prefer the hosted connection registry (filled from initialize clientInfo),
     * then the per-request MCP client headers.
     *
     * @return array{0: ?string, 1: ?string, 2: string} agent, version, provenance source
     */
    private static function clientAgent(): array
    {
        $http = McpContext::httpRequest();
        $connection = $http->attributes->get('mcp_connection');

        if ($connection instanceof McpConnection) {
            $name = filled($connection->client_name) ? (string) $connection->client_name : null;
            $version = filled($connection->client_version) ? (string) $connection->client_version : null;

            if ($name !== null) {
                return [$name, $version, 'client_info'];
            }
        }

        $headerName = $http->header('MCP-Client-Name');
        $headerVersion = $http->header('MCP-Client-Version');
        $name = is_string($headerName) && trim($headerName) !== '' ? trim($headerName) : null;
        $version = is_string($headerVersion) && trim($headerVersion) !== '' ? trim($headerVersion) : null;

        if ($name !== null) {
            return [$name, $version, 'client_info'];
        }

        return [null, null, 'absent'];
    }
}
