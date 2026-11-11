<?php

namespace Database\Factories;

use App\Enums\FeatureReleaseState;
use App\Models\FeatureRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureRelease>
 */
class FeatureReleaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'environment' => app()->environment(),
            'state' => FeatureReleaseState::Off,
            'selected_team_ids' => [],
        ];
    }
}
