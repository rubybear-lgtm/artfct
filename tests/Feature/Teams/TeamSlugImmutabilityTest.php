<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('renaming_a_team_changes_the_name_and_keeps_the_slug', function () {
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->patch(route('teams.update', $team), ['name' => 'Acme Labs'])->assertRedirect();

    expect($team->fresh()->name)->toBe('Acme Labs')
        ->and($team->fresh()->slug)->toBe('acme');
});

test('a_renamed_teams_slug_cannot_be_claimed_by_another_team', function () {
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    test()->actingAs($admin)->patch(route('teams.update', $team), ['name' => 'Acme Labs'])->assertRedirect();

    $attacker = User::factory()->create();

    test()->actingAs($attacker)
        ->post(route('teams.store'), ['name' => 'Not Acme', 'slug' => 'acme'])
        ->assertSessionHasErrors('slug');

    expect(Team::query()->where('slug', 'acme')->count())->toBe(1);
});

test('the_model_refuses_any_change_to_an_existing_slug', function () {
    $team = Team::factory()->create(['slug' => 'fixed-slug']);

    expect(fn () => $team->update(['slug' => 'other']))->toThrow(RuntimeException::class)
        ->and(fn () => $team->forceFill(['slug' => 'other'])->save())->toThrow(RuntimeException::class);

    expect($team->fresh()->slug)->toBe('fixed-slug');
});

test('a_new_team_still_gets_a_generated_slug_from_its_name', function () {
    $team = Team::factory()->create(['name' => 'Fresh Start', 'slug' => null]);

    expect($team->slug)->toBe('fresh-start');
});
