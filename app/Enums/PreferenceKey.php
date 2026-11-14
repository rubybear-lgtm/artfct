<?php

namespace App\Enums;

/**
 * The only preferences the server stores. Each key declares who may set it and
 * the exact values it accepts. Values are served to agents as data to act on,
 * so they are a closed set and never free text: a preference can never carry
 * an instruction written by a person or an agent.
 *
 * A preference changes how an agent behaves, never what it is allowed to do.
 * Permissions and team policy (for example `public_sharing_allowed`) are
 * enforced separately and are not overridden by any value here.
 */
enum PreferenceKey: string
{
    /** Whether an agent saves documents and reports it makes to Artfct. */
    case SaveToArtfct = 'save_to_artfct';

    /**
     * Scopes the key may be set at. A key that lists only `user` is personal
     * and cannot be given a team default.
     *
     * @return list<PreferenceScope>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::SaveToArtfct => [PreferenceScope::User, PreferenceScope::Team],
        };
    }

    /** @return list<string> */
    public function values(): array
    {
        return match ($this) {
            self::SaveToArtfct => ['always', 'ask', 'never'],
        };
    }

    /** What applies when neither the person nor their team has chosen. */
    public function default(): string
    {
        return match ($this) {
            self::SaveToArtfct => 'ask',
        };
    }

    public function allows(PreferenceScope $scope): bool
    {
        return in_array($scope, $this->scopes(), true);
    }

    public function accepts(string $value): bool
    {
        return in_array($value, $this->values(), true);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $key): string => $key->value, self::cases());
    }
}
