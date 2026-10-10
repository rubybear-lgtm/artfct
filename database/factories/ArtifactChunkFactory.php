<?php

namespace Database\Factories;

use App\Models\ArtifactChunk;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtifactChunk>
 */
class ArtifactChunkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->paragraph();

        return [
            'team_id' => Team::factory(),
            'artifact_id' => fake()->regexify('[a-f0-9]{32}'),
            'text' => $text,
            'search_text' => $text,
            'embedding' => array_map(fn () => fake()->randomFloat(6, -1, 1), range(1, 1024)),
            'agent' => null,
            'repo_url' => null,
            'commit_sha' => null,
            'chunk_created_at' => now(),
        ];
    }
}
