<?php

namespace Database\Factories;

use App\Models\ArtifactComment;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtifactComment>
 */
class ArtifactCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'artifact_id' => fake()->regexify('[a-z0-9]{13}'),
            'parent_id' => null,
            'author_user_id' => (string) fake()->numberBetween(1, 1000),
            'author_name' => fake()->name(),
            'author_agent' => null,
            'body' => fake()->sentence(),
            'resolved_at' => null,
            'resolved_by' => null,
        ];
    }
}
