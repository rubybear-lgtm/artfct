<?php

use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function healthPageStaff(): User
{
    $staff = User::factory()->create();
    $staff->forceFill(['staff_at' => now()])->save();

    return $staff;
}

test('health_page_reports_failed_jobs_and_indexing_failures', function () {
    $staff = healthPageStaff();

    $this->actingAs($staff)->get(route('ops.health'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ops/health')
            ->where('queue.failed_jobs', 0)
            ->where('indexing.failures', 0)
            ->where('org_token_revocations.pending', 0)
            ->where('stripe.last_received_at', null)
            ->where('worker_events.last_received_at', null));

    $team = Team::factory()->create();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
    ArtifactIndexingFailure::create([
        'team_id' => $team->id,
        'artifact_id' => 'abc123',
        'attempts' => 3,
        'reason' => 'embedding timeout',
        'failed_at' => now(),
    ]);
    DB::table('org_token_revocation_retries')->insert([
        ['jti_hash' => str_repeat('a', 64), 'encrypted_jti' => 'x', 'expires_at' => now()->addDay(), 'retry_after' => now(), 'completed_at' => null],
        ['jti_hash' => str_repeat('b', 64), 'encrypted_jti' => 'x', 'expires_at' => now()->addDay(), 'retry_after' => now(), 'completed_at' => now()],
    ]);
    DB::table('stripe_events_received')->insert(['event_id' => 'evt_1', 'type' => 'invoice.paid', 'received_at' => '2026-10-14 09:00:00']);
    DB::table('worker_events_received')->insert(['event_id' => (string) Str::uuid(), 'type' => 'artifact.created', 'org_id' => 'acme', 'received_at' => '2026-10-15 10:30:00']);

    config(['services.workos.staff_organization_id' => 'org_staff_123']);

    $this->actingAs($staff)->get(route('ops.health'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('queue.failed_jobs', 1)
            ->where('queue.pending_jobs', 1)
            ->where('indexing.failures', 1)
            ->where('org_token_revocations.pending', 1)
            ->where('stripe.last_received_at', fn (string $at) => str_starts_with($at, '2026-10-14T09:00:00'))
            ->where('worker_events.last_received_at', fn (string $at) => str_starts_with($at, '2026-10-15T10:30:00'))
            ->where('staff_organization', ['configured' => true, 'id' => 'org_staff_123'])
            ->has('readiness', 4, fn (Assert $row) => $row->hasAll(['name', 'state', 'missing'])));
});

test('health_page_readiness_matches_the_readiness_command', function () {
    config([
        'services.workos' => ['client_id' => 'client_x', 'secret' => null, 'redirect_url' => null],
        'services.stripe' => [],
        'mail.default' => 'log',
    ]);

    $response = $this->actingAs(healthPageStaff())->get(route('ops.health'))->assertOk();
    $readiness = collect($response->viewData('page')['props']['readiness'])->keyBy('name');

    expect($readiness['WorkOS sign-in']['state'])->toBe('incomplete')
        ->and($readiness['WorkOS sign-in']['missing'])->toBe(['services.workos.secret', 'services.workos.redirect_url'])
        ->and($readiness['Stripe payments']['state'])->toBe('not configured');

    $this->artisan('app:readiness')->expectsOutputToContain('services.workos.secret, services.workos.redirect_url')->assertFailed();
});
