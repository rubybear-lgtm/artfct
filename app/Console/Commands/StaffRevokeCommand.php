<?php

namespace App\Console\Commands;

use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Operator\OperatorAuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Removes artfct staff access (spec 17), including the case manager privilege,
 * which cannot exist without it.
 */
#[Signature('staff:revoke {email : The email of the staff user}')]
#[Description('Revoke operator console access from a user')]
class StaffRevokeCommand extends Command
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

        if ($user->staff_at === null && $user->case_manager_at === null) {
            $this->components->warn("{$user->email} is not staff; nothing to revoke.");

            return self::SUCCESS;
        }

        $user->forceFill(['staff_at' => null, 'case_manager_at' => null])->save();

        $logger->record(
            AuditEventType::StaffRevoked,
            null,
            null,
            "user:{$user->id}",
            'internal',
            'staff:revoke',
        );

        $this->components->info("Revoked staff access from {$user->email}.");

        return self::SUCCESS;
    }
}
