<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\McpConnection;
use App\Models\OperatorAuditEvent;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Billing\UsageContract;
use App\Services\Operator\TenantDetail;
use App\Services\Polis\PolisAdminClient;
use Illuminate\Http\Client\ConnectionException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function tenantDetailStaff(): User
{
    $staff = User::factory()->create();
    $staff->forceFill(['staff_at' => now()])->save();

    return $staff;
}

/**
 * A Worker usage double that answers with the given figures or throws.
 *
 * @param  array<string, mixed>|Throwable  $answer
 */
function tenantDetailUsage(array|Throwable $answer): void
{
    app()->instance(UsageContract::class, new class($answer) implements UsageContract
    {
        /** @param array<string, mixed>|Throwable $answer */
        public function __construct(private readonly array|Throwable $answer) {}

        public function currentUsage(string $orgSlug): array
        {
            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return $this->answer;
        }
    });
}

test('tenant_detail_shows_worker_usage_and_limits', function () {
    $team = Team::factory()->create(['slug' => 'usage-tenant']);
    $team->forceFill([
        'plan' => 'team',
        'stripe_customer_id' => 'cus_Usage1',
        'stripe_subscription_id' => 'sub_Usage1',
        'seats_billed' => 7,
        'region' => 'eu',
    ])->save();

    $owner = memberOfTeam($team, TeamRole::Admin);
    memberOfTeam($team, TeamRole::Member);
    $team->forceFill(['owner_user_id' => $owner->id])->save();
    TeamInvitation::factory()->create(['team_id' => $team->id]);
    TeamInvitation::factory()->create(['team_id' => $team->id, 'expires_at' => now()->subDay()]);
    McpConnection::factory()->create(['team_id' => $team->id, 'last_used_at' => now()->subHour()]);
    McpConnection::factory()->revoked()->create(['team_id' => $team->id]);
    OrgToken::factory()->create(['team_id' => $team->id, 'expires_at' => now()->addDay()]);
    OrgToken::factory()->revoked()->create(['team_id' => $team->id]);

    tenantDetailUsage([
        'storage_bytes' => 123456,
        'artifacts_this_period' => 42,
        'render_minutes_this_period' => 0,
        'period_start' => '2026-10-01T00:00:00Z',
        'period_end' => '2026-11-01T00:00:00Z',
        'limits' => ['storage_bytes' => 1000000, 'artifacts_per_month' => 500],
    ]);

    $this->actingAs(tenantDetailStaff())
        ->get(route('ops.tenants.show', $team->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ops/tenants/show')
            ->where('tenant.slug', 'usage-tenant')
            ->where('tenant.plan', 'team')
            ->where('tenant.seats_billed', 7)
            ->where('tenant.owner', $owner->name)
            ->where('tenant.stripe_customer_url', 'https://dashboard.stripe.com/customers/cus_Usage1')
            ->where('tenant.stripe_subscription_url', 'https://dashboard.stripe.com/subscriptions/sub_Usage1')
            ->where('members.total', 2)
            ->where('members.pending_invitations', 1)
            ->has('members.admins', 1)
            ->where('members.admins.0.email', $owner->email)
            ->where('connections.mcp.active', 1)
            ->where('connections.org_tokens.active', 1)
            ->where('connections.org_tokens.last_used_at', null)
            ->missing('sso')
            ->missing('usage')
            ->loadDeferredProps(fn (Assert $reloaded) => $reloaded
                ->where('sso.state', 'unavailable')
                ->where('sso.connections', [])
                ->where('usage.storage_bytes', 123456)
                ->where('usage.artifacts_this_period', 42)
                ->where('usage.period_end', '2026-11-01T00:00:00Z')
                ->where('usage.limits.storage_bytes', 1000000)
                ->where('usage.limits.artifacts_per_month', 500)));

    config(['services.stripe.secret' => 'sk_test_123']);

    $this->actingAs(tenantDetailStaff())
        ->get(route('ops.tenants.show', $team->slug))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.stripe_customer_url', 'https://dashboard.stripe.com/test/customers/cus_Usage1'));
});

test('tenant_detail_renders_when_worker_usage_fails', function () {
    $team = Team::factory()->create(['slug' => 'worker-down']);
    memberOfTeam($team, TeamRole::Admin);

    tenantDetailUsage(new ConnectionException('Worker unreachable'));

    $this->actingAs(tenantDetailStaff())
        ->get(route('ops.tenants.show', $team->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ops/tenants/show')
            ->where('tenant.slug', 'worker-down')
            ->where('members.total', 1)
            ->has('events')
            ->loadDeferredProps(fn (Assert $reloaded) => $reloaded
                ->where('usage.error', 'the Worker could not be reached.')
                ->missing('usage.storage_bytes')));
});

test('tenant_detail_renders_when_worker_usage_is_malformed', function () {
    $team = Team::factory()->create(['slug' => 'worker-odd']);
    memberOfTeam($team, TeamRole::Admin);

    // Reachable, but answers without the keys the contract promises.
    tenantDetailUsage(['render_minutes_this_period' => 0]);

    $this->actingAs(tenantDetailStaff())
        ->get(route('ops.tenants.show', $team->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.slug', 'worker-odd')
            ->where('members.total', 1)
            ->loadDeferredProps(fn (Assert $reloaded) => $reloaded
                ->has('usage.error')
                ->missing('usage.storage_bytes')));
});

test('tenant_detail_renders_when_polis_is_unreachable', function () {
    $team = Team::factory()->create(['slug' => 'polis-down']);
    memberOfTeam($team, TeamRole::Admin);

    tenantDetailUsage(['storage_bytes' => 10, 'artifacts_this_period' => 2, 'render_minutes_this_period' => 0]);
    app()->instance(PolisAdminClient::class, new class extends PolisAdminClient
    {
        public function connections(string $tenant): array
        {
            throw new ConnectionException('Polis unreachable');
        }
    });

    $this->actingAs(tenantDetailStaff())
        ->get(route('ops.tenants.show', $team->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.slug', 'polis-down')
            ->where('members.total', 1)
            ->missing('sso')
            ->loadDeferredProps(fn (Assert $reloaded) => $reloaded
                ->where('sso.state', 'unavailable')
                ->where('usage.storage_bytes', 10)
                ->where('usage.artifacts_this_period', 2)));
});

test('tenant_detail_caps_the_admin_list_but_keeps_the_real_member_count', function () {
    $team = Team::factory()->create(['slug' => 'many-admins']);

    foreach (range(1, TenantDetail::ADMIN_LIMIT + 2) as $number) {
        memberOfTeam($team, TeamRole::Admin);
    }

    $response = $this->actingAs(tenantDetailStaff())->get(route('ops.tenants.show', $team->slug))->assertOk();
    $members = $response->viewData('page')['props']['members'];

    expect($members['total'])->toBe(TenantDetail::ADMIN_LIMIT + 2)
        ->and($members['admins'])->toHaveCount(TenantDetail::ADMIN_LIMIT);
});

test('tenant_detail_reaches_a_soft_deleted_tenant_and_404s_on_an_unknown_slug', function () {
    $team = Team::factory()->create(['slug' => 'gone-tenant']);
    $team->delete();
    $staff = tenantDetailStaff();

    $this->actingAs($staff)
        ->get(route('ops.tenants.show', 'gone-tenant'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tenant.deleted_at', fn ($value) => $value !== null));

    $this->actingAs($staff)->get(route('ops.tenants.show', 'no-such-tenant'))->assertNotFound();
});

test('tenant_view_is_logged_once_per_hour_through_the_page', function () {
    $team = Team::factory()->create(['slug' => 'viewed-tenant']);
    $staff = tenantDetailStaff();
    tenantDetailUsage(['storage_bytes' => 1, 'artifacts_this_period' => 1, 'render_minutes_this_period' => 0]);

    $views = fn (): int => OperatorAuditEvent::query()
        ->where('event_type', AuditEventType::OperatorTenantViewed)
        ->where('team_id', $team->id)
        ->count();

    $this->actingAs($staff)->get(route('ops.tenants.show', $team->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reloaded) => $reloaded->has('usage')));

    expect($views())->toBe(1);

    $this->actingAs($staff)->get(route('ops.tenants.show', $team->slug))->assertOk();

    expect($views())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->where('team_id', $team->id)->count())->toBe(1);

    $this->travel(61)->minutes();
    $this->actingAs($staff)->get(route('ops.tenants.show', $team->slug))->assertOk();

    expect($views())->toBe(2);
});

test('a_hand_made_usage_partial_request_is_still_logged', function () {
    $team = Team::factory()->create(['slug' => 'partial-tenant']);
    tenantDetailUsage(['storage_bytes' => 1, 'artifacts_this_period' => 1, 'render_minutes_this_period' => 0]);

    $views = fn (): int => OperatorAuditEvent::query()
        ->where('event_type', AuditEventType::OperatorTenantViewed)
        ->where('team_id', $team->id)
        ->count();

    // A staff user who has never viewed this tenant asks for its usage with
    // the partial-reload headers and nothing else. Reading it is a view.
    $intruder = tenantDetailStaff();
    $version = (string) $this->actingAs(tenantDetailStaff())
        ->get(route('ops.tenants.index'))
        ->viewData('page')['version'];

    expect($views())->toBe(0);

    $this->actingAs($intruder)->get(route('ops.tenants.show', $team->slug), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'ops/tenants/show',
        'X-Inertia-Partial-Data' => 'usage',
    ])->assertOk()->assertJsonPath('props.usage.storage_bytes', 1);

    expect($views())->toBe(1)
        ->and(OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->sole()->staff_user_id)->toBe($intruder->id);

    // The page's own load followed by its deferred follow-up is one view.
    $staff = tenantDetailStaff();

    $this->actingAs($staff)->get(route('ops.tenants.show', $team->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reloaded) => $reloaded->has('usage')));

    expect($views())->toBe(2)
        ->and(OperatorAuditEvent::query()->where('staff_user_id', $staff->id)->count())->toBe(1);
});

test('staff_who_is_a_member_is_attributed_as_member_on_team_routes_and_staff_on_ops', function () {
    $team = Team::factory()->create(['slug' => 'dual-role', 'name' => 'Dual Role']);
    $staff = tenantDetailStaff();
    $team->memberships()->create(['user_id' => $staff->id, 'role' => TeamRole::Admin]);
    $team->forceFill(['owner_user_id' => $staff->id])->save();

    $this->actingAs($staff)->get(route('ops.tenants.show', $team->slug))->assertOk();

    $viewed = OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->sole();
    $tenantCopy = AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->sole();

    expect($viewed->staff_user_id)->toBe($staff->id)
        ->and($viewed->team_id)->toBe($team->id)
        ->and($tenantCopy->actor)->toBe("staff:{$staff->id}");

    $this->actingAs($staff)
        ->patch(route('teams.update', $team->slug), ['name' => 'Dual Role Renamed'])
        ->assertRedirect();

    $renamed = AuditEvent::query()->where('event_type', AuditEventType::TeamRenamed)->sole();

    expect($renamed->actor)->toBe((string) $staff->id)
        ->and($renamed->actor)->not->toStartWith('staff');

    $this->actingAs($staff)->get(route('ops.tenants.show', $team->slug))
        ->assertInertia(function (Assert $page) use ($staff) {
            $page->where('events', function ($events) use ($staff): bool {
                $byType = collect($events)->keyBy('type');

                return $byType['team.renamed']['actor_label'] === $staff->name
                    && $byType['operator.tenant_viewed']['actor_label'] === 'artfct staff';
            });
        });
});
