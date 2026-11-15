<?php

namespace App\Services\Artifacts;

use App\Enums\AuditEventType;
use App\Mcp\Support\McpTelemetry;
use App\Mcp\Support\McpTimestamp;
use App\Models\Collection;
use App\Models\User;
use App\Services\Analytics\ProductAnalytics;
use App\Services\Billing\BundleTooLargeException;
use App\Services\Billing\DailyPublishLimiter;
use App\Services\Billing\QuotaExceededException;
use App\Services\Billing\QuotaService;
use App\Services\Collections\CollectionService;
use App\Services\Governance\AuditLogger;
use App\Services\Indexing\ExtractionHeuristics;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The publish core shared by `deploy_artifact` and template pulls (spec 21).
 * Takes an explicit team, actor and Worker credential instead of reading
 * `McpContext`, so a library pull with no MCP connection publishes through
 * the same path: quota check, daily publish limit, the Worker create and file
 * uploads, audit event, analytics event.
 *
 * Returns the Worker-reported fields; the caller builds its own link
 * (`view_url`), because an MCP caller and a browser session link differently.
 * Failures throw {@see PublishFailedException} carrying the machine-readable
 * error the caller renders in its own shape.
 */
final class ArtifactPublisher
{
    public function __construct(
        private readonly QuotaService $quota,
        private readonly DailyPublishLimiter $daily,
        private readonly CollectionService $collections,
        private readonly ArtifactTypeResolver $types,
        private readonly AuditLogger $audit,
        private readonly ProductAnalytics $analytics,
        private readonly McpTelemetry $telemetry,
    ) {}

    /**
     * @return array{id: string, artifact_type: string, tier: mixed, url: mixed, sharing: ?string, version: int, created: bool, title: string, organization: string, collection_id?: int, collection?: string}
     *
     * @throws PublishFailedException
     */
    public function publish(ArtifactPublish $command): array
    {
        $team = $command->team;
        $actor = $command->actor;
        $files = $command->files;
        $entrypoint = $command->entrypoint;
        $versionPublish = $command->artifactId !== null;
        $artifactIdInput = $command->artifactId;

        $totalBytes = 0;
        foreach ($files as $file) {
            $totalBytes += strlen($file['bytes']);
        }
        $entryBytes = $files[$entrypoint]['bytes'];
        $typeSourceBytes = $entryBytes;

        // A path-sorted fingerprint so identical bundles match regardless of
        // input order, without reordering the Worker manifest.
        $filesForFingerprint = $files;
        ksort($filesForFingerprint);
        $contentFingerprint = hash('sha256', implode("\0", array_map(
            fn (array $file): string => $file['path'].':'.$file['sha256'],
            $filesForFingerprint,
        )));

        try {
            $this->quota->assertCanCreateArtifact($team, $totalBytes);
            $this->daily->assertCanPublish($team, $actor);
        } catch (QuotaExceededException $exception) {
            $this->fail($command, $exception->errorCode, $exception->getMessage().' Use get_usage to inspect current limits and usage, then retry after remediation.', false, 'get_usage', $exception->details);
        } catch (BundleTooLargeException $exception) {
            $this->fail($command, $exception->errorCode, $exception->getMessage().' Use get_usage to inspect current limits and usage, then retry after remediation.', false, 'get_usage');
        } catch (RequestException|ConnectionException|RuntimeException) {
            $this->fail($command, 'upstream_unavailable', 'The artifact service is temporarily unavailable.', true);
        }

        $workerBaseUrl = config('services.worker.base_url');
        if (! is_string($workerBaseUrl) || $workerBaseUrl === '') {
            $this->fail($command, 'configuration_error', 'The artifact service is not configured.');
        }
        $baseUrl = rtrim($workerBaseUrl, '/');
        $token = $command->credentialToken;
        $title = $command->title;
        $description = $command->description;

        if ($command->baseVersion !== null) {
            $this->checkBaseVersion($command, $baseUrl, $token);
        }

        try {
            $payload = [
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
                'provenance' => $command->provenance,
            ];

            // A db-backed artifact declares its collections (and, on a pull,
            // its seed rows) in the manifest; the Worker seeds once per new
            // artifact id and never copies live rows. `dbGiven` distinguishes
            // omitted (keep the Worker's declaration) from explicit null
            // (remove the endpoint).
            if ($command->dbGiven) {
                $payload['manifest']['db'] = $command->db;
            }

            // Version publishes omit title/description unless the caller sent
            // them, so a previous description is not wiped by a title fallback.
            if (! $versionPublish || $command->titleProvided) {
                $payload['title'] = $title;
            }
            if (! $versionPublish || $command->descriptionProvided) {
                $payload['description'] = $description;
            }

            // A new version keeps the artifact's existing sharing, so those
            // fields are deliberately absent from a version request.
            if (! $versionPublish) {
                $payload = [
                    'mode' => 'permanent',
                    // `tier` is the deprecated alias: the tier sent always
                    // matches the sharing so an older Worker that only knows
                    // `tier` still accepts it.
                    'tier' => $this->tierForSharing($command->sharing),
                    'sharing' => $command->sharing,
                    'edit_access' => $command->editAccess,
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
                        $this->fail($command, 'deployment_rejected', 'The artifact service could not store the artifact content.', true, null, [], (string) $created->json('id'));
                    }
                }
            }
        } catch (PublishFailedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->fail($command, 'upstream_unavailable', 'The artifact service is temporarily unavailable.', true, null, [], $artifactIdInput);
        }

        /** @var Response $created */
        // The Worker names the accepted artifact's id, so a later failure can
        // clean the pending artifact up; refusals name none.
        $partialId = $created->json('id');
        $partialId = is_string($partialId) && $partialId !== '' ? $partialId : null;

        if ($created->status() === 429) {
            $this->fail($command, 'rate_limited', 'The workspace is publishing too quickly. Retry shortly.', true, null, [], $partialId);
        }

        if ($created->status() === 403 && $created->json('error.code') === 'quota_exceeded') {
            $this->fail($command, 'quota_exceeded', 'This workspace is over its plan limits. Use get_usage to inspect them.', false, 'get_usage', [], $partialId);
        }

        if ($created->status() === 403 && $created->json('error.code') === 'public_sharing_disabled') {
            $this->fail($command, 'public_sharing_disabled', 'Your team has turned off public links. Publish it with sharing set to team or private instead.', false, null, [], $partialId);
        }

        if ($versionPublish && $created->status() === 403 && $created->json('error.code') === 'forbidden') {
            $this->fail($command, 'edit_forbidden', 'You can only publish new versions of artifacts you own or that are shared with you for editing. Publish it as a new artifact instead by calling deploy_artifact without artifact_id.', false, null, [], $artifactIdInput);
        }

        if ($versionPublish && $created->status() === 404) {
            $this->fail($command, 'artifact_not_found', 'That artifact was not found in the authenticated workspace.', false, null, [], $artifactIdInput);
        }

        if ($versionPublish && $created->status() === 409) {
            $this->fail($command, 'version_conflict', 'Another version was being published at the same time. Retry the deployment.', true, null, [], $artifactIdInput);
        }

        if (! $created->successful()) {
            $this->fail($command, 'deployment_rejected', 'The artifact service could not accept this deployment.', false, null, [], $partialId);
        }

        $artifactId = (string) $created->json('id');
        $tier = $created->json('tier');
        $version = (int) $created->json('version');
        // Product `created` means "brand-new artifact", not "Worker inserted a
        // version row". Version publishes are always created:false (RUB-516),
        // even when a legacy Worker still reports created:true on 201.
        $wasCreated = ! $versionPublish;

        $this->telemetry($command, 'success', $artifactId);
        $this->audit->record(
            AuditEventType::ArtifactDeployed,
            $team,
            $actor,
            // A version publish is audited against the version, so the log
            // records which publish happened rather than only the artifact.
            $versionPublish ? "artifact:{$artifactId} version:{$version}" : "artifact:{$artifactId}",
            $command->ip,
            $command->userAgent,
        );

        $actorUser = ctype_digit((string) $actor) ? User::query()->find((int) $actor) : null;
        $this->analytics->artifactShared(
            $actorUser ?? (string) $actor,
            $team,
            $command->source,
            [
                'artifact_id' => $artifactId,
                'version' => $version,
                'created' => $wasCreated,
                'mcp_client_name' => $command->clientName,
            ],
        );

        // Daily quota: new artifacts always count. Version publishes count only
        // when the Worker wrote a new version (HTTP 201); identical-content
        // no-ops return 200 and must not burn the cap.
        $countsTowardDaily = ! $versionPublish || $created->status() === 201;
        $this->daily->recordPublish($team, $actor, $contentFingerprint, $countsTowardDaily);

        $artifactType = $this->types->record($team, $artifactId, $typeSourceBytes, $command->declaredType);

        $result = [
            'id' => $artifactId,
            'artifact_type' => $artifactType->value,
            'tier' => $tier,
            'url' => $created->json('url'),
            'sharing' => $this->sharingFromResponse($created->json('sharing'), is_string($tier) ? $tier : null),
            'version' => $version,
            'created' => $wasCreated,
            'title' => $title,
            'organization' => $team->slug,
        ];

        if ($command->collection instanceof Collection) {
            try {
                $this->collections->addArtifact($command->collection, $artifactId);
            } catch (\Throwable $exception) {
                report($exception);
                $this->fail($command, 'collection_mutation_failed', 'The artifact was published, but it could not be added to that collection. Retry with add_collection_artifact.', true, 'add_collection_artifact', ['id' => $artifactId, 'collection_id' => $command->collection->id], $artifactId);
            }

            $result['collection_id'] = $command->collection->id;
            $result['collection'] = $command->collection->name;
        }

        return $result;
    }

    /**
     * Read-before-write: refuse when the live version moved on since the
     * caller read it, so a stale copy cannot silently replace newer work.
     *
     * @throws PublishFailedException
     */
    private function checkBaseVersion(ArtifactPublish $command, string $baseUrl, string $token): void
    {
        $artifactIdInput = $command->artifactId;
        $baseVersion = $command->baseVersion;

        try {
            $live = Http::withToken($token)->get($baseUrl.'/v1/artifacts/'.$artifactIdInput);
        } catch (\Throwable $exception) {
            report($exception);
            $this->fail($command, 'upstream_unavailable', 'The artifact service is temporarily unavailable.', true, null, [], $artifactIdInput);
        }

        /** @var Response $live */
        if (in_array($live->status(), [403, 404], true)) {
            $this->fail($command, 'artifact_not_found', 'That artifact was not found in the authenticated workspace.', false, null, [], $artifactIdInput);
        }

        if (! $live->successful()) {
            $this->fail($command, 'deployment_rejected', 'The artifact service could not check the current version.', true, null, [], $artifactIdInput);
        }

        $liveVersion = (int) $live->json('version');
        if ($baseVersion > $liveVersion) {
            $this->fail($command, 'invalid_request', "base_version {$baseVersion} is ahead of the live version {$liveVersion}. Read the live version with read_artifact or get_artifact, then publish with that version as base_version.", false, 'get_artifact', ['artifact_id' => $artifactIdInput, 'base_version' => $baseVersion, 'live_version' => $liveVersion], $artifactIdInput);
        }

        if ($liveVersion !== $baseVersion) {
            $this->fail($command, 'stale_base', "This artifact is now at version {$liveVersion}, but your change was built on version {$baseVersion}. Read the live version with read_artifact, merge your changes into it, then publish again with base_version {$liveVersion}.", false, 'read_artifact', [
                'artifact_id' => $artifactIdInput,
                'base_version' => $baseVersion,
                'live_version' => $liveVersion,
                'current_version' => $liveVersion,
                'updated_at' => McpTimestamp::format($live->json('updated_at')),
            ], $artifactIdInput);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     *
     * @throws PublishFailedException
     */
    private function fail(ArtifactPublish $command, string $code, string $message, bool $retryable = false, ?string $nextAction = null, array $details = [], ?string $artifactId = null): never
    {
        if ($code !== 'configuration_error') {
            $outcome = match ($code) {
                'upstream_unavailable', 'deployment_rejected' => 'error',
                default => $code,
            };
            $this->telemetry($command, $outcome, $artifactId);
        }

        throw new PublishFailedException($message, $code, $retryable, $nextAction, $details, $artifactId);
    }

    private function telemetry(ArtifactPublish $command, string $outcome, ?string $artifactId): void
    {
        if (! $command->recordTelemetry) {
            return;
        }

        $this->telemetry->record($command->tool, $outcome, $command->startedAt, $artifactId);
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
        if (is_string($sharing) && $sharing !== '') {
            return $sharing;
        }

        return match ($tier) {
            'secure' => 'team',
            'public' => 'public',
            'private' => 'private',
            default => null,
        };
    }

    public static function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) === 1) {
            $title = trim(strip_tags($matches[1]));

            if ($title !== '') {
                return Str::limit($title, 200, '');
            }
        }

        $heading = ExtractionHeuristics::titleFromHtml($html);
        if ($heading !== null) {
            return Str::limit($heading, 200, '');
        }

        return 'Untitled artifact';
    }

    /** The media type for a bundle path, inferred from its extension. */
    public static function contentTypeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html; charset=utf-8',
            'md', 'markdown' => 'text/markdown; charset=utf-8',
            'css' => 'text/css',
            'js', 'mjs' => 'text/javascript',
            'json' => 'application/json',
            'wasm' => 'application/wasm',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => 'application/octet-stream',
        };
    }
}
