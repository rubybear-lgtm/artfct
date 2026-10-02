<?php

namespace Database\Factories;

use App\Enums\UsageEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtifactUsageEvent>
 */
class ArtifactUsageEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'artifact_id' => fake()->regexify('[a-f0-9]{32}'),
            'event_type' => UsageEventType::Viewed,
            'actor_user_id' => null,
            'related_artifact_id' => null,
            'occurred_at' => now(),
        ];
    }
}
