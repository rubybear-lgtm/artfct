<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactType;
use App\Models\Collection;
use App\Models\Team;

/**
 * The explicit inputs to {@see ArtifactPublisher::publish()}. Everything the
 * publish core needs — team, actor, credential — is carried here, never read
 * from `McpContext`, so the MCP tool and the template puller (library or MCP)
 * publish through the same path.
 */
final class ArtifactPublish
{
    /**
     * @param  array<string, array{path: string, bytes: string, content_type: string, sha256: string}>  $files  keyed by path
     * @param  array<string, mixed>  $provenance  Worker provenance payload, built by the caller
     * @param  array<string, mixed>|null  $db  Worker-shape db manifest fragment, or null to turn the endpoint off
     */
    public function __construct(
        public Team $team,
        public string $actor,
        public string $credentialToken,
        public array $files,
        public string $entrypoint,
        public int $startedAt,
        public string $title,
        public bool $titleProvided,
        public ?string $description,
        public bool $descriptionProvided,
        public string $sharing = 'team',
        public string $editAccess = 'view',
        public ?string $artifactId = null,
        public ?int $baseVersion = null,
        public ?Collection $collection = null,
        public array $provenance = [],
        public ?ArtifactType $declaredType = null,
        public string $source = 'mcp',
        public string $tool = 'deploy_artifact',
        public string $ip = '127.0.0.1',
        public string $userAgent = 'unknown',
        public ?string $clientName = null,
        public bool $recordTelemetry = true,
        public ?array $db = null,
        public bool $dbGiven = false,
    ) {}
}
