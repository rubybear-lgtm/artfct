<?php

namespace App\Models;

use App\Enums\UsageEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $artifact_id
 * @property UsageEventType $event_type
 * @property int|null $actor_user_id
 * @property string|null $related_artifact_id
 * @property Carbon $occurred_at
 */
#[Fillable(['team_id', 'artifact_id', 'event_type', 'actor_user_id', 'related_artifact_id', 'occurred_at'])]
class ArtifactUsageEvent extends Model
{
    public $timestamps = false;

    protected $casts = [
        'event_type' => UsageEventType::class,
        'occurred_at' => 'datetime',
    ];
}
