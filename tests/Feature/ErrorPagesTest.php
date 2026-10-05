<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('a browser request to an unknown page renders the branded error page', function () {
    config(['app.debug' => false]);

    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 404)
            ->where('auth.user', null));
});

test('a json request to an unknown api url still returns json', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/no-such-endpoint')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('a browser request refused by a policy renders the branded error page', function () {
    config(['app.debug' => false]);

    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $this->actingAs($member)
        ->get(route('teams.governance.show', $team))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 403)
            ->where('auth.user.id', $member->id));
});

test('oauth token errors stay json without a json accept header', function () {
    config(['app.debug' => false]);

    $this->post('/oauth/token', [])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');
});

test('an expired session renders the branded error page', function () {
    config(['app.debug' => false]);

    // CSRF is bypassed while the app runs in the testing environment; this is
    // how McpOAuthTest exercises the same middleware.
    app()['env'] = 'local';

    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertStatus(419)
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 419));
});

test('a throttled browser request renders the branded error page with a retry hint', function () {
    config(['app.debug' => false]);

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->get('/login');
    }

    $this->get('/login')
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 429)
            ->where('retryAfter', fn ($value) => is_int($value) && $value > 0));
});

test('a retry hint of zero reads as no hint at all', function () {
    config(['app.debug' => false]);

    Route::get('/test-throttled-zero', fn () => abort(429, '', ['Retry-After' => '0']));

    $this->get('/test-throttled-zero')
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 429)
            ->missing('retryAfter'));
});

test('debug mode keeps laravel default rendering instead of the branded page', function () {
    config(['app.debug' => true]);

    $response = $this->get('/no-such-page');

    $response->assertNotFound();

    // Laravel's own 404 view, not the Inertia root that carries the page data.
    expect($response->getContent())->not->toContain('data-page="app"');
});
