<?php

namespace App\Console\Commands;

use App\Enums\AuditEventType;
use App\Models\Team;
use App\Services\Billing\BillingService;
use App\Services\Governance\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * RUB-342: reconciles each subscribed team's Stripe seat quantity with its
 * active member count. Idempotent; one team's failure does not stop the rest.
 */
#[Signature('billing:sync-seats {org? : The team slug; omit to sync every subscribed team}')]
#[Description('Syncs Stripe seat quantities with active team members')]
class BillingSyncSeatsCommand extends Command
{
    public function handle(BillingService $billing, AuditLogger $audit): int
    {
        $slug = $this->argument('org');
        $teams = Team::query()
            ->whereNotNull('stripe_subscription_id')
            ->when(is_string($slug), fn ($query) => $query->where('slug', $slug))
            ->get();

        $failed = 0;
        foreach ($teams as $team) {
            try {
                if ($billing->syncSeatsAtPeriodBoundary($team)) {
                    $audit->record(AuditEventType::SeatsSynced, $team, 'system', $team->slug, 'internal', 'billing:sync-seats', "seats_billed={$team->seats_billed}");
                    $this->components->info("Synced seats for {$team->slug}: {$team->seats_billed}.");
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error("Could not sync {$team->slug}: {$exception->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
