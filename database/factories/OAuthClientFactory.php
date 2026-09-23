<?php

namespace Database\Factories;

use App\Models\OAuthClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OAuthClient>
 */
class OAuthClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => 'client_'.fake()->unique()->regexify('[a-z0-9]{24}'),
            'client_name' => fake()->company().' MCP',
            'redirect_uris' => ['http://127.0.0.1:8765/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'client_id_issued_at' => now()->timestamp,
        ];
    }
}
