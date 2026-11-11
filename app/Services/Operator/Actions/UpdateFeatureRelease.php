<?php

namespace App\Services\Operator\Actions;

use App\Enums\AuditEventType;
use App\Enums\FeatureReleaseState;
use App\Models\FeatureRelease;
use App\Models\Team;
use App\Services\FeatureFlags;
use App\Services\Operator\OperatorAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateFeatureRelease
{
    public function __construct(private readonly FeatureFlags $flags, private readonly OperatorAuditLogger $audit) {}

    /** @param list<string> $teamSlugs */
    public function run(Request $request, string $key, FeatureReleaseState $state, array $teamSlugs, string $reason): void
    {
        abort_unless($this->flags->registered($key), 404);

        DB::transaction(function () use ($request, $key, $state, $teamSlugs, $reason): void {
            $release = FeatureRelease::query()->firstOrCreate([
                'key' => $key,
                'environment' => $this->flags->environment(),
            ], ['state' => FeatureReleaseState::Off, 'selected_team_ids' => []]);
            $release = FeatureRelease::query()->whereKey($release->id)->lockForUpdate()->firstOrFail();

            $teams = Team::query()->whereIn('slug', $teamSlugs)->orderBy('id')->lockForUpdate()->get();
            if ($teams->count() !== count($teamSlugs)) {
                throw ValidationException::withMessages(['team_slugs' => 'One or more teams no longer exist. Refresh and try again.']);
            }

            $previousSlugs = $this->flags->selectedTeams($release)->pluck('slug')->sort()->values()->all();
            $nextSlugs = $teams->pluck('slug')->sort()->values()->all();
            $diff = sprintf('state %s → %s; selected teams [%s] → [%s]', $release->state->label(), $state->label(), implode(', ', $previousSlugs), implode(', ', $nextSlugs));

            $release->update(['state' => $state, 'selected_team_ids' => $teams->modelKeys()]);
            $this->audit->recordForRequest(
                $request,
                AuditEventType::OperatorFeatureReleaseChanged,
                null,
                $this->flags->environment().':feature:'.$key,
                trim($reason).' ('.$diff.')',
            );
        });

        $this->flags->flush();
    }
}
