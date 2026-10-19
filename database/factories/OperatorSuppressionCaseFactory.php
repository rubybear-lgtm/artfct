<?php

namespace Database\Factories;

use App\Models\OperatorSuppressionCase;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperatorSuppressionCase>
 */
class OperatorSuppressionCaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'category' => OperatorSuppressionCase::CATEGORY_LEGAL_REQUEST,
            'reference' => 'TICKET-'.fake()->unique()->numberBetween(1000, 9999),
            'created_by_user_id' => User::factory(),
            'expires_at' => now()->addDays(30),
            'released_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function released(): static
    {
        return $this->state(fn (): array => ['released_at' => now()->subMinute()]);
    }
}
