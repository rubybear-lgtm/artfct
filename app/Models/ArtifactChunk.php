<?php

namespace App\Models;

use Database\Factories\ArtifactChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $artifact_id
 * @property string $text
 * @property array<int, float> $embedding
 * @property string|null $agent
 * @property string|null $repo_url
 * @property string|null $commit_sha
 * @property Carbon $chunk_created_at
 */
#[Fillable(['team_id', 'artifact_id', 'text', 'search_text', 'embedding', 'agent', 'repo_url', 'commit_sha', 'chunk_created_at'])]
class ArtifactChunk extends Model
{
    /** @use HasFactory<ArtifactChunkFactory> */
    use HasFactory;

    protected $casts = [
        'embedding' => 'array',
        'chunk_created_at' => 'datetime',
    ];
}
