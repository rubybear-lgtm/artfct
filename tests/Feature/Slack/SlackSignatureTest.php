<?php

use App\Enums\TeamRole;
use App\Models\Team;

$payload = ['team_id' => 'T123', 'user_id' => 'U123', 'text' => 'dashboard'];

test('valid_signature_reaches_controller', function () use ($payload) {
    $team = Team::factory()->create(['slug' => 'test-org', 'slack_workspace_id' => 'T123']);
    linkSlackUser($team, TeamRole::Member, 'U123');

    postSlackCommand($payload)->assertOk()->assertJson(['response_type' => 'ephemeral']);
});

test('tampered_body_rejected', function () use ($payload) {
    config(['services.slack.signing_secret' => 'slack-test-secret']);
    $timestamp = now()->timestamp;
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:".json_encode($payload), 'slack-test-secret');
    $tampered = json_encode(['text' => 'dashboxrd'] + $payload);

    test()->call('POST', '/api/slack/commands', [], [], [], [
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SLACK_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $tampered)->assertUnauthorized();
});

test('wrong_secret_rejected', function () use ($payload) {
    postSlackCommand($payload, signingSecret: 'another-secret')->assertUnauthorized();
});

test('stale_timestamp_rejected', function () use ($payload) {
    postSlackCommand($payload, now()->timestamp - 600)->assertUnauthorized();
});

test('future_timestamp_rejected', function () use ($payload) {
    postSlackCommand($payload, now()->timestamp + 600)->assertUnauthorized();
});

test('missing_headers_rejected', function () use ($payload) {
    postSlackCommand($payload, headerOverrides: ['HTTP_X_SLACK_SIGNATURE' => null])->assertUnauthorized();
    postSlackCommand($payload, headerOverrides: ['HTTP_X_SLACK_REQUEST_TIMESTAMP' => null])->assertUnauthorized();
});

test('unset_signing_secret_fails_closed', function () use ($payload) {
    config(['services.slack.signing_secret' => null]);

    postSlackCommand($payload, signingSecret: '', configureSecret: false)->assertUnauthorized();
});

test('slack_published_test_vector_verifies', function () {
    config(['services.slack.signing_secret' => '8f742231b10e8888abcd99yyyzzz85a5']);
    $this->travelTo(Carbon\Carbon::createFromTimestamp(1531420618));
    $body = 'token=xyzz0WbapA4vBCDEFasx0q6G&team_id=T1DC2JH3J&team_domain=testteamnow&channel_id=G8PSS9T3V&channel_name=foobar&user_id=U2CERLKJA&user_name=roadrunner&command=%2Fwebhook-collect&text=&response_url=https%3A%2F%2Fhooks.slack.com%2Fcommands%2FT1DC2JH3J%2F397700885554%2F96rGlfmibIGlgcZRskXaIFfN&trigger_id=398738663015.47445629121.803a0bc887a14d10d2c447fce8b6703c';

    // Verified against the middleware alone: an accepted signature reaches
    // the controller, which then fails validation (422) on this payload.
    $this->call('POST', '/api/slack/commands', [], [], [], [
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => '1531420618',
        'HTTP_X_SLACK_SIGNATURE' => 'v0=a2114d57b48eac39b9ad189dd8316235a7b4a8d21a10bd27519666489c69b503',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertStatus(422);
});

test('slash_command_behaviour_unchanged_when_signed', function () use ($payload) {
    Team::factory()->create(['slug' => 'test-org', 'slack_workspace_id' => 'T123']);

    postSlackCommand(['user_id' => 'U_UNKNOWN'] + $payload)->assertOk();
});
