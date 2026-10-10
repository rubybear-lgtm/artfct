<?php

namespace App\Models;

use Database\Factories\ArtifactIndexEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $artifact_id
 * @property int|null $version
 * @property bool $rendered
 * @property string $extracted_text
 * @property string|null $title
 * @property array<int, string>|null $headings
 * @property Carbon $extracted_at
 */
#[Fillable(['team_id', 'artifact_id', 'version', 'rendered', 'extracted_text', 'title', 'headings', 'extracted_at'])]
class ArtifactIndexEntry extends Model
{
    /** @use HasFactory<ArtifactIndexEntryFactory> */
    use HasFactory;

    protected $casts = [
        'rendered' => 'boolean',
        'version' => 'integer',
        'headings' => 'array',
        'extracted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
