<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\ExternalIdentity;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Slack\ArtifactSharingContract;
use App\Services\Slack\FakeArtifactSharing;
use App\Services\Slack\FakeSlackPost;
use App\Services\Slack\SharingLevel;
use App\Services\Slack\SlackChannelNotAllowedException;
use App\Services\Slack\SlackPostContract;
use App\Services\Slack\SlackPostService;
use App\Services\Slack\SlackRateLimitExceededException;
use App\Services\Slack\SlashCommandService;
use App\Services\Slack\UnfurlService;

function linkSlackUser(Team $team, TeamRole $role, string $slackUserId): User
{
    $user = memberOfTeam($team, $role);
    ExternalIdentity::create([
        'user_id' => $user->id,
        'provider' => 'slack',
        'external_id' => $slackUserId,
        'email' => $user->email,
        'verified_at' => now(),
    ]);

    return $user;
}

test('public_artifact_unfurls_with_full_metadata', function () {
    $team = Team::factory()->create(['slug' => 'test-org']);
    /** @var FakeArtifactSharing $sharing */
    $sharing = app(ArtifactSharingContract::class);
    $sharing->seed('test-org', '1234567890', SharingLevel::Public);

    $result = app(UnfurlService::class)->unfurl($team, '1234567890');

    expect($result->bareCard)->toBeFalse();
    expect($result->title)->not->toBeNull();
    expect($result->description)->not->toBeNull();
});

test('private_artifact_unfurls_bare_card', function () {
    $team = Team::factory()->create(['slug' => 'test-org']);
    /** @var FakeArtifactSharing $sharing */
    $sharing = app(ArtifactSharingContract::class);
    $sharing->seed('test-org', '1234567890', SharingLevel::OrgPrivate);

    $result = app(UnfurlService::class)->unfurl($team, '1234567890');

    expect($result->bareCard)->toBeTrue();
    expect($result->title)->toBeNull();
    expect($result->description)->toBeNull();
    expect($result->thumbnail)->toBeNull();
});

test('domain_restricted_artifact_unfurls_title_only', function () {
    $team = Team::factory()->create(['slug' => 'test-org']);
    /** @var FakeArtifactSharing $sharing */
    $sharing = app(ArtifactSharingContract::class);
    $sharing->seed('test-org', '1234567890', SharingLevel::DomainRestricted, 'acme.com');

    $result = app(UnfurlService::class)->unfurl($team, '1234567890');

    expect($result->bareCard)->toBeFalse();
    expect($result->title)->not->toBeNull();
    expect($result->description)->toBeNull();
    expect($result->thumbnail)->toBeNull();
});

test('outside_user_unfurl_reveals_nothing', function () {
    // Unfurl richness is a pure function of the artifact's own sharing
    // level — there is no viewer-identity parameter to pass at all, so
    // an outsider who somehow obtains an org-private link gets exactly
    // the same bare card any other viewer would (DoD: "sees the bare
    // card" — not a *different*, more revealing shape).
    $team = Team::factory()->create(['slug' => 'test-org']);
    /** @var FakeArtifactSharing $sharing */
    $sharing = app(ArtifactSharingContract::class);
    $sharing->seed('test-org', '1234567890', SharingLevel::OrgPrivate);

    $result = app(UnfurlService::class)->unfurl($team, '1234567890');

    expect($result->bareCard)->toBeTrue();
});

test('slash_command_returns_ephemeral_results', function () {
    $team = Team::factory()->create(['slug' => 'test-org', 'slack_workspace_id' => 'T123']);
    linkSlackUser($team, TeamRole::Member, 'U123');

    $response = postSlackCommand([
        'team_id' => 'T123',
        'user_id' => 'U123',
        'text' => 'dashboard',
    ]);

    $response->assertOk();
    $response->assertJson(['response_type' => 'ephemeral']);
});

test('search_results_not_visible_to_channel', function () {
    $team = Team::factory()->create(['slug' => 'test-org', 'slack_workspace_id' => 'T123']);
    linkSlackUser($team, TeamRole::Member, 'U123');

    $response = postSlackCommand([
        'team_id' => 'T123',
        'user_id' => 'U123',
        'text' => 'dashboard',
    ]);

    // Slack only ever posts a message visible to the whole channel when
    // response_type is "in_channel" — this route must never send that.
    expect($response->json('response_type'))->toBe('ephemeral');
    expect($response->json('response_type'))->not->toBe('in_channel');
});

test('unlinked_slack_user_gets_connect_prompt', function () {
    Team::factory()->create(['slug' => 'test-org', 'slack_workspace_id' => 'T123']);

    $response = postSlackCommand([
        'team_id' => 'T123',
        'user_id' => 'U_UNKNOWN',
        'text' => 'dashboard',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['response_type' => 'ephemeral']);
    expect($response->json('text'))->toContain('Connect');
});

test('slack_user_cannot_search_other_org', function () {
    $orgA = Team::factory()->create(['slug' => 'org-a', 'slack_workspace_id' => 'T_A']);
    $orgB = Team::factory()->create(['slug' => 'org-b', 'slack_workspace_id' => 'T_B']);
    // Linked in org A only.
    linkSlackUser($orgA, TeamRole::Member, 'U123');

    $result = app(SlashCommandService::class)->handle('T_B', 'U123', 'dashboard');

    expect($result->needsConnect)->toBeTrue();
});

test('agent_post_to_unlisted_channel_refused', function () {
    $team = Team::factory()->create();
    $token = OrgToken::factory()->create(['team_id' => $team->id, 'slack_channels' => ['#allowed']]);

    expect(fn () => app(SlackPostService::class)->postArtifact($token, '#other', 'https://artfct.dev/p/abc', 'Dashboard'))
        ->toThrow(SlackChannelNotAllowedException::class);

    /** @var FakeSlackPost $slack */
    $slack = app(SlackPostContract::class);
    expect($slack->posted)->toBeEmpty();
});

test('channel_post_rate_limit_enforced', function () {
    $team = Team::factory()->create();
    $token = OrgToken::factory()->create(['team_id' => $team->id, 'slack_channels' => ['#allowed']]);
    $service = app(SlackPostService::class);

    for ($i = 0; $i < 5; $i++) {
        $service->postArtifact($token, '#allowed', 'https://artfct.dev/p/abc', 'Dashboard');
    }

    expect(fn () => $service->postArtifact($token, '#allowed', 'https://artfct.dev/p/abc', 'Dashboard'))
        ->toThrow(SlackRateLimitExceededException::class);

    /** @var FakeSlackPost $slack */
    $slack = app(SlackPostContract::class);
    expect($slack->posted)->toHaveCount(5); // refused, not queued — the 6th never reaches Slack
});

test('unfurl_and_search_are_audited', function () {
    $team = Team::factory()->create(['slug' => 'audit-org', 'slack_workspace_id' => 'T_AUDIT']);
    linkSlackUser($team, TeamRole::Member, 'U123');
    /** @var FakeArtifactSharing $sharing */
    $sharing = app(ArtifactSharingContract::class);
    $sharing->seed('audit-org', '1234567890', SharingLevel::Public);

    app(UnfurlService::class)->unfurl($team, '1234567890');
    app(SlashCommandService::class)->handle('T_AUDIT', 'U123', 'anything');

    expect(AuditEvent::query()->where('event_type', AuditEventType::ArtifactViewed)->where('actor', 'slack:unfurl')->exists())->toBeTrue();
    expect(AuditEvent::query()->where('event_type', AuditEventType::SearchPerformed)->exists())->toBeTrue();
});
