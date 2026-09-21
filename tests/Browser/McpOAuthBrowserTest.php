<?php

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('OAuth consent identifies the client user workspace and requested scopes', function () {
    $user = User::factory()->create(['name' => 'Consent Reviewer']);
    $team = app(CreateTeam::class)->handle($user, 'Consent Workspace');

    test()->actingAs($user);

    $parameters = http_build_query([
        'response_type' => 'code',
        'client_id' => 'artfct-cli',
        'redirect_uri' => 'http://127.0.0.1:43123/oauth/callback',
        'scope' => 'artifacts:read artifacts:delete usage:read',
        'state' => 'browser-state',
        'code_challenge' => str_repeat('c', 64),
        'code_challenge_method' => 'S256',
        'team' => $team->slug,
    ]);

    visit('/oauth/authorize?'.$parameters)
        ->assertSee('Connect artfct-cli')
        ->assertSee('Consent Reviewer')
        ->assertSee('Consent Workspace')
        ->assertSee('Read artifacts and search your workspace')
        ->assertSee('Delete artifacts from your workspace')
        ->assertSee('View usage and quota totals')
        ->assertNoJavaScriptErrors();
});
