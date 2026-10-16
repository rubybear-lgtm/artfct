<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Collection>
 */
class CollectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'created_by_user_id' => User::factory(),
            'canonical' => false,
        ];
    }
}
