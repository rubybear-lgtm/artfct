<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Rules\TeamSlug;
use App\Services\Billing\OrgLimitsWriter;
use App\Services\Billing\TeamTrialService;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    public function __construct(
        private OrgLimitsWriter $limitsWriter,
        private TeamTrialService $trials,
    ) {}

    /**
     * Create a new team and add the user as its admin.
     *
     * When `$slug` is given it is used verbatim (validated by the caller
     * against {@see TeamSlug}); otherwise one is derived from the name.
     *
     * Non-personal teams start a Stripe-backed 90-day Team trial (RUB-529).
     * After commit, quota limits are pushed to the Worker.
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

        if (! $isPersonal) {
            if (! $this->trials->start($team->fresh(), $user, 'web')) {
                $this->limitsWriter->push($team);
            }
            $team->refresh();
        } else {
            $this->limitsWriter->push($team);
        }

        return $team;
    }
}
