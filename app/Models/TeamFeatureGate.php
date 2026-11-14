<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One team's release for one feature key (spec 21). Absent row means off;
 * this is the same seam as the spec-20 unit's gate (same table, same
 * semantics) so wave 2 reconciles onto a single implementation.
 *
 * @property int $id
 * @property int $team_id
 * @property string $key
 * @property bool $enabled
 */
final class TeamFeatureGate extends Model
{
    protected $fillable = ['team_id', 'key', 'enabled'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
