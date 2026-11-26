<?php

namespace App\Enums;

/**
 * The MCP scopes a client can request. The authorization server advertises
 * these plus the separate {@see OAuthIdentityScope} list, gates role access
 * against this set for tool grants, and renders consent wording from both.
 * Identity scopes stay outside this enum so they never enter default JWT
 * claims or console scope pickers.
 */
enum McpScope: string
{
    case ArtifactsRead = 'artifacts:read';
    case ArtifactsDeploy = 'artifacts:deploy';
    case ArtifactsDelete = 'artifacts:delete';
    case CommentsWrite = 'comments:write';
    case CollectionsRead = 'collections:read';
    case CollectionsWrite = 'collections:write';
    case UsageRead = 'usage:read';
    case DataRead = 'data:read';
    case DataWrite = 'data:write';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * Scopes a team role may grant on OAuth consent, MCP connections, or
     * agent tokens. Viewers are read-only; everyone else may grant the full set.
     *
     * @return list<string>
     */
    public static function forRole(?TeamRole $role): array
    {
        return match ($role) {
            TeamRole::Admin, TeamRole::Member => self::values(),
            default => [
                self::ArtifactsRead->value,
                self::CollectionsRead->value,
                self::UsageRead->value,
                self::DataRead->value,
            ],
        };
    }

    /**
     * Space-joined scope claim used when a JWT omits an explicit scope list
     * and the middleware fills in the role default.
     */
    public static function claimForRole(?TeamRole $role): string
    {
        return implode(' ', self::forRole($role));
    }

    public function label(): string
    {
        return match ($this) {
            self::ArtifactsRead => 'Read and search your team\'s shared work',
            self::ArtifactsDeploy => 'Share new work with your team',
            self::ArtifactsDelete => 'Delete shared work',
            self::CommentsWrite => 'Comment on shared work',
            self::CollectionsRead => 'See your team\'s collections',
            self::CollectionsWrite => 'Create collections and add work to them',
            self::UsageRead => 'See workspace limits',
            self::DataRead => 'Read the data kept inside your team\'s shared work',
            self::DataWrite => 'Change the data kept inside your team\'s shared work',
        };
    }

    public function risk(): McpScopeRisk
    {
        return match ($this) {
            self::ArtifactsRead, self::CollectionsRead, self::UsageRead, self::DataRead => McpScopeRisk::Read,
            self::ArtifactsDeploy, self::CollectionsWrite, self::CommentsWrite, self::DataWrite => McpScopeRisk::Write,
            self::ArtifactsDelete => McpScopeRisk::Destructive,
        };
    }
}
