<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TeamTrialService;
use Illuminate\Support\Facades\DB;

class UpgradePersonalTeam
{
    public function __construct(
        private CreateTeam $createTeam,
        private TeamTrialService $trials,
    ) {}

    /**
     * Turn the user's personal team into a regular team, keeping its artifacts,
     * and give them a fresh personal team so everyone always has one.
     * The upgraded team starts a 90-day Team trial (RUB-529).
     */
    public function handle(User $user, Team $team, string $name): Team
    {
        $upgraded = DB::transaction(function () use ($user, $team, $name) {
            $team->forceFill(['name' => $name, 'is_personal' => false])->save();

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            $user->switchTeam($team);

            return $team;
        });

        $this->trials->start($upgraded->fresh(), $user, 'web');

        return $upgraded->fresh();
    }
}
