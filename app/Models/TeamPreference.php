<?php

namespace App\Models;

use Database\Factories\TeamPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team's default for one preference key, used for every member who has not
 * set their own. Only a team admin changes it, and each change is audited.
 *
 * @property int $id
 * @property int $team_id
 * @property string $key
 * @property string $value
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'key', 'value', 'updated_by'])]
class TeamPreference extends Model
{
    /** @use HasFactory<TeamPreferenceFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
