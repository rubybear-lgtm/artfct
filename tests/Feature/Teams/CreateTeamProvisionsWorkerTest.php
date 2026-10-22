<?php

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.worker.base_url' => 'https://worker.test',
        'services.worker.limits_write_secret' => 'limits-secret',
    ]);
    Http::fake(['worker.test/*' => Http::response(['org' => 'provisioned'], 200)]);
});

test('creating_a_team_pushes_org_limits_so_the_worker_has_an_org_row', function () {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Provisioned Co');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://worker.test/v1/internal/org-limits'
        && $request->hasHeader('Authorization', 'Bearer limits-secret')
        && $request['org'] === $team->slug
        && $request['read_only'] === false);
});

test('creating_a_team_still_succeeds_when_the_worker_push_is_unavailable', function () {
    Http::fake(['worker.test/*' => Http::response('nope', 503)]);

    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Offline Co');

    expect($team->exists)->toBeTrue()
        ->and($team->slug)->not->toBeEmpty();
});
