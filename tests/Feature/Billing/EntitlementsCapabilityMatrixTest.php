<?php

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TeamEntitlements;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * RUB-529: plan × role capability matrix — single source of truth.
 *
 * @return array<string, bool>
 */
function expectedCapabilities(string $planTier, string $role): array
{
    $isAdmin = $role === 'admin';
    $isMember = $role === 'member';
    $isViewer = $role === 'viewer';
    $isBot = $role === 'bot';
    $paid = in_array($planTier, ['pro', 'team_trialing', 'team', 'business'], true);
    $teammates = in_array($planTier, ['team_trialing', 'team', 'business'], true);
    $notViewer = ! $isViewer && ! $isBot;

    return [
        TeamEntitlements::VIEW_ADMIN => $isAdmin,
        TeamEntitlements::MANAGE_BILLING => $isAdmin,
        // Default config allows members to invite on Team+.
        TeamEntitlements::MANAGE_MEMBERS => $teammates && ($isAdmin || $isMember),
        TeamEntitlements::VIEW_MEMBERS => ! $isBot,
        TeamEntitlements::USE_BOTS => $paid && $notViewer,
        TeamEntitlements::SEARCH_BY_DESCRIPTION => $paid && ! $isBot,
        TeamEntitlements::CUSTOM_HOSTNAME => $paid && $isAdmin,
        TeamEntitlements::MANAGE_GOVERNANCE => $isAdmin,
        TeamEntitlements::MANAGE_AUTH => $isAdmin,
        TeamEntitlements::VIEW_AUDIT_LOG => $isAdmin,
        TeamEntitlements::CREATE_API_TOKENS => $notViewer,
    ];
}

/**
 * @return array{0: Plan, 1: string, 2: array<string, mixed>}
 */
function planFixtures(): array
{
    return [
        [Plan::Free, 'free', []],
        [Plan::Pro, 'pro', []],
        [Plan::Team, 'team_trialing', [
            'trial_started_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(89),
        ]],
        [Plan::Team, 'team', ['trial_ends_at' => null, 'trial_started_at' => null]],
        [Plan::Enterprise, 'business', []],
    ];
}

test('capability_matrix_covers_every_plan_and_role', function () {
    foreach (planFixtures() as [$plan, $tier, $attrs]) {
        $team = Team::factory()->create(array_merge(['plan' => $plan], $attrs));

        foreach ([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer] as $role) {
            $user = memberOfTeam($team, $role);
            $entitlements = TeamEntitlements::resolve($team, $user);

            expect($entitlements->planTier)->toBe($tier)
                ->and($entitlements->capabilities)->toBe(expectedCapabilities($tier, $role->value));
        }

        $bot = User::factory()->bot()->create();
        $team->memberships()->create(['user_id' => $bot->id, 'role' => TeamRole::Member]);
        $botEntitlements = TeamEntitlements::resolve($team, $bot);

        expect($botEntitlements->isBot)->toBeTrue()
            ->and($botEntitlements->can(TeamEntitlements::VIEW_ADMIN))->toBeFalse()
            ->and($botEntitlements->can(TeamEntitlements::MANAGE_BILLING))->toBeFalse()
            ->and($botEntitlements->can(TeamEntitlements::USE_BOTS))->toBeFalse()
            ->and($botEntitlements->nav)->toBe([]);
    }
});

test('member_gets_403_on_admin_and_billing_routes', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'ent-member-403']);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get(route('teams.admin.show', $team))->assertForbidden();
    test()->actingAs($member)->get(route('teams.billing.show', $team))->assertForbidden();
});

test('free_admin_gets_plan_required_when_creating_a_bot', function () {
    configureOrgJwt();
    $team = Team::factory()->create(['plan' => Plan::Free, 'slug' => 'ent-free-bot']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->postJson(route('teams.bots.store', $team), [
        'name' => 'Nope',
        'scopes' => ['artifacts:read'],
    ])
        ->assertForbidden()
        ->assertJsonPath('errorCode', 'plan_required');
});

test('inertia_shares_entitlements_and_nav_per_plan_and_role', function () {
    // plan, role, tier, expectAdminNav, expectBotsLocked, expectMembersLocked
    $cases = [
        [Plan::Free, TeamRole::Admin, 'free', true, true, true],
        [Plan::Pro, TeamRole::Admin, 'pro', true, false, true],
        [Plan::Team, TeamRole::Admin, 'team', true, false, false],
        [Plan::Team, TeamRole::Member, 'team', false, false, false],
    ];

    foreach ($cases as [$plan, $role, $tier, $expectAdminNav, $expectBotsLocked, $expectMembersLocked]) {
        $team = Team::factory()->create([
            'plan' => $plan,
            'slug' => 'nav-'.$tier.'-'.$role->value.'-'.uniqid(),
        ]);
        $user = memberOfTeam($team, $role);
        $user->forceFill(['current_team_id' => $team->id])->save();

        test()->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entitlements.planTier', $tier)
                ->where('entitlements.isAdmin', $expectAdminNav)
                ->where('entitlements.capabilities.view_admin', $expectAdminNav)
                ->where('entitlements.capabilities.use_bots', $plan !== Plan::Free && $role !== TeamRole::Viewer)
                ->has('entitlements.nav')
                ->where('entitlements.nav', function ($nav) use ($expectAdminNav, $expectBotsLocked, $expectMembersLocked, $role) {
                    $items = collect($nav);
                    $admin = $items->firstWhere('key', 'admin');
                    $bots = $items->firstWhere('key', 'bots');
                    $members = $items->firstWhere('key', 'members');
                    $billing = $items->firstWhere('key', 'billing');

                    if ($expectAdminNav) {
                        expect($admin)->not->toBeNull()
                            ->and($billing)->not->toBeNull()
                            ->and($members['locked'] ?? null)->toBe($expectMembersLocked)
                            ->and($bots['locked'] ?? null)->toBe($expectBotsLocked);
                    } else {
                        expect($admin)->toBeNull()
                            ->and($billing)->toBeNull();
                        if ($role === TeamRole::Member) {
                            expect($members)->not->toBeNull()
                                ->and($members['locked'])->toBeFalse();
                        }
                    }

                    return true;
                }));
    }
});
