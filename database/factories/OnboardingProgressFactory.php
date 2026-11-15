<?php

namespace Database\Factories;

use App\Mcp\Support\ArtifactGuidance;
use App\Models\OnboardingProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingProgress>
 */
class OnboardingProgressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'step' => 'read_usage_rules',
            'outcome' => 'done',
            'host' => 'claude-app',
            'guidance_version' => ArtifactGuidance::VERSION,
        ];
    }
}
