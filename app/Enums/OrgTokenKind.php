<?php

namespace App\Enums;

/**
 * Why an org token was minted. Agent tokens are meant for MCP clients that
 * send a static Bearer header (bots, custom connectors); API tokens remain
 * for REST/CI. Both share the same JWT and revocation path.
 */
enum OrgTokenKind: string
{
    case Api = 'api';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'API',
            self::Agent => 'Agent',
        };
    }

    public function isAgent(): bool
    {
        return $this === self::Agent;
    }
}
