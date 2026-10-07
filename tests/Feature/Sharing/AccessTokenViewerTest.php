<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Indexing\RealRenderer;
use Illuminate\Support\Facades\Http;

/**
 * RUB-438: the isolated-origin access token now names the viewer it was minted
 * for, so a link minted while an artifact was team-visible cannot keep opening
 * it once the owner makes it private. The Worker accepts the viewer-bound form
 * only for a private artifact when the scope is `p` (a team admin or the
 * `system` renderer) or the viewer is the artifact's owner.
 */
function sharingAccessTokenFromUrl(?string $url): string
{
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    return (string) ($query['token'] ?? '');
}

test('the_viewer_bound_token_has_five_parts_and_an_hmac_over_every_field', function () {
    $link = new ArtifactAccessLink('fixed-secret', '.artfct.dev', 60);

    $url = (string) $link->forArtifact('test-org', ARTIFACT_LINK_ID, now()->setTimestamp(1_700_000_000), null, '42', false);

    // Independently: the message is every field before the HMAC, joined by
    // dots, and the HMAC is the plain SHA-256 primitive over it.
    $message = ARTIFACT_LINK_ID.'.1700000000.42.m';
    $expected = $message.'.'.hash_hmac('sha256', $message, 'fixed-secret');

    expect($url)->toBe(
        'https://test-org--'.ARTIFACT_LINK_ID.'.artfct.dev/p/'.ARTIFACT_LINK_ID.'/?token='.$expected
    );

    $parts = explode('.', sharingAccessTokenFromUrl($url));

    expect($parts)->toHaveCount(5)
        ->and($parts[0])->toBe(ARTIFACT_LINK_ID)
        ->and($parts[1])->toBe('1700000000')
        ->and($parts[2])->toBe('42')
        ->and($parts[3])->toBe('m')
        ->and($parts[4])->toBe(hash_hmac('sha256', $message, 'fixed-secret'));
});

test('a_private_seeing_viewer_gets_scope_p_and_a_member_gets_scope_m', function () {
    $link = new ArtifactAccessLink('fixed-secret');
    $expiresAt = now()->setTimestamp(1_700_000_000);

    expect(explode('.', sharingAccessTokenFromUrl($link->forArtifact('test-org', ARTIFACT_LINK_ID, $expiresAt, null, '42', true)))[3])
        ->toBe('p')
        ->and(explode('.', sharingAccessTokenFromUrl($link->forArtifact('test-org', ARTIFACT_LINK_ID, $expiresAt, null, '42', false)))[3])
        ->toBe('m');
});

test('the_renderer_mints_a_system_private_seeing_token', function () {
    config([
        'services.cloudflare.account_id' => 'test-account',
        'services.cloudflare.api_token' => 'secret-provider-token',
    ]);
    configureArtifactLinks();
    Http::fake(['*' => Http::response(['success' => true, 'result' => [
        ['selector' => 'body', 'results' => [['text' => 'Rendered']]],
    ]])]);

    app(RealRenderer::class)->render('acme', ARTIFACT_LINK_ID, '<div id="root"></div>');

    $url = (string) (Http::recorded()->first()[0]['url'] ?? '');
    $parts = explode('.', sharingAccessTokenFromUrl($url));

    expect($parts)->toHaveCount(5)
        ->and($parts[2])->toBe('system')
        ->and($parts[3])->toBe('p');
});

test('an_invalid_viewer_string_is_refused', function (string $viewer) {
    $link = new ArtifactAccessLink('fixed-secret');

    expect(fn (): ?string => $link->forArtifact('test-org', ARTIFACT_LINK_ID, now()->setTimestamp(1_700_000_000), null, $viewer, false))
        ->toThrow(RuntimeException::class);
})->with([
    'empty' => '',
    'too long' => str_repeat('a', 65),
    'dot' => 'a.b',
    'space' => 'a b',
    'slash' => 'a/b',
    'at sign' => 'user@example.com',
]);

test('no_viewer_keeps_the_legacy_three_part_token', function () {
    $link = new ArtifactAccessLink('fixed-secret', '.artfct.dev', 60);

    $url = (string) $link->forArtifact('test-org', ARTIFACT_LINK_ID, now()->setTimestamp(1_700_000_000));

    $message = ARTIFACT_LINK_ID.'.1700000000';
    $token = sharingAccessTokenFromUrl($url);

    expect(explode('.', $token))->toHaveCount(3)
        ->and($token)->toBe($message.'.'.hash_hmac('sha256', $message, 'fixed-secret'));
});

test('the_console_open_url_mints_a_viewer_bound_token_naming_the_member', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'test-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Hello</h1>', sharing: 'team');

    $response = test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open');

    $response->assertRedirect();

    $parts = explode('.', sharingAccessTokenFromUrl((string) $response->headers->get('Location')));

    expect($parts)->toHaveCount(5)
        ->and($parts[2])->toBe((string) $member->id)
        ->and($parts[3])->toBe('m');
});

test('the_viewer_show_url_mints_a_viewer_bound_token_naming_the_admin_with_scope_p', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'view-team']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => $admin->id,
        'title' => 'Quarterly report',
        'description' => null,
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    $response = test()->actingAs($admin)->get('/a/'.ARTIFACT_LINK_ID);
    $response->assertOk();

    $parts = explode('.', sharingAccessTokenFromUrl((string) $response->viewData('page')['props']['frameUrl']));

    expect($parts)->toHaveCount(5)
        ->and($parts[2])->toBe((string) $admin->id)
        ->and($parts[3])->toBe('p');
});
