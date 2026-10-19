<?php

namespace Database\Factories;

use App\Models\SearchResultServed;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchResultServed>
 */
class SearchResultServedFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'artifact_id' => fake()->regexify('[a-f0-9]{32}'),
            'served_at' => now(),
        ];
    }
}
