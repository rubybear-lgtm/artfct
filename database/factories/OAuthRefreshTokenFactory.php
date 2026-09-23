<?php

namespace Database\Factories;

use App\Models\OAuthRefreshToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OAuthRefreshToken>
 */
class OAuthRefreshTokenFactory extends Factory
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
            'client_id' => 'test-client',
            'token_hash' => hash('sha256', Str::random(96)),
            'scopes' => ['artifacts:read'],
            'expires_at' => now()->addDays(30),
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }
}
