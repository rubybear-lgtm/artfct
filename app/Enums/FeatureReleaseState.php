<?php

namespace App\Enums;

enum FeatureReleaseState: string
{
    case Off = 'off';
    case SelectedTeams = 'selected';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::SelectedTeams => 'Selected teams',
            self::Released => 'Released',
        };
    }
}
