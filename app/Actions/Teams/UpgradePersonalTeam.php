<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpgradePersonalTeam
{
    public function __construct(private CreateTeam $createTeam) {}

    /**
     * Turn the user's personal team into a regular team, keeping its artifacts,
     * and give them a fresh personal team so everyone always has one.
     *
     * The slug is the tenant key and never changes, so only the name does.
     */
    public function handle(User $user, Team $team, string $name): Team
    {
        return DB::transaction(function () use ($user, $team, $name) {
            $team->forceFill(['name' => $name, 'is_personal' => false])->save();

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            $user->switchTeam($team);

            return $team;
        });
    }
}
