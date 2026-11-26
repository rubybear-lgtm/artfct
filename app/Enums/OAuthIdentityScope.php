<?php

namespace App\Enums;

/**
 * OIDC identity scopes advertised by the authorization server for OpenAI's
 * MCP-plugin auth contract. Kept outside {@see McpScope} so they never enter
 * default JWT claims, org-token validation, bot teammates, or console scope
 * pickers — only AuthorizationServerController accepts and renders them.
 */
enum OAuthIdentityScope: string
{
    case OpenId = 'openid';
    case Email = 'email';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * MCP scopes first, then identity scopes — the order both metadata
     * documents advertise.
     *
     * @return list<string>
     */
    public static function withMcpScopes(): array
    {
        return [...McpScope::values(), ...self::values()];
    }

    public static function isIdentity(string $scope): bool
    {
        return self::tryFrom($scope) !== null;
    }

    public function label(): string
    {
        return match ($this) {
            self::OpenId => 'Confirm who you are',
            self::Email => 'See your email address',
        };
    }

    public function risk(): McpScopeRisk
    {
        return McpScopeRisk::Read;
    }
}
