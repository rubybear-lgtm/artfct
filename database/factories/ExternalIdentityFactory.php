<?php

namespace Database\Factories;

use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalIdentity>
 */
class ExternalIdentityFactory extends Factory
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
            'provider' => 'GoogleOAuth',
            'external_id' => fake()->unique()->uuid(),
            'email' => fake()->unique()->safeEmail(),
            'verified_at' => now(),
            'created_at' => now(),
        ];
    }

    public function polis(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'polis',
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => null,
        ]);
    }
}
