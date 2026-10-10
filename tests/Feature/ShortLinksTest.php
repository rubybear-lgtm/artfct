<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Contracts\PublicArtifactSource;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Artifacts\FakePublicArtifactSource;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * RUB-439: `/a/{id}` is the one short link for every sharing level. An
 * anonymous visitor sees a public artifact there; a team or private one sends
 * them to sign in and back; a signed-in visitor sees their own team's artifact
 * as before and a public one in its public mode; and a foreign or missing id
 * is the same 404 (signed in) or the same sign-in redirect (signed out), so the
 * route is never an existence oracle. Version links are `/a/{id}/v/{n}`, and
 * every surface hands out the short link, public artifacts included.
 */

/** A 13-character base36 permanent id: the shape a new artifact has. */
const SHORT_LINK_ID = 'abc123def4567';

/** A 32-character lowercase hex id, the other permanent shape. */
const SHORT_LINK_LEGACY_ID = '0123456789abcdef0123456789abcdef';

/**
 * @return array<string, mixed>
 */
function shortLinkProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/**
 * Seed the Worker's public read for one artifact.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedShortLinkPublicArtifact(string $artifactId, string $orgSlug = 'public-org', array $overrides = []): void
{
    /** @var FakePublicArtifactSource $public */
    $public = app(PublicArtifactSource::class);
    $public->seed($artifactId, $orgSlug, array_merge([
        'title' => 'Public report',
        'description' => 'A public report.',
        'version' => 1,
        'version_count' => 1,
        'updated_at' => now()->subDay()->toIso8601String(),
        'edit_access' => 'view',
    ], $overrides));
}

test('an anonymous visitor views a public artifact at the short link', function () {
    config(['services.artifact_access.origin_suffix' => '.artfct.dev']);
    seedShortLinkPublicArtifact(SHORT_LINK_ID);

    $response = test()->get('/a/'.SHORT_LINK_ID);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('artifacts/show')
        ->where('publicView', true)
        ->where('canSignInToEdit', false)
        ->where('artifact.id', SHORT_LINK_ID)
        ->where('artifact.title', 'Public report')
        ->where('artifact.sharing', 'public')
        // The owner's name is hidden and no Download is offered in public mode.
        ->where('artifact.ownerName', null)
        ->where('downloadUrl', null)
        ->where('selectedVersion', null));

    $props = shortLinkProps($response);

    expect($props['frameUrl'])
        ->toBe('https://public-org--'.SHORT_LINK_ID.'.artfct.dev/p/'.SHORT_LINK_ID.'/')
        ->and($props['openUrl'])->toBe($props['frameUrl'])
        ->and($props['viewerUrl'])->toBe(route('artifacts.show', ['artifactId' => SHORT_LINK_ID]));
});

test('a signed-out visitor of a team artifact is sent to sign in and back', function () {
    // Nothing is seeded public: the Worker answers 404 for a team artifact, so
    // the visitor gets the single sign-in answer rather than a dead end.
    $response = test()->get('/a/'.SHORT_LINK_ID);

    $response->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', route('artifacts.show', ['artifactId' => SHORT_LINK_ID]));
});

test('an anonymous visitor of an unknown id is sent to sign in, not told it is missing', function () {
    test()->get('/a/'.SHORT_LINK_LEGACY_ID)->assertRedirect(route('login'));
});

test('a signed-in non-member gets the same 404 as a random id', function () {
    $victimTeam = Team::factory()->create(['slug' => 'short-victim']);

    // The directory fake serves every org, so pin it to the victim's: the
    // outsider's own teams must not resolve the artifact, which is what makes
    // the 404 meaningful.
    $directory = new class extends FakeArtifactDirectory
    {
        public function getArtifact(string $orgSlug, string $artifactId): ?array
        {
            return $orgSlug === 'short-victim' ? parent::getArtifact($orgSlug, $artifactId) : null;
        }
    };
    $directory->seedArtifact([
        'id' => SHORT_LINK_ID,
        'org_id' => $victimTeam->slug,
        'user_id' => 1,
        'title' => 'Victim report',
        'description' => null,
        'content_hash' => md5(SHORT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);
    app()->instance(ArtifactDirectory::class, $directory);

    $outsider = memberOfTeam(Team::factory()->create(['slug' => 'short-outsider']), TeamRole::Member);

    test()->actingAs($outsider)->get('/a/'.SHORT_LINK_ID)->assertNotFound();
    test()->actingAs($outsider)->get('/a/'.SHORT_LINK_LEGACY_ID)->assertNotFound();
});

test('a signed-in member of another team still sees a public artifact', function () {
    seedShortLinkPublicArtifact(SHORT_LINK_ID);
    $other = memberOfTeam(Team::factory()->create(['slug' => 'short-other']), TeamRole::Member);

    test()->actingAs($other)->get('/a/'.SHORT_LINK_ID)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('publicView', true)
            ->where('artifact.title', 'Public report'));
});

test('a signed-out visitor of a public artifact that allows editing is offered a sign in to edit', function () {
    seedShortLinkPublicArtifact(SHORT_LINK_ID, 'edit-org', ['edit_access' => 'edit']);

    $response = test()->get('/a/'.SHORT_LINK_ID);

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('publicView', true)
        ->where('canSignInToEdit', true)
        ->where('artifact.editAccess', 'edit'));

    // The sign-in link returns the visitor to this page.
    $response->assertSessionHas('url.intended', route('artifacts.show', ['artifactId' => SHORT_LINK_ID]));
});

test('a signed-in visitor of a public artifact that allows editing gets no sign in to edit', function () {
    seedShortLinkPublicArtifact(SHORT_LINK_ID, 'edit-org', ['edit_access' => 'edit']);
    $other = memberOfTeam(Team::factory()->create(['slug' => 'short-edit-other']), TeamRole::Member);

    test()->actingAs($other)->get('/a/'.SHORT_LINK_ID)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('publicView', true)
            ->where('canSignInToEdit', false));
});

test('the version route frames the named version of a public artifact', function () {
    config(['services.artifact_access.origin_suffix' => '.artfct.dev']);
    seedShortLinkPublicArtifact(SHORT_LINK_ID, 'version-org', ['version' => 3, 'version_count' => 3]);

    $response = test()->get('/a/'.SHORT_LINK_ID.'/v/2');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('publicView', true)
        ->where('selectedVersion', 2)
        ->has('versions', 3));

    expect(shortLinkProps($response)['frameUrl'])
        ->toBe('https://version-org--'.SHORT_LINK_ID.'.artfct.dev/p/'.SHORT_LINK_ID.'/v:2/')
        ->and(shortLinkProps($response)['viewerUrl'])
        ->toBe(route('artifacts.version', ['artifactId' => SHORT_LINK_ID, 'version' => 2]));
});

test('the version route frames the named version for a member too', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'short-member-version']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => SHORT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => $member->id,
        'title' => 'Versioned report',
        'description' => null,
        'content_hash' => md5(SHORT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $member->id,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);
    $directory->seedVersions(SHORT_LINK_ID, [
        ['version' => 3, 'created_at' => now()->subDay()->toIso8601String(), 'current' => true],
        ['version' => 2, 'created_at' => now()->subDays(2)->toIso8601String(), 'current' => false],
        ['version' => 1, 'created_at' => now()->subDays(3)->toIso8601String(), 'current' => false],
    ], 3);

    $response = test()->actingAs($member)->get('/a/'.SHORT_LINK_ID.'/v/2');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('publicView', false)
        ->where('selectedVersion', 2));

    expect(shortLinkProps($response)['frameUrl'])
        ->toStartWith('https://short-member-version--'.SHORT_LINK_ID.'.artfct.dev/p/'.SHORT_LINK_ID.'/v:2/?token=');
});

test('the console open route redirects to the short link', function () {
    $team = Team::factory()->create(['slug' => 'short-console']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, SHORT_LINK_ID, '<h1>Short console</h1>');

    test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".SHORT_LINK_ID.'/open')
        ->assertRedirect(route('artifacts.show', ['artifactId' => SHORT_LINK_ID]));

    test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".SHORT_LINK_ID.'/open?version=2')
        ->assertRedirect(route('artifacts.version', ['artifactId' => SHORT_LINK_ID, 'version' => 2]));
});

test('every permanent tier is handed the short link, public included', function () {
    URL::forceRootUrl('https://artfct.dev');
    URL::forceScheme('https');

    $id = SHORT_LINK_ID;

    expect(ArtifactViewLink::forArtifact('acme', $id, 'public'))->toBe('https://artfct.dev/a/'.$id)
        ->and(ArtifactViewLink::forArtifact('acme', $id, 'secure'))->toBe('https://artfct.dev/a/'.$id)
        ->and(ArtifactViewLink::forArtifact('acme', $id, null))->toBe('https://artfct.dev/a/'.$id)
        ->and(ArtifactViewLink::forArtifact('acme', $id, 'public', null, 2))->toBe('https://artfct.dev/a/'.$id.'/v/2')
        ->and(ArtifactViewLink::forArtifact('acme', $id, 'ephemeral'))->toBe('https://artfct.dev/p/'.$id);
});

test('the short link stays within the length budget', function () {
    URL::forceRootUrl('https://artfct.dev');
    URL::forceScheme('https');

    // A 13-character id is the longest new shape: 22 characters of
    // scheme+host+path plus 13 of id is 35.
    expect(strlen('https://artfct.dev/a/'.SHORT_LINK_ID))->toBeLessThanOrEqual(35)
        ->and(strlen(route('artifacts.show', ['artifactId' => SHORT_LINK_ID])))->toBeLessThanOrEqual(35);
});
