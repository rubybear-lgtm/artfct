<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, org-scoped set of artifacts (spec 16) — "kept deliberately
 * thin": no approval workflow, no review state, no owner assignment.
 * `canonical` is the one thing gated to admins ({@see
 * \App\Policies\CollectionPolicy::pinCanonical()}) — everything else any
 * member can do.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string|null $description
 * @property bool $canonical
 * @property int $created_by_user_id
 * @property-read \Illuminate\Support\Collection<int, CollectionArtifact> $artifacts
 */
#[Fillable(['team_id', 'name', 'description', 'created_by_user_id', 'canonical'])]
class Collection extends Model
{
    protected $casts = [
        'canonical' => 'boolean',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<CollectionArtifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(CollectionArtifact::class);
    }

    public function artifactIds(): array
    {
        return $this->artifacts()->pluck('artifact_id')->all();
    }
}
