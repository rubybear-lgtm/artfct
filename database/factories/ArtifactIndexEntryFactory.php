<?php

namespace Database\Factories;

use App\Models\ArtifactIndexEntry;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtifactIndexEntry>
 */
class ArtifactIndexEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'artifact_id' => fake()->regexify('[a-f0-9]{32}'),
            'rendered' => false,
            'extracted_text' => fake()->paragraph(),
            'title' => fake()->sentence(3),
            'headings' => [],
            'extracted_at' => now(),
        ];
    }
}
