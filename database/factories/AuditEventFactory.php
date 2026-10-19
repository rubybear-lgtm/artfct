<?php

namespace Database\Factories;

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'event_type' => AuditEventType::ArtifactCreated,
            'actor' => (string) fake()->numberBetween(1, 1000),
            'target' => fake()->uuid(),
            'ip' => fake()->ipv4(),
            'user_agent' => 'synthetic',
            'outcome' => 'success',
            'created_at' => now(),
        ];
    }
}
