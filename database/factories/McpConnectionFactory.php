<?php

namespace Database\Factories;

use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpConnection>
 */
class McpConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'credential_jti' => null,
            'name' => fake()->words(2, true),
            'client_name' => fake()->randomElement(['claude-code', 'cursor', 'codex', 'gemini']),
            'client_version' => fake()->numerify('1.##.##'),
            'transport' => 'stdio',
            'scopes' => ['artifacts:read', 'artifacts:deploy'],
            'metadata' => null,
            'last_used_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
