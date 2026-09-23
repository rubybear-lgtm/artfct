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
            self::ArtifactsRead => 'Read artifacts and search your workspace',
            self::ArtifactsDeploy => 'Deploy artifacts to your workspace',
            self::ArtifactsDelete => 'Delete artifacts from your workspace',
            self::CollectionsRead => 'View approved artifact collections',
            self::CollectionsWrite => 'Create collections and add artifacts',
            self::UsageRead => 'View usage and quota totals',
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
