<?php

namespace App\Services;

use App\Enums\FeatureReleaseState;
use App\Models\FeatureRelease;
use App\Models\Team;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

class FeatureFlags
{
    /** @return array<string, array{name: string, description: string, expose_to_browser?: bool}> */
    public function registry(): array
    {
        return config('features.registry', []);
    }

    public function environment(): string
    {
        return app()->environment();
    }

    public function registered(string $key): bool
    {
        return array_key_exists($key, $this->registry());
    }

    public function register(): void
    {
        foreach (array_keys($this->registry()) as $key) {
            Feature::store('releases')->define($key, function (Team $team) use ($key): bool {
                $release = $this->release($key);

                return match ($release?->state) {
                    FeatureReleaseState::Released => true,
                    FeatureReleaseState::SelectedTeams => in_array($team->id, $release->selected_team_ids, true),
                    default => false,
                };
            });
        }
    }

    public function enabled(string $key, Team $team): bool
    {
        if (! $this->registered($key) || ! $team->exists || $team->trashed()) {
            return false;
        }

        return Feature::store('releases')->for($team)->active($key);
    }

    public function ensureEnabled(string $key, Team $team): void
    {
        abort_unless($this->enabled($key, $team), 404);
    }

    public function release(string $key): ?FeatureRelease
    {
        return FeatureRelease::query()->where('key', $key)->where('environment', $this->environment())->first();
    }

    /** @return array<string, bool> */
    public function forBrowser(Team $team): array
    {
        $flags = [];

        foreach ($this->registry() as $key => $definition) {
            if ($definition['expose_to_browser'] ?? false) {
                $flags[$key] = $this->enabled($key, $team);
            }
        }

        return $flags;
    }

    /** @return Collection<int, Team> */
    public function selectedTeams(?FeatureRelease $release): Collection
    {
        return Team::query()->whereKey($release->selected_team_ids ?? [])->orderBy('slug')->get(['id', 'slug', 'name']);
    }

    public function flush(): void
    {
        Feature::store('releases')->flushCache();
    }
}
