<?php

namespace App\Models;

use Database\Factories\ArtifactCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $artifact_id
 * @property int|null $parent_id
 * @property string $author_user_id
 * @property string|null $author_name
 * @property string|null $author_agent
 * @property string $body
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 * @property Carbon $created_at
 */
#[Fillable(['team_id', 'artifact_id', 'parent_id', 'author_user_id', 'author_name', 'author_agent', 'body', 'resolved_at', 'resolved_by'])]
class ArtifactComment extends Model
{
    /** @use HasFactory<ArtifactCommentFactory> */
    use HasFactory;

    public const MAX_BODY_LENGTH = 4000;

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The shape tools and pages return. The body is data written by other
     * people or agents and is marked as such wherever it is shown to a model.
     *
     * @return array<string, mixed>
     */
    public function toThreadItem(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'author' => $this->author_name,
            'agent' => $this->author_agent,
            'body' => $this->body,
            'created_at' => $this->created_at->toIso8601String(),
            'resolved' => $this->resolved_at !== null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
