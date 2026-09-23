<?php

namespace App\Console\Commands;

use App\Models\McpActivity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('mcp:prune-activity {--days= : Retain activity for this many days}')]
#[Description('Removes MCP activity records older than the configured retention window')]
class PruneMcpActivityCommand extends Command
{
    public function handle(): int
    {
        $option = $this->option('days');
        $days = $option === null
            ? (int) config('auth.mcp_activity_retention_days', 90)
            : filter_var($option, FILTER_VALIDATE_INT);

        if (! is_int($days) || $days < 1 || $days > 3650) {
            $this->components->error('The retention window must be between 1 and 3650 days.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $deleted = 0;

        McpActivity::query()
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(1000, function ($activities) use (&$deleted): void {
                $deleted += McpActivity::query()->whereKey($activities->modelKeys())->delete();
            });

        $this->components->info("Pruned {$deleted} MCP activity record(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
