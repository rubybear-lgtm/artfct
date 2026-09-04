<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Rules\TeamSlug;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * Create a new team and add the user as its admin.
     *
     * When `$slug` is given it is used verbatim (validated by the caller
     * against {@see TeamSlug} — this is what lets DoD tests
     * submit an invalid slug like `acme--corp`); otherwise one is derived
     * from the name.
     */
    public function handle(User $user, string $name, bool $isPersonal = false, ?string $slug = null): Team
    {
        return DB::transaction(function () use ($user, $name, $isPersonal, $slug) {
            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
                'slug' => $slug,
            ]);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Admin,
            ]);

            $user->switchTeam($team);

            return $team;
        });
    }
}
