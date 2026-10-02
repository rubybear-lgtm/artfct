<?php

namespace Database\Factories;

use App\Models\McpActivity;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpActivity>
 */
class McpActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'credential_jti' => fake()->uuid(),
            'actor' => (string) fake()->numberBetween(1, 1000),
            'tool' => fake()->randomElement(['get_connection', 'search_artifacts', 'deploy_to_canvas']),
            'artifact_id' => null,
            'transport' => 'streamable_http',
            'request_id' => fake()->uuid(),
            'session_id' => fake()->uuid(),
            'client_name' => 'test-client',
            'outcome' => 'success',
            'latency_ms' => fake()->numberBetween(1, 500),
            'created_at' => now(),
        ];
    }
}
