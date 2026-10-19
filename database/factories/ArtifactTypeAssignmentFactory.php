<?php

namespace Database\Factories;

use App\Enums\ArtifactType;
use App\Models\ArtifactTypeAssignment;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtifactTypeAssignment>
 */
class ArtifactTypeAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'artifact_id' => fake()->regexify('[a-z0-9]{13}'),
            'artifact_type' => ArtifactType::Other,
            'declared' => false,
        ];
    }
}
