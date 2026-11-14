<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-team feature gate (spec 20, wave 1).
 *
 * This is the AppDb unit's standalone gate: string key, per team, default
 * off. The Mechanism unit ships the shared /ops release model in its own
 * namespace; wave 2 reconciles the two (same key strings, one table).
 *
 * @property int $team_id
 * @property string $key
 * @property bool $enabled
 */
class TeamFeatureGate extends Model
{
    protected $fillable = ['team_id', 'key', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
