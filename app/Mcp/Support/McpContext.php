<?php

namespace App\Mcp\Support;

use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request as HttpRequest;

final class McpContext
{
    public static function team(): Team
    {
        /** @var Team|null $team */
        $team = request()->attributes->get('org_jwt_team');

        if (! $team instanceof Team) {
            throw new AuthorizationException('The MCP connection is not associated with a workspace.');
        }

        return $team;
    }

    /** @return array{jti: string, user_id: string, role: string, scope?: string} */
    public static function claims(): array
    {
        /** @var array{jti: string, user_id: string, role: string, scope?: string}|null $claims */
        $claims = request()->attributes->get('org_jwt_claims');

        if (! is_array($claims)) {
            throw new AuthorizationException('The MCP connection is not authenticated.');
        }

        return $claims;
    }

    public static function requireScope(string $scope, ?string $tool = null): void
    {
        $claims = self::claims();
        $scopes = preg_split('/\s+/', trim((string) ($claims['scope'] ?? '')));

        if (! in_array($scope, $scopes ?: [], true)) {
            if ($tool !== null) {
                app(McpTelemetry::class)->record($tool, 'denied', hrtime(true));
            }

            throw new AuthorizationException(
                "insufficient_scope: The MCP connection requires the {$scope} scope.",
            );
        }
    }

    public static function actor(): string
    {
        return self::claims()['user_id'];
    }

    public static function httpRequest(): HttpRequest
    {
        /** @var HttpRequest $request */
        $request = request();

        return $request;
    }
}
