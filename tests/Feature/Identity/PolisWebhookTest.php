<?php

use App\Models\ExternalIdentity;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function polisEvent(array $payload, ?string $secret = 'whsec', ?int $timestampMs = null): TestResponse
{
    $body = json_encode($payload);
    $timestampMs ??= (int) (microtime(true) * 1000);
    $signature = hash_hmac('sha256', $timestampMs.'.'.$body, (string) $secret);

    return test()->call('POST', '/webhooks/polis', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_BOXYHQ_SIGNATURE' => "t={$timestampMs},s={$signature}",
    ], $body);
}

beforeEach(function () {
    config(['services.polis.webhook_secret' => 'whsec']);
    Http::fake();
});

test('a_created_user_is_provisioned_into_the_tenant_org', function () {
    $team = Team::factory()->create(['slug' => 'acme']);

    polisEvent(['event' => 'user.created', 'tenant' => 'acme', 'product' => 'artfct', 'data' => ['id' => 'scim-1', 'email' => 'New@Acme.com', 'first_name' => 'New', 'last_name' => 'Hire', 'active' => true]])->assertOk();

    $user = User::query()->where('email', 'new@acme.com')->firstOrFail();
    expect($team->memberships()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(ExternalIdentity::query()->where('provider', 'scim')->where('external_id', 'scim-1')->exists())->toBeTrue();
});

test('a_repeated_event_does_not_duplicate_the_user', function () {
    Team::factory()->create(['slug' => 'acme']);
    $event = ['event' => 'user.created', 'tenant' => 'acme', 'data' => ['id' => 'scim-1', 'email' => 'a@acme.com', 'active' => true]];

    polisEvent($event)->assertOk();
    polisEvent($event)->assertOk();

    expect(User::query()->where('email', 'a@acme.com')->count())->toBe(1);
});

test('a_deactivation_keeps_the_row_and_revokes_org_tokens', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    polisEvent(['event' => 'user.created', 'tenant' => 'acme', 'data' => ['id' => 'scim-1', 'email' => 'a@acme.com', 'active' => true]])->assertOk();
    $user = User::query()->where('email', 'a@acme.com')->firstOrFail();
    $token = OrgToken::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);

    polisEvent(['event' => 'user.updated', 'tenant' => 'acme', 'data' => ['id' => 'scim-1', 'email' => 'a@acme.com', 'active' => false]])->assertOk();

    expect($user->fresh()->deactivated_at)->not->toBeNull()
        ->and($token->fresh()->revoked_at)->not->toBeNull();
});

test('a_deleted_event_also_deprovisions_and_a_batch_is_processed', function () {
    Team::factory()->create(['slug' => 'acme']);
    polisEvent([
        ['event' => 'user.created', 'tenant' => 'acme', 'data' => ['id' => 's1', 'email' => 'one@acme.com', 'active' => true]],
        ['event' => 'user.created', 'tenant' => 'acme', 'data' => ['id' => 's2', 'email' => 'two@acme.com', 'active' => true]],
    ])->assertOk();

    polisEvent(['event' => 'user.deleted', 'tenant' => 'acme', 'data' => ['id' => 's1', 'email' => 'one@acme.com']])->assertOk();

    expect(User::query()->where('email', 'one@acme.com')->firstOrFail()->deactivated_at)->not->toBeNull()
        ->and(User::query()->where('email', 'two@acme.com')->firstOrFail()->deactivated_at)->toBeNull();
});

test('a_bad_signature_a_stale_timestamp_or_no_secret_is_refused', function () {
    Team::factory()->create(['slug' => 'acme']);
    $event = ['event' => 'user.created', 'tenant' => 'acme', 'data' => ['id' => 's', 'email' => 'x@acme.com', 'active' => true]];

    polisEvent($event, 'wrong-secret')->assertStatus(400);
    polisEvent($event, 'whsec', (int) ((time() - 3600) * 1000))->assertStatus(400);
    config(['services.polis.webhook_secret' => null]);
    polisEvent($event)->assertStatus(400);

    expect(User::query()->where('email', 'x@acme.com')->exists())->toBeFalse();
});

test('an_event_for_an_unknown_org_changes_nothing_and_returns_ok', function () {
    polisEvent(['event' => 'user.created', 'tenant' => 'nobody', 'data' => ['id' => 's', 'email' => 'x@nobody.com', 'active' => true]])->assertOk();

    expect(User::query()->where('email', 'x@nobody.com')->exists())->toBeFalse();
});
