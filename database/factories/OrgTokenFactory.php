<?php

namespace Database\Factories;

use App\Enums\McpScope;
use App\Enums\OrgTokenKind;
use App\Enums\TeamRole;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrgToken>
 */
class OrgTokenFactory extends Factory
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
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'kind' => OrgTokenKind::Api,
            'jti' => (string) Str::uuid(),
            'role' => TeamRole::Admin,
            'scopes' => null,
            'last_four' => fake()->regexify('[a-z0-9]{4}'),
            'expires_at' => now()->addMinutes(5),
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }

    /**
     * @param  list<string>|null  $scopes
     */
    public function agent(?array $scopes = null): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => OrgTokenKind::Agent,
            'scopes' => $scopes ?? [
                McpScope::ArtifactsRead->value,
                McpScope::ArtifactsDeploy->value,
                McpScope::CollectionsRead->value,
                McpScope::UsageRead->value,
            ],
        ]);
    }
}
