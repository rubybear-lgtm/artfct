<?php

namespace App\Enums;

/**
 * The scopes an MCP client can request. This is the server's only scope
 * definition: the authorization server advertises this list, gates role
 * access against it, and renders the consent screen from it, so a scope
 * cannot be added without also declaring the wording and the risk level the
 * user is shown.
 */
enum McpScope: string
{
    case ArtifactsRead = 'artifacts:read';
    case ArtifactsDeploy = 'artifacts:deploy';
    case ArtifactsDelete = 'artifacts:delete';
    case CollectionsRead = 'collections:read';
    case CollectionsWrite = 'collections:write';
    case UsageRead = 'usage:read';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::ArtifactsRead => 'Read and search your team\'s shared work',
            self::ArtifactsDeploy => 'Share new work with your team',
            self::ArtifactsDelete => 'Delete shared work',
            self::CollectionsRead => 'See your team\'s collections',
            self::CollectionsWrite => 'Create collections and add work to them',
            self::UsageRead => 'See usage and plan limits',
        };
    }

    public function risk(): McpScopeRisk
    {
        return match ($this) {
            self::ArtifactsRead, self::CollectionsRead, self::UsageRead => McpScopeRisk::Read,
            self::ArtifactsDeploy, self::CollectionsWrite => McpScopeRisk::Write,
            self::ArtifactsDelete => McpScopeRisk::Destructive,
        };
    }
}
