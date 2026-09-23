<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bounds how long a dropped org-limits push can leave the Worker on stale limits (RUB-310).
Schedule::command('billing:sync-limits')->hourly();

// Bills teammates: reconcile Stripe seat quantities with active members daily (RUB-342).
Schedule::command('billing:sync-seats')->daily();

// Keeps customer-facing MCP activity bounded without affecting live calls.
Schedule::command('mcp:prune-activity')->dailyAt('02:15')->withoutOverlapping();
