<?php

namespace App\Services\Templates;

use App\Models\Team;

/**
 * A pull request: copy an Artfct-authored template into the caller's team
 * as a new, independent artifact (spec 21). The pull runs as the caller:
 * team, actor and credential are explicit, so MCP and library pulls share
 * the path.
 */
final class TemplatePull
{
    /**
     * @param  list<string>  $scopes  the caller's granted scopes (role-derived for library pulls)
     */
    public function __construct(
        public Team $team,
        public string $actor,
        public string $credentialToken,
        public string $templateKey,
        public int $startedAt,
        public array $scopes = [],
        public ?string $title = null,
        public ?string $sharing = null,
        public ?string $collection = null,
        public ?string $requestId = null,
        public ?string $agent = null,
        public ?string $agentVersion = null,
        public string $agentSource = 'absent',
        public ?string $sessionId = null,
        public ?string $clientName = null,
        public string $source = 'mcp',
        public string $ip = '127.0.0.1',
        public string $userAgent = 'unknown',
        public bool $recordTelemetry = true,
    ) {}
}
