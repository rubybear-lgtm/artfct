<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.worker.base_url' => 'https://worker.test',
        'services.worker.limits_write_secret' => 'limits-secret',
    ]);
});

test('the_command_backfills_a_teams_owner', function () {
    Http::fake(['worker.test/*' => Http::response(['org' => 'acme', 'updated' => 4])]);
    $team = Team::factory()->create(['slug' => 'acme']);
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();

    test()->artisan('artifacts:backfill-owners')
        ->expectsOutputToContain('Updated 4 artifact(s) for acme')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://worker.test/v1/internal/orgs/acme/owner-backfill'
        && $request->hasHeader('Authorization', 'Bearer limits-secret')
        && $request['owner_user_id'] === (string) $owner->id);
});

test('the_command_falls_back_to_the_earliest_admin_when_the_team_has_no_owner', function () {
    Http::fake(['worker.test/*' => Http::response(['org' => 'acme', 'updated' => 1])]);
    $team = Team::factory()->create(['slug' => 'acme', 'owner_user_id' => null]);
    $first = memberOfTeam($team, TeamRole::Admin);
    $second = memberOfTeam($team, TeamRole::Admin);

    // Make the join order unambiguous rather than relying on two inserts
    // sharing a second.
    $team->memberships()->where('user_id', $second->id)->update(['created_at' => now()->addMinute()]);

    test()->artisan('artifacts:backfill-owners')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request['owner_user_id'] === (string) $first->id);
});

test('the_command_skips_a_team_with_neither_owner_nor_admin', function () {
    Http::fake();
    $team = Team::factory()->create(['slug' => 'acme', 'owner_user_id' => null]);
    memberOfTeam($team, TeamRole::Member);

    test()->artisan('artifacts:backfill-owners')
        ->expectsOutputToContain('no owner and no admin')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('a_dry_run_prints_what_it_would_do_and_calls_nothing', function () {
    Http::fake();
    $team = Team::factory()->create(['slug' => 'acme']);
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();

    test()->artisan('artifacts:backfill-owners', ['--dry-run' => true])
        ->expectsOutputToContain('Would set owner')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('the_team_option_limits_the_backfill_to_one_team', function () {
    Http::fake(['worker.test/*' => Http::response(['org' => 'acme', 'updated' => 2])]);
    $acme = Team::factory()->create(['slug' => 'acme']);
    $acmeOwner = memberOfTeam($acme, TeamRole::Admin);
    $acme->forceFill(['owner_user_id' => $acmeOwner->id])->save();

    $other = Team::factory()->create(['slug' => 'other']);
    $otherOwner = memberOfTeam($other, TeamRole::Admin);
    $other->forceFill(['owner_user_id' => $otherOwner->id])->save();

    test()->artisan('artifacts:backfill-owners', ['--team' => 'acme'])->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/orgs/acme/'));
});
