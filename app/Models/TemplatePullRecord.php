<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed template pull (spec 21): the idempotency record that maps
 * (team, template, request id) to the artifact it produced.
 *
 * @property int $id
 * @property int $team_id
 * @property string $actor
 * @property string $template_key
 * @property string $request_id
 * @property string $artifact_id
 */
final class TemplatePullRecord extends Model
{
    protected $table = 'template_pulls';

    protected $fillable = ['team_id', 'actor', 'template_key', 'request_id', 'artifact_id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
