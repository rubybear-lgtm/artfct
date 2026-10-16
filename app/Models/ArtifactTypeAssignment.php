<?php

namespace App\Models;

use App\Enums\ArtifactType;
use Database\Factories\ArtifactTypeAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $team_id
 * @property string $artifact_id
 * @property ArtifactType $artifact_type
 * @property bool $declared
 */
#[Fillable(['team_id', 'artifact_id', 'artifact_type', 'declared'])]
class ArtifactTypeAssignment extends Model
{
    /** @use HasFactory<ArtifactTypeAssignmentFactory> */
    use HasFactory;

    protected $table = 'artifact_types';

    protected $casts = [
        'artifact_type' => ArtifactType::class,
        'declared' => 'boolean',
    ];
}
