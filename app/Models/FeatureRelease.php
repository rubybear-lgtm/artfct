<?php

namespace App\Models;

use App\Enums\FeatureReleaseState;
use Database\Factories\FeatureReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $environment
 * @property FeatureReleaseState $state
 * @property list<int> $selected_team_ids
 */
#[Fillable(['key', 'environment', 'state', 'selected_team_ids'])]
class FeatureRelease extends Model
{
    /** @use HasFactory<FeatureReleaseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'state' => FeatureReleaseState::class,
            'selected_team_ids' => 'array',
        ];
    }
}
