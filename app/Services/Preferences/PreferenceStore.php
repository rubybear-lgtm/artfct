<?php

namespace App\Services\Preferences;

use App\Enums\PreferenceKey;
use App\Enums\PreferenceScope;
use App\Models\Team;
use App\Models\TeamPreference;
use App\Models\User;
use App\Models\UserPreference;
use InvalidArgumentException;

/**
 * Reads and writes the preferences the server keeps for a person and for a
 * team. The effective value for a person is their own, else their team's
 * default, else the key's built-in default. Task and project overrides live in
 * the agent's own context and are never stored here.
 *
 * Whatever is stored is checked against `PreferenceKey` on every read, so a
 * row left behind by an older key list or value set is ignored, not served.
 */
final class PreferenceStore
{
    /**
     * @return array<string, array{value: string, source: 'user'|'team'|'default', scopes: list<string>, allowed_values: list<string>}>
     */
    public function effective(?User $user, Team $team): array
    {
        /** @var array<string, string> $own */
        $own = $user === null
            ? []
            : UserPreference::query()->where('user_id', $user->id)->pluck('value', 'key')->all();

        /** @var array<string, string> $shared */
        $shared = TeamPreference::query()->where('team_id', $team->id)->pluck('value', 'key')->all();

        $effective = [];

        foreach (PreferenceKey::cases() as $key) {
            [$value, $source] = $this->resolve(
                $key,
                $own[$key->value] ?? null,
                $shared[$key->value] ?? null,
            );

            $effective[$key->value] = [
                'value' => $value,
                'source' => $source,
                'scopes' => array_map(static fn (PreferenceScope $scope): string => $scope->value, $key->scopes()),
                'allowed_values' => $key->values(),
            ];
        }

        return $effective;
    }

    /**
     * @throws InvalidArgumentException When the key cannot be set at that
     *                                  scope or the value is not one of its allowed values.
     */
    public function set(PreferenceKey $key, PreferenceScope $scope, string $value, User $actor, Team $team): void
    {
        $this->assertScope($key, $scope);

        if (! $key->accepts($value)) {
            throw new InvalidArgumentException("{$key->value} accepts: ".implode(', ', $key->values()).'.');
        }

        // One atomic statement per key: two writes to different keys, or the
        // same key from two agents at once, never fail on the unique index.
        if ($scope === PreferenceScope::User) {
            UserPreference::query()->upsert(
                [['user_id' => $actor->id, 'key' => $key->value, 'value' => $value]],
                ['user_id', 'key'],
                ['value'],
            );

            return;
        }

        TeamPreference::query()->upsert(
            [['team_id' => $team->id, 'key' => $key->value, 'value' => $value, 'updated_by' => $actor->id]],
            ['team_id', 'key'],
            ['value', 'updated_by'],
        );
    }

    /**
     * @throws InvalidArgumentException When the key cannot be set at that scope.
     */
    public function clear(PreferenceKey $key, PreferenceScope $scope, User $actor, Team $team): void
    {
        $this->assertScope($key, $scope);

        if ($scope === PreferenceScope::User) {
            UserPreference::query()->where('user_id', $actor->id)->where('key', $key->value)->delete();

            return;
        }

        TeamPreference::query()->where('team_id', $team->id)->where('key', $key->value)->delete();
    }

    /**
     * @return array{0: string, 1: 'user'|'team'|'default'}
     */
    private function resolve(PreferenceKey $key, ?string $own, ?string $shared): array
    {
        if ($own !== null && $key->allows(PreferenceScope::User) && $key->accepts($own)) {
            return [$own, 'user'];
        }

        if ($shared !== null && $key->allows(PreferenceScope::Team) && $key->accepts($shared)) {
            return [$shared, 'team'];
        }

        return [$key->default(), 'default'];
    }

    private function assertScope(PreferenceKey $key, PreferenceScope $scope): void
    {
        if (! $key->allows($scope)) {
            throw new InvalidArgumentException("{$key->value} cannot be set at the {$scope->value} level.");
        }
    }
}
