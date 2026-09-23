<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dead-lettered indexing attempt (spec 12): "parked in a dead-letter
 * queue with the reason recorded and surfaced in the console." Deliberately
 * its own table — never touches `artifacts` or the Worker's serving path,
 * so a dead-lettered artifact keeps serving normally.
 *
 * @property int $id
 * @property int $team_id
 * @property string $artifact_id
 * @property int $attempts
 * @property string $reason
 * @property Carbon $failed_at
 */
#[Fillable(['team_id', 'artifact_id', 'attempts', 'reason', 'failed_at'])]
class ArtifactIndexingFailure extends Model
{
    protected $casts = [
        'failed_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
