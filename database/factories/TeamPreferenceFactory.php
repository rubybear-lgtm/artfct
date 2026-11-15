<?php

namespace Database\Factories;

use App\Enums\PreferenceKey;
use App\Models\Team;
use App\Models\TeamPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamPreference>
 */
class TeamPreferenceFactory extends Factory
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
            'key' => PreferenceKey::SaveToArtfct->value,
            'value' => 'ask',
            'updated_by' => null,
        ];
    }
}
