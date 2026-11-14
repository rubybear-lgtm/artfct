<?php

namespace App\Services\Templates;

use App\Enums\ArtifactType;
use App\Models\TemplatePullRecord;
use App\Services\Artifacts\ArtifactPublish;
use App\Services\Artifacts\ArtifactPublisher;
use App\Services\Artifacts\PublishFailedException;
use App\Services\Collections\CollectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Copies an Artfct-authored template into a team's library as a new,
 * independent artifact (spec 21). The pull runs as the caller and publishes
 * through the same path as `deploy_artifact`: the `requires` features must
 * be released, scopes are checked with a reconnect next action, quota and
 * the daily publish limit apply, seed rows are written once, and provenance
 * records `tool: pull_template` with `source_path: template:<key>@<version>`.
 *
 * Idempotency: a client-generated `request_id` (the library dialog mints one
 * when it opens; the MCP tool accepts one) maps to at most one artifact per
 * (team, template). Without a request id every pull makes a new artifact.
 * A pull that fails after the Worker accepted the create removes the
 * pending artifact, so a retry starts clean and nothing counts against quota.
 */
final class TemplatePuller
{
    public function __construct(
        private readonly ArtifactPublisher $publisher,
        private readonly TemplateCatalog $catalog,
        private readonly TeamFeatureGates $gates,
        private readonly CollectionService $collections,
    ) {}

    /**
     * @return array{id: string, template_key: string, template_version: int, title: string, sharing: string, created: bool}
     *
     * @throws PullFailedException
     */
    public function pull(TemplatePull $pull): array
    {
        $template = $this->catalog->find($pull->templateKey);

        if ($template === null) {
            throw new PullFailedException(
                "There is no template [{$pull->templateKey}] in this workspace.",
                'template_not_found',
            );
        }

        foreach ([TeamFeatureGates::ARTIFACT_TEMPLATES, ...$template->requires] as $feature) {
            if (! $this->gates->enabled($feature, $pull->team)) {
                throw new PullFailedException(
                    "The `{$feature}` feature is not enabled for this workspace yet.",
                    'feature_not_enabled',
                );
            }
        }

        $this->requireScope($pull, 'artifacts:deploy');

        if ($template->db !== null) {
            $this->requireScope($pull, 'data:read');
            $this->requireScope($pull, 'data:write');
        }

        $collection = null;
        if ($pull->collection !== null && trim($pull->collection) !== '') {
            $this->requireScope($pull, 'collections:write');
            $collection = $this->collections->findForTeam($pull->team, $pull->collection);

            if ($collection === null) {
                throw new PullFailedException(
                    'That collection was not found in the authenticated workspace. Pass its id or exact name from list_collections, or create it first with create_collection.',
                    'collection_not_found',
                    nextAction: 'list_collections',
                );
            }
        }

        $sharing = $pull->sharing ?? $template->sharing;
        if (! in_array($sharing, ['private', 'team'], true)) {
            throw new PullFailedException(
                'A pulled template can be `private` or `team`; public pulls are not supported.',
                'invalid_request',
            );
        }

        $requestId = $pull->requestId !== null && trim($pull->requestId) !== '' ? trim($pull->requestId) : null;

        TemplatePullRecord::query()
            ->where('created_at', '<', now()->subDay())
            ->delete();

        if ($requestId !== null) {
            $existing = TemplatePullRecord::query()
                ->where('team_id', $pull->team->id)
                ->where('template_key', $template->key)
                ->where('request_id', $requestId)
                ->first();

            if ($existing !== null) {
                return [
                    'id' => $existing->artifact_id,
                    'template_key' => $template->key,
                    'template_version' => $template->version,
                    'title' => $pull->title ?? $template->title,
                    'sharing' => $sharing,
                    'created' => false,
                ];
            }
        }

        $files = [];
        foreach ($template->files as $path => $bytes) {
            $files[$path] = [
                'path' => $path,
                'bytes' => $bytes,
                'content_type' => ArtifactPublisher::contentTypeFor($path),
                'sha256' => hash('sha256', $bytes),
            ];
        }

        $command = new ArtifactPublish(
            team: $pull->team,
            actor: $pull->actor,
            credentialToken: $pull->credentialToken,
            files: $files,
            entrypoint: $template->entrypoint,
            startedAt: $pull->startedAt,
            title: $pull->title ?? $template->title,
            description: $template->description,
            sharing: $sharing,
            editAccess: $template->editAccess,
            collection: $collection,
            provenance: [
                'agent' => $pull->agent,
                'agent_raw' => $pull->agent,
                'agent_version' => $pull->agentVersion,
                'model' => null,
                'session_id' => $pull->sessionId,
                'tool' => 'pull_template',
                'repo_url' => null,
                'branch' => null,
                'commit_sha' => null,
                'dirty' => null,
                'source_path' => "template:{$template->key}@{$template->version}",
                'client' => $pull->source === 'mcp' ? 'artfct-remote-mcp' : 'artfct-library',
                'client_version' => '1.0.0',
                'sources' => [
                    'agent' => $pull->agentSource,
                    'agent_raw' => $pull->agentSource,
                    'agent_version' => $pull->agentVersion === null ? 'absent' : $pull->agentSource,
                    'model' => 'absent',
                    'session_id' => $pull->sessionId === null ? 'absent' : 'process',
                    'tool' => 'config',
                    'repo_url' => 'absent',
                    'branch' => 'absent',
                    'commit_sha' => 'absent',
                    'dirty' => 'absent',
                    'source_path' => 'config',
                ],
            ],
            declaredType: ArtifactType::tryFrom($template->artifactType),
            source: $pull->source,
            tool: 'pull_template',
            ip: $pull->ip,
            userAgent: $pull->userAgent,
            clientName: $pull->clientName,
            recordTelemetry: $pull->recordTelemetry,
            db: $template->db,
            dbGiven: $template->db !== null,
        );

        $lock = $requestId !== null
            ? Cache::lock("template-pull:{$pull->team->id}:{$template->key}:{$requestId}", 60)
            : null;

        try {
            $lock?->block(10);

            if ($requestId !== null) {
                $existing = TemplatePullRecord::query()
                    ->where('team_id', $pull->team->id)
                    ->where('template_key', $template->key)
                    ->where('request_id', $requestId)
                    ->first();

                if ($existing !== null) {
                    return [
                        'id' => $existing->artifact_id,
                        'template_key' => $template->key,
                        'template_version' => $template->version,
                        'title' => $command->title ?? $template->title,
                        'sharing' => $sharing,
                        'created' => false,
                    ];
                }
            }

            $published = $this->publisher->publish($command);

            if ($requestId !== null) {
                TemplatePullRecord::query()->create([
                    'team_id' => $pull->team->id,
                    'template_key' => $template->key,
                    'request_id' => $requestId,
                    'artifact_id' => $published['id'],
                ]);
            }

            return [
                'id' => $published['id'],
                'template_key' => $template->key,
                'template_version' => $template->version,
                'title' => $published['title'],
                'sharing' => $published['sharing'],
                'created' => true,
            ];
        } catch (PublishFailedException $exception) {
            // A pull that fails after the Worker accepted the create removes
            // the pending artifact, so a retry starts clean and nothing
            // counts against the org's quota (the daily slot is only recorded
            // on success, inside the publisher).
            if ($exception->artifactId !== null && $exception->artifactId !== '') {
                $this->deletePendingArtifact($pull, $exception->artifactId);
            }

            throw new PullFailedException(
                $exception->getMessage(),
                $exception->errorCode,
                $exception->retryable,
                $exception->nextAction,
                $exception->details,
                telemetryRecorded: true,
            );
        } finally {
            $lock?->release();
        }
    }

    /** @throws PullFailedException */
    private function requireScope(TemplatePull $pull, string $scope): void
    {
        if (! in_array($scope, $pull->scopes, true)) {
            throw new PullFailedException(
                "insufficient_scope: The caller requires the {$scope} scope.",
                'insufficient_scope',
                nextAction: 'reconnect',
            );
        }
    }

    /**
     * Best-effort removal of a half-pulled artifact. Telemetry and audit for
     * the failure itself were already recorded by the publisher; a cleanup
     * failure must never mask the original error.
     */
    private function deletePendingArtifact(TemplatePull $pull, string $artifactId): void
    {
        try {
            $workerBaseUrl = config('services.worker.base_url');

            if (! is_string($workerBaseUrl) || $workerBaseUrl === '') {
                return;
            }

            Http::withToken($pull->credentialToken)
                ->delete(rtrim($workerBaseUrl, '/').'/v1/artifacts/'.$artifactId);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
