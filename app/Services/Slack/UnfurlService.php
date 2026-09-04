<?php

namespace App\Services\Slack;

use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Models\Team;
use App\Services\Governance\AuditLogger;

/**
 * spec 15: "A Slack unfurl renders to everyone in the channel — including
 * guests and people outside the artifact's share scope." Richness is
 * therefore a pure function of the artifact's own sharing level, never of
 * who happens to paste the link or who's currently viewing — Slack's
 * unfurl is fetched and cached once per URL, not per viewer, so there is
 * no "insider" bypass to build in the first place.
 */
final class UnfurlService
{
    public function __construct(
        private readonly ArtifactSharingContract $sharing,
        private readonly ArtifactDirectory $artifacts,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function unfurl(Team $team, string $artifactId): UnfurlResult
    {
        $level = $this->sharing->sharingLevelFor($team->slug, $artifactId);

        $result = match ($level) {
            null, SharingLevel::OrgPrivate => UnfurlResult::bare(),
            SharingLevel::DomainRestricted => $this->titleOnly($team, $artifactId),
            SharingLevel::Public => $this->fullMetadata($team, $artifactId),
        };

        $this->auditLogger->record(
            AuditEventType::ArtifactViewed,
            $team,
            'slack:unfurl',
            $artifactId,
            'internal',
            'slack-unfurl',
            $result->bareCard ? 'bare_card' : 'unfurled',
        );

        return $result;
    }

    private function titleOnly(Team $team, string $artifactId): UnfurlResult
    {
        $artifact = $this->findArtifact($team, $artifactId);

        return $artifact === null ? UnfurlResult::bare() : UnfurlResult::titleOnly($artifact['title'] ?? $artifactId);
    }

    private function fullMetadata(Team $team, string $artifactId): UnfurlResult
    {
        $artifact = $this->findArtifact($team, $artifactId);
        if ($artifact === null) {
            return UnfurlResult::bare();
        }

        $provenance = $artifact['provenance'] ?? [];
        $summary = trim(($provenance['agent'] ?? '').(isset($provenance['repo_url']) ? " · {$provenance['repo_url']}" : ''));

        return UnfurlResult::full(
            title: $artifact['title'] ?? $artifactId,
            description: $artifact['description'] ?? null,
            thumbnail: $artifact['thumbnail'] ?? null,
            provenanceSummary: $summary !== '' ? $summary : null,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findArtifact(Team $team, string $artifactId): ?array
    {
        $page = $this->artifacts->listArtifacts($team->slug, [], null, 1000);
        foreach ($page['artifacts'] as $artifact) {
            if ($artifact['id'] === $artifactId) {
                return $artifact;
            }
        }

        return null;
    }
}
