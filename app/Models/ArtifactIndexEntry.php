<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $artifact_id
 * @property bool $rendered
 * @property string $extracted_text
 * @property string|null $title
 * @property array<int, string>|null $headings
 * @property Carbon $extracted_at
 */
#[Fillable(['team_id', 'artifact_id', 'rendered', 'extracted_text', 'title', 'headings', 'extracted_at'])]
class ArtifactIndexEntry extends Model
{
    protected $casts = [
        'rendered' => 'boolean',
        'headings' => 'array',
        'extracted_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
