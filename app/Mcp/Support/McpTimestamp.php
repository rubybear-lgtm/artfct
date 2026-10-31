<?php

namespace App\Mcp\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * One ISO 8601 shape for every MCP response timestamp: UTC with a Z suffix
 * and no fractional seconds (RUB-515 item 14).
 */
final class McpTimestamp
{
    /** Matches every timestamp this helper emits. */
    public const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    public static function format(CarbonInterface|DateTimeInterface|string|null $dt): ?string
    {
        if ($dt === null || $dt === '') {
            return null;
        }

        $carbon = $dt instanceof CarbonInterface
            ? $dt
            : Carbon::parse($dt);

        return $carbon->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
