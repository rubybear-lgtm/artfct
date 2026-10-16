<?php

namespace App\Console\Commands;

use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Operator\OperatorAuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Grants artfct staff access (spec 17). This command is the only way
 * `users.staff_at` is ever set: the column is not fillable and no request
 * reaches it.
 */
#[Signature('staff:grant {email : The email of an existing user} {--case-manager : Also let this staff member open, renew and release suppression cases}')]
#[Description('Grant operator console access to an existing user')]
class StaffGrantCommand extends Command
{
    public function handle(OperatorAuditLogger $logger): int
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $this->argument('email')))])
            ->first();

        if ($user === null) {
            $this->components->error('No user has that email address.');

            return self::FAILURE;
        }

        $caseManager = (bool) $this->option('case-manager');

        if ($user->staff_at !== null && (! $caseManager || $user->case_manager_at !== null)) {
            $this->components->warn("{$user->email} already has that access; nothing to grant.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $caseManager, $logger): void {
            $user->forceFill([
                'staff_at' => $user->staff_at ?? now(),
                'case_manager_at' => $caseManager ? ($user->case_manager_at ?? now()) : $user->case_manager_at,
            ])->save();

            $logger->record(
                AuditEventType::StaffGranted,
                null,
                null,
                "user:{$user->id}",
                'internal',
                'staff:grant',
                $caseManager ? 'staff with case manager privilege' : 'staff',
            );
        });

        $this->components->info("Granted staff access to {$user->email}".($caseManager ? ' as a case manager.' : '.'));

        return self::SUCCESS;
    }
}
