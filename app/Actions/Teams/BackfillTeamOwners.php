<?php

namespace App\Actions\Teams;

use Illuminate\Support\Facades\DB;

/**
 * Gives every ownerless team an owner: its earliest admin. Used by the
 * migration that introduced `teams.owner_user_id`, and safe to rerun.
 */
class BackfillTeamOwners
{
    public function handle(): int
    {
        $updated = 0;

        DB::table('teams')->whereNull('owner_user_id')->orderBy('id')->each(function ($team) use (&$updated): void {
            $ownerId = DB::table('team_members')
                ->where('team_id', $team->id)
                ->where('role', 'admin')
                ->orderBy('id')
                ->value('user_id');

            if ($ownerId !== null) {
                DB::table('teams')->where('id', $team->id)->update(['owner_user_id' => $ownerId]);
                $updated++;
            }
        });

        return $updated;
    }
}
