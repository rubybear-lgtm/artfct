<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $collection_id
 * @property string $artifact_id
 * @property Carbon $added_at
 */
#[Fillable(['collection_id', 'artifact_id', 'added_at'])]
class CollectionArtifact extends Model
{
    public $timestamps = false;

    protected $casts = [
        'added_at' => 'datetime',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
