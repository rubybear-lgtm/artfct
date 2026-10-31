<?php

namespace App\Mcp\Support;

use Carbon\CarbonInterface;

/**
 * One ISO 8601 shape for every MCP response timestamp: UTC with a Z suffix
 * and no fractional seconds (RUB-515 item 14).
 */
final class McpTimestamp
{
    public static function format(?CarbonInterface $dt): ?string
    {
        if ($dt === null) {
            return null;
        }

        return $dt->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
