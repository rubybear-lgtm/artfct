<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Rules\TeamSlug;
use App\Services\Billing\OrgLimitsWriter;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    public function __construct(private OrgLimitsWriter $limitsWriter) {}

    /**
     * Create a new team and add the user as its admin.
     *
     * When `$slug` is given it is used verbatim (validated by the caller
     * against {@see TeamSlug} — this is what lets DoD tests
     * submit an invalid slug like `acme--corp`); otherwise one is derived
     * from the name.
     *
     * After commit, pushes default quota limits to the Worker so the org row
     * exists before any artifact create (RUB-465). A missing Worker URL or
     * secret makes the push a no-op.
     */
    public function handle(User $user, string $name, bool $isPersonal = false, ?string $slug = null): Team
    {
        $team = DB::transaction(function () use ($user, $name, $isPersonal, $slug) {
            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
                'slug' => $slug,
            ]);
            $team->forceFill(['owner_user_id' => $user->id])->save();

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Admin,
            ]);

            $user->switchTeam($team);

            return $team;
        });

        $this->limitsWriter->push($team);

        return $team;
    }
}
