<?php

namespace App\Enums;

/**
 * Whose preference it is. A user's own value follows them across every team
 * they belong to; a team's value is the default for every member who has not
 * chosen their own.
 */
enum PreferenceScope: string
{
    case User = 'user';
    case Team = 'team';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
