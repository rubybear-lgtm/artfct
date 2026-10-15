<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\OperatorAuditEvent;
use App\Models\Team;
use App\Models\User;
use App\Policies\ConsolePolicy;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

beforeEach(fn () => $this->withoutVite());

const STAFF_ACCESS_ORG = 'org_staff_test';

function staffAccessStaff(string $email = 'staff@artfct.test'): User
{
    $staff = User::factory()->create(['email' => $email]);
    $staff->forceFill(['staff_at' => now()])->save();

    return $staff;
}

/**
 * The session data the AuthKit callback writes for a staff sign-in.
 *
 * @return array{staff_session: array{user_id: int, at: int}}
 */
function staffAccessSession(User $user, ?int $at = null): array
{
    return ['staff_session' => ['user_id' => $user->id, 'at' => $at ?? now()->timestamp]];
}

/**
 * Runs the rest of the test as if on another environment. The suite runs in
 * `testing`, where `staff_at` alone is enough on purpose; the session-flag
 * rules only bite elsewhere.
 */
function staffAccessEnvironment(string $environment): void
{
    app()['env'] = $environment;
}

/**
 * A sign-in client that is not the fake one, so the callback treats it as the
 * real hosted client, answering with a fixed organization.
 */
function staffAccessRealClient(string $email, ?string $organizationId): AuthKitClientContract
{
    return new class($email, $organizationId) implements AuthKitClientContract
    {
        public function __construct(private readonly string $email, private readonly ?string $organizationId) {}

        public function authenticateWithCode(string $code): AuthKitProfile
        {
            return new AuthKitProfile(
                externalId: 'workos-'.md5($this->email),
                provider: 'GoogleOAuth',
                email: $this->email,
                emailVerified: true,
                firstName: 'Staff',
                lastName: null,
                avatar: null,
                sessionId: null,
                organizationId: $this->organizationId,
            );
        }
    };
}

/**
 * Signs in through the callback on the real-client path and returns a test
 * helper that carries the resulting session into the next request.
 */
function staffAccessSignInThroughRealClient(string $email, ?string $organizationId): void
{
    app()->instance(AuthKitClientContract::class, staffAccessRealClient($email, $organizationId));

    test()->withSession(['authkit_state' => 'state-1'])
        ->get(route('authenticate', ['code' => 'code-1', 'state' => 'state-1']))
        ->assertRedirect();

    test()->assertAuthenticated();
}

/**
 * Carries the session the sign-in just created into the next request.
 */
function staffAccessSameSession(): TestCase
{
    return test()->withCookie((string) config('session.cookie'), session()->getId());
}

/**
 * @return list<array{string, string}>
 */
function staffAccessOpsRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'ops.'))
        ->map(fn ($route): array => [
            (string) collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD'),
            '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/'),
        ])
        ->values()
        ->all();
}

test('non_staff_gets_404_on_every_ops_route', function () {
    $routes = staffAccessOpsRoutes();
    expect($routes)->not->toBeEmpty();

    $member = memberOfTeam(Team::factory()->create(), TeamRole::Member);
    expect($member->staff_at)->toBeNull();

    // Once as `testing`, where staff_at alone decides, and once as another
    // environment with a forged staff-session flag, where it must still be the
    // missing staff_at that refuses.
    foreach ([['testing', false], ['staging', true]] as [$environment, $forgedFlag]) {
        staffAccessEnvironment($environment);

        foreach ($routes as [$method, $uri]) {
            $response = $this->actingAs($member)
                ->withSession($forgedFlag ? staffAccessSession($member) : [])
                ->call($method, $uri);

            expect($response->getStatusCode())->toBe(404, "{$method} {$uri} in {$environment} was not a 404");
        }
    }
});

test('team_admin_and_owner_are_not_staff', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();

    foreach ([$admin, $owner] as $user) {
        expect($user->isStaff())->toBeFalse()
            ->and($user->isCaseManager())->toBeFalse()
            ->and(Gate::forUser($user)->allows('operate'))->toBeFalse();
    }

    foreach (['testing', 'staging'] as $environment) {
        staffAccessEnvironment($environment);

        foreach ([$admin, $owner] as $user) {
            $this->actingAs($user)->withSession(staffAccessSession($user))
                ->get(route('ops.tenants.index'))
                ->assertNotFound();
        }
    }
});

test('staff_at_is_not_mass_assignable_or_settable_over_http', function () {
    $user = User::factory()->create();

    $user->fill(['staff_at' => now(), 'case_manager_at' => now()]);
    expect($user->staff_at)->toBeNull()->and($user->case_manager_at)->toBeNull();

    $user->update(['name' => 'Renamed', 'staff_at' => now(), 'case_manager_at' => now()]);
    expect($user->fresh()->name)->toBe('Renamed')
        ->and($user->fresh()->staff_at)->toBeNull()
        ->and($user->fresh()->case_manager_at)->toBeNull();

    $created = User::query()->create([
        'name' => 'New Person',
        'email' => 'new-person@example.test',
        'password' => null,
        'staff_at' => now(),
        'case_manager_at' => now(),
    ]);
    expect($created->fresh()->staff_at)->toBeNull();

    $this->actingAs($user)->patch(route('account.update'), [
        'name' => 'Over Http',
        'staff_at' => now()->toIso8601String(),
        'case_manager_at' => now()->toIso8601String(),
    ])->assertRedirect();

    expect($user->fresh()->name)->toBe('Over Http')
        ->and($user->fresh()->staff_at)->toBeNull()
        ->and($user->fresh()->case_manager_at)->toBeNull();

    // A guest creating an account through the sign-in routes cannot name it either.
    auth()->logout();
    $this->post(route('authkit.dev-login'), [
        'email' => 'sneaky@example.test',
        'provider' => 'GoogleOAuth',
        'staff_at' => now()->toIso8601String(),
    ]);
    $this->get(route('authenticate', [
        'code' => FakeAuthKitClient::codeFor(new AuthKitProfile('x-1', 'GoogleOAuth', 'sneaky2@example.test', true, 'Sneaky', null, null)),
        'staff_at' => now()->toIso8601String(),
    ]));

    expect(User::query()->whereNotNull('staff_at')->exists())->toBeFalse();
});

test('staff_has_no_team_policy_abilities_without_membership', function () {
    $team = Team::factory()->create(['slug' => 'other-tenant']);
    memberOfTeam($team, TeamRole::Admin);
    $staff = staffAccessStaff();

    expect(Gate::forUser($staff)->allows('operate'))->toBeTrue();

    foreach (['view', 'update', 'delete', 'leave', 'addMember', 'manageBilling', 'manageGovernance', 'manageDomains', 'transferOwnership', 'changeAuthMode'] as $ability) {
        expect(Gate::forUser($staff)->allows($ability, $team))->toBeFalse("staff was granted {$ability}");
    }

    $console = new ConsolePolicy;
    expect($console->view($staff, $team))->toBeFalse()
        ->and($console->revoke($staff, $team))->toBeFalse()
        ->and($console->export($staff, $team))->toBeFalse();

    staffAccessEnvironment('staging');

    $manualResolve = [
        route('console.index', ['team' => 'other-tenant']),
        route('console.export', ['team' => 'other-tenant']),
        route('console.open', ['team' => 'other-tenant', 'artifactId' => ARTIFACT_LINK_ID]),
        route('teams.admin.show', ['team' => 'other-tenant']),
        route('teams.billing.show', ['team' => 'other-tenant']),
        route('teams.audit.index', ['team' => 'other-tenant']),
        route('teams.governance.show', ['team' => 'other-tenant']),
    ];

    foreach ($manualResolve as $url) {
        $this->actingAs($staff)->withSession(staffAccessSession($staff))->get($url)->assertNotFound();
    }

    // The settings routes behind the membership middleware refuse the way
    // they do for any non-member.
    foreach ([route('teams.edit', ['team' => 'other-tenant']), route('teams.authentication.show', ['team' => 'other-tenant'])] as $url) {
        $status = $this->actingAs($staff)->withSession(staffAccessSession($staff))->get($url)->getStatusCode();
        expect($status)->toBeIn([403, 404]);
    }
});

test('staff_grant_and_revoke_write_audit_events', function () {
    $user = User::factory()->create(['email' => 'new-staff@artfct.test']);

    $this->artisan('staff:grant', ['email' => 'new-staff@artfct.test'])->assertSuccessful();

    $user->refresh();
    expect($user->staff_at)->not->toBeNull()
        ->and($user->isStaff())->toBeTrue()
        ->and($user->case_manager_at)->toBeNull();

    $granted = OperatorAuditEvent::query()->where('event_type', AuditEventType::StaffGranted)->sole();
    expect($granted->target)->toBe("user:{$user->id}")
        ->and($granted->staff_user_id)->toBeNull()
        ->and($granted->team_id)->toBeNull();
    expect(AuditEvent::query()->where('event_type', AuditEventType::StaffGranted)->sole()->team_id)->toBeNull();

    $this->artisan('staff:grant', ['email' => 'new-staff@artfct.test', '--case-manager' => true])->assertSuccessful();
    expect($user->fresh()->isCaseManager())->toBeTrue();

    $this->artisan('staff:revoke', ['email' => 'new-staff@artfct.test'])->assertSuccessful();

    $user->refresh();
    expect($user->staff_at)->toBeNull()
        ->and($user->case_manager_at)->toBeNull()
        ->and($user->isStaff())->toBeFalse();

    $revoked = OperatorAuditEvent::query()->where('event_type', AuditEventType::StaffRevoked)->sole();
    expect($revoked->target)->toBe("user:{$user->id}")->and($revoked->team_id)->toBeNull();
    expect(AuditEvent::query()->where('event_type', AuditEventType::StaffRevoked)->sole()->team_id)->toBeNull();

    // Revoking someone who is not staff is a warning, not an event.
    $before = [OperatorAuditEvent::query()->count(), AuditEvent::query()->count()];

    $this->artisan('staff:revoke', ['email' => 'new-staff@artfct.test'])->assertSuccessful();

    expect([OperatorAuditEvent::query()->count(), AuditEvent::query()->count()])->toBe($before);

    // Email lookup ignores case, and an unknown email fails with no event.
    $this->artisan('staff:grant', ['email' => '  New-Staff@ARTFCT.test '])->assertSuccessful();
    expect($user->fresh()->isStaff())->toBeTrue();
    $this->artisan('staff:revoke', ['email' => 'NEW-STAFF@artfct.test'])->assertSuccessful();
    expect($user->fresh()->isStaff())->toBeFalse();

    $before = [OperatorAuditEvent::query()->count(), AuditEvent::query()->count()];

    $this->artisan('staff:grant', ['email' => 'nobody@artfct.test'])->assertFailed();
    $this->artisan('staff:revoke', ['email' => 'nobody@artfct.test'])->assertFailed();

    expect([OperatorAuditEvent::query()->count(), AuditEvent::query()->count()])->toBe($before);
});

test('signed_out_request_to_ops_gets_404_not_a_redirect', function () {
    // The refusal must be the ordinary 404 and nothing else: in `production`
    // (where NoIndexOutsideProduction adds nothing) it carries no
    // X-Robots-Tag, exactly like a URL that does not exist.
    foreach (['testing', 'staging', 'production'] as $environment) {
        staffAccessEnvironment($environment);

        $response = $this->get('/ops');

        $response->assertNotFound();
        expect($response->headers->get('Location'))->toBeNull();

        if ($environment === 'production') {
            expect($response->headers->get('X-Robots-Tag'))->toBeNull()
                ->and($this->get('/zz-not-a-page')->headers->get('X-Robots-Tag'))->toBeNull();
        }

        $this->getJson('/ops')->assertNotFound();
    }

    $this->assertGuest();
});

test('dev_login_session_with_staff_at_gets_404_on_ops', function () {
    config(['services.workos.staff_organization_id' => STAFF_ACCESS_ORG]);
    $staff = staffAccessStaff('dev-staff@artfct.test');

    // The fake client is what dev login uses. Even a code that names the staff
    // organization must not produce a staff session.
    $code = FakeAuthKitClient::codeFor(new AuthKitProfile(
        externalId: 'dev-staff',
        provider: 'GoogleOAuth',
        email: 'dev-staff@artfct.test',
        emailVerified: true,
        firstName: 'Dev',
        lastName: null,
        avatar: null,
        organizationId: STAFF_ACCESS_ORG,
    ));

    $this->get(route('authenticate', ['code' => $code]))->assertRedirect();
    $this->assertAuthenticatedAs($staff);
    expect(session('staff_session'))->toBeNull();

    staffAccessEnvironment('staging');

    staffAccessSameSession()->get('/ops')->assertNotFound();
});

test('session_from_another_workos_organization_gets_404_on_ops', function () {
    config(['services.workos.staff_organization_id' => STAFF_ACCESS_ORG]);
    $staff = staffAccessStaff('other-org@artfct.test');

    staffAccessSignInThroughRealClient('other-org@artfct.test', 'org_some_customer');

    $this->assertAuthenticatedAs($staff);
    expect(session('staff_session'))->toBeNull();

    staffAccessEnvironment('staging');

    staffAccessSameSession()->get('/ops')->assertNotFound();
});

test('staff_org_session_with_staff_at_reaches_ops', function () {
    config(['services.workos.staff_organization_id' => STAFF_ACCESS_ORG]);
    $staff = staffAccessStaff('org-staff@artfct.test');

    staffAccessSignInThroughRealClient('org-staff@artfct.test', STAFF_ACCESS_ORG);

    $this->assertAuthenticatedAs($staff);
    expect(session('staff_session'))->toBe(['user_id' => $staff->id, 'at' => now()->timestamp]);

    // In production NoIndexOutsideProduction adds nothing, so the header on a
    // staff response has to come from the guard.
    staffAccessEnvironment('production');

    $response = staffAccessSameSession()->get('/ops');

    $response->assertOk()->assertSee('ok');
    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');

    staffAccessEnvironment('staging');

    // The staff flag is never shared with the page.
    staffAccessSameSession()->get(route('account.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $staff->id)
            ->missing('auth.user.staff_at')
            ->missing('auth.user.case_manager_at'));
});

test('callback_sets_staff_session_only_for_the_staff_organization_on_the_real_client', function (string $client, ?string $profileOrganization, ?string $configuredOrganization, bool $expected) {
    config(['services.workos.staff_organization_id' => $configuredOrganization]);

    if ($client === 'real') {
        staffAccessSignInThroughRealClient('callback@artfct.test', $profileOrganization);
    } else {
        $code = FakeAuthKitClient::codeFor(new AuthKitProfile('fake-1', 'GoogleOAuth', 'callback@artfct.test', true, 'Cb', null, null, null, $profileOrganization));
        $this->get(route('authenticate', ['code' => $code]))->assertRedirect();
    }

    $this->assertAuthenticated();
    $flag = session('staff_session');

    expect(is_array($flag) && $flag['user_id'] === auth()->id())->toBe($expected);
})->with([
    'staff organization on the real client' => ['real', STAFF_ACCESS_ORG, STAFF_ACCESS_ORG, true],
    'another organization' => ['real', 'org_some_customer', STAFF_ACCESS_ORG, false],
    'no organization' => ['real', null, STAFF_ACCESS_ORG, false],
    'empty organization against an unset staff organization' => ['real', '', null, false],
    'null organization against an unset staff organization' => ['real', null, null, false],
    'empty organization against an empty staff organization' => ['real', '', '', false],
    'staff organization named on the fake client' => ['fake', STAFF_ACCESS_ORG, STAFF_ACCESS_ORG, false],
]);

test('staff_non_member_is_refused_on_viewer_console_open_and_export', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'tenant-x', 'name' => 'Tenant X']);
    $member = memberOfTeam($team, TeamRole::Admin);
    $staff = staffAccessStaff();
    $secret = 'TENANT-X-CONFIDENTIAL-BODY';

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => $member->id,
        'owner_user_id' => (string) $member->id,
        'title' => 'Confidential plan',
        'description' => $secret,
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, "<h1>{$secret}</h1>", sharing: 'team', ownerUserId: (string) $member->id);

    $urls = [
        'viewer' => route('artifacts.show', ['artifactId' => ARTIFACT_LINK_ID]),
        'console open' => route('console.open', ['team' => $team->slug, 'artifactId' => ARTIFACT_LINK_ID]),
        'console export' => route('console.export', ['team' => $team->slug]),
    ];

    // Control: the tenant's own admin is served by the same routes, so the
    // refusals below are about membership and not about a broken fixture.
    $viewer = $this->actingAs($member)->get($urls['viewer']);
    $viewer->assertOk();
    expect($viewer->getContent())->toContain('Confidential plan');
    $this->actingAs($member)->get($urls['console open'])->assertRedirect();

    auth()->logout();
    staffAccessEnvironment('staging');

    foreach ($urls as $label => $url) {
        $response = $this->actingAs($staff)->withSession(staffAccessSession($staff))->get($url);

        expect($response->getStatusCode())->toBeIn([403, 404], "{$label} answered {$response->getStatusCode()} to a non-member staff user");
        expect((string) $response->getContent())->not->toContain($secret)->not->toContain('Confidential plan');
        expect($response->headers->get('Location'))->toBeNull();
    }
});

test('non_get_methods_on_ops_return_404_not_405', function () {
    $member = memberOfTeam(Team::factory()->create(), TeamRole::Member);

    foreach (['testing', 'staging', 'production'] as $environment) {
        staffAccessEnvironment($environment);

        foreach (['/ops', '/ops/anything', '/ops/tenants/1/limits'] as $uri) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                foreach ([null, $member] as $user) {
                    $request = $user === null ? $this : $this->actingAs($user);
                    $response = $request->call($method, $uri);

                    expect($response->getStatusCode())->toBe(404, "{$method} {$uri} in {$environment} answered {$response->getStatusCode()}");
                    expect($response->headers->has('Allow'))->toBeFalse("{$method} {$uri} in {$environment} sent an Allow header");
                }
            }
        }
    }
});

test('staff_session_for_another_user_is_refused', function () {
    $staff = staffAccessStaff();
    $other = staffAccessStaff('other-staff@artfct.test');

    staffAccessEnvironment('staging');

    // Control: the session written for this very user is accepted.
    $this->actingAs($staff)->withSession(staffAccessSession($staff))->get('/ops')->assertOk();

    $this->actingAs($staff)->withSession(staffAccessSession($other))->get('/ops')->assertNotFound();
});

test('staff_session_older_than_the_grant_is_refused', function () {
    $staff = staffAccessStaff();

    staffAccessEnvironment('staging');

    $signedInAt = now()->timestamp;

    $this->actingAs($staff)->withSession(staffAccessSession($staff, $signedInAt))->get('/ops')->assertOk();

    // Revoked and granted again after that sign-in: it has to be redone.
    $staff->forceFill(['staff_at' => now()->addMinutes(5)])->save();

    $this->actingAs($staff->fresh())->withSession(staffAccessSession($staff, $signedInAt))->get('/ops')->assertNotFound();
    $this->actingAs($staff->fresh())->withSession(staffAccessSession($staff, now()->addMinutes(6)->timestamp))->get('/ops')->assertOk();
});
