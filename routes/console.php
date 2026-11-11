<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bounds how long a dropped org-limits push can leave the Worker on stale limits (RUB-310).
Schedule::command('billing:sync-limits')->hourly();

// Per-seat sync retired with flat Team pricing (RUB-529). Command remains a no-op.
Schedule::command('billing:sync-seats')->daily();

// Local safety net if Stripe's subscription.deleted for a trial is delayed.
Schedule::command('billing:expire-trials')->hourly()->withoutOverlapping();

// Keeps customer-facing MCP activity bounded without affecting live calls.
Schedule::command('mcp:prune-activity')->dailyAt('02:15')->withoutOverlapping();

// Retries edge denylist writes persisted during OAuth refresh-token replay.
Schedule::command('app:retry-org-token-revocations')->everyMinute()->withoutOverlapping();

// Writes events withheld by an expired or released suppression case to the tenant's audit log (spec 17).
Schedule::command('ops:release-suppressed')->hourly()->withoutOverlapping();
