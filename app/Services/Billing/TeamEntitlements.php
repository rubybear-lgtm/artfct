<?php

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

/**
 * Single plan × role capability matrix for UI and backend authorization (RUB-529).
 *
 * Plan tiers: free, pro, team_trialing, team, business (Enterprise).
 * Roles: admin, member, viewer; bots never receive admin capabilities.
 *
 * Controllers and Inertia read this object instead of re-deriving rules.
 *
 * @phpstan-type NavItem array{key: string, visible: bool, locked: bool, label: string, reason: ?string}
 * @phpstan-type EntitlementPayload array{
 *     plan: string,
 *     planTier: string,
 *     role: ?string,
 *     isBot: bool,
 *     isAdmin: bool,
 *     capabilities: array<string, bool>,
 *     nav: list<NavItem>
 * }
 */
final class TeamEntitlements
{
    public const VIEW_ADMIN = 'view_admin';

    public const MANAGE_BILLING = 'manage_billing';

    public const MANAGE_MEMBERS = 'manage_members';

    public const VIEW_MEMBERS = 'view_members';

    public const USE_BOTS = 'use_bots';

    public const SEARCH_BY_DESCRIPTION = 'search_by_description';

    public const CUSTOM_HOSTNAME = 'custom_hostname';

    public const MANAGE_GOVERNANCE = 'manage_governance';

    public const MANAGE_AUTH = 'manage_auth';

    public const VIEW_AUDIT_LOG = 'view_audit_log';

    public const CREATE_API_TOKENS = 'create_api_tokens';

    /**
     * @param  array<string, bool>  $capabilities
     * @param  list<NavItem>  $nav
     */
    private function __construct(
        public readonly Team $team,
        public readonly User $user,
        public readonly string $planTier,
        public readonly ?TeamRole $role,
        public readonly bool $isBot,
        public readonly array $capabilities,
        public readonly array $nav,
    ) {}

    public static function resolve(Team $team, User $user): self
    {
        app(TeamTrialService::class)->expireIfDue($team);
        $team->refresh();

        $isBot = $user->isBot();
        $role = $isBot ? null : $user->teamRole($team);
        $isAdmin = ! $isBot && $role === TeamRole::Admin;
        $planTier = self::planTier($team);
        $paidFeatures = PlanGate::hasTeamFeatures($team);
        // Team trial and paid Team/Enterprise get human teammates; Pro and Free do not.
        $teammatesPlan = match ($planTier) {
            'team_trialing', 'team', 'business' => true,
            default => false,
        };
        $botPlan = PlanGate::hasBotAccess($team);
        $notViewer = $role !== null && $role !== TeamRole::Viewer;

        $canInviteByRole = $isAdmin
            || (
                $role === TeamRole::Member
                && (bool) config('teams.members_can_invite')
                && $role->hasPermission(TeamPermission::CreateInvitation)
            );

        // $notViewer / $canInviteByRole already require a non-bot human role.
        $capabilities = [
            self::VIEW_ADMIN => $isAdmin,
            self::MANAGE_BILLING => $isAdmin,
            self::MANAGE_MEMBERS => $teammatesPlan && $canInviteByRole,
            self::VIEW_MEMBERS => ! $isBot && $role !== null,
            self::USE_BOTS => $botPlan && $notViewer,
            self::SEARCH_BY_DESCRIPTION => $paidFeatures && ! $isBot,
            self::CUSTOM_HOSTNAME => $paidFeatures && $isAdmin,
            self::MANAGE_GOVERNANCE => $isAdmin,
            self::MANAGE_AUTH => $isAdmin,
            self::VIEW_AUDIT_LOG => $isAdmin,
            self::CREATE_API_TOKENS => $notViewer,
        ];

        $nav = self::navFor($capabilities, $planTier, $isAdmin, $isBot, $role);

        return new self($team, $user, $planTier, $role, $isBot, $capabilities, $nav);
    }

    public function can(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    /**
     * Role failures are 403; plan failures raise PlanGateException (plan_required).
     *
     * @throws PlanGateException
     */
    public function assertCan(string $capability): void
    {
        if ($this->can($capability)) {
            return;
        }

        $adminOnly = in_array($capability, [
            self::VIEW_ADMIN,
            self::MANAGE_BILLING,
            self::MANAGE_GOVERNANCE,
            self::MANAGE_AUTH,
            self::VIEW_AUDIT_LOG,
        ], true);

        if ($this->isBot || $this->role === null || $this->role === TeamRole::Viewer || ($adminOnly && $this->role !== TeamRole::Admin)) {
            abort(403, __('You do not have permission to do that.'));
        }

        // Admin on a plan that does not include the feature → upgrade prompt.
        $feature = match ($capability) {
            self::USE_BOTS => 'AI bot teammates',
            self::MANAGE_MEMBERS => 'Team members and invites',
            self::SEARCH_BY_DESCRIPTION => 'Search by description',
            self::CUSTOM_HOSTNAME => 'Custom web address',
            default => 'This feature',
        };

        $required = match ($capability) {
            self::MANAGE_MEMBERS => 'Team',
            self::USE_BOTS, self::SEARCH_BY_DESCRIPTION, self::CUSTOM_HOSTNAME => 'Pro or Team',
            default => 'a higher',
        };

        throw new PlanGateException($feature, $required);
    }

    /**
     * @return EntitlementPayload
     */
    public function toArray(): array
    {
        return [
            'plan' => ($this->team->plan ?? Plan::Free)->value,
            'planTier' => $this->planTier,
            'role' => $this->role?->value,
            'isBot' => $this->isBot,
            'isAdmin' => $this->can(self::VIEW_ADMIN),
            'capabilities' => $this->capabilities,
            'nav' => $this->nav,
        ];
    }

    public static function planTier(Team $team): string
    {
        if (PlanGate::isEnterprise($team)) {
            return 'business';
        }

        if (PlanGate::is($team, Plan::Pro)) {
            return 'pro';
        }

        if (PlanGate::is($team, Plan::Team)) {
            $onTrial = $team->trial_ends_at !== null && $team->trial_ends_at->isFuture();

            return $onTrial ? 'team_trialing' : 'team';
        }

        return 'free';
    }

    /**
     * @param  array<string, bool>  $capabilities
     * @return list<NavItem>
     */
    private static function navFor(array $capabilities, string $planTier, bool $isAdmin, bool $isBot, ?TeamRole $role): array
    {
        if ($isBot || $role === null) {
            return [];
        }

        $items = [];

        if ($isAdmin) {
            $items[] = [
                'key' => 'admin',
                'visible' => true,
                'locked' => false,
                'label' => 'Admin',
                'reason' => null,
            ];
        }

        // Members / invites: Team+ unlocked; Free/Pro locked for admins (upgrade prompt).
        if ($isAdmin) {
            $membersUnlocked = $capabilities[self::MANAGE_MEMBERS];
            $items[] = [
                'key' => 'members',
                'visible' => true,
                'locked' => ! $membersUnlocked,
                'label' => 'Members',
                'reason' => $membersUnlocked ? null : 'Upgrade to Team to invite teammates.',
            ];
        } elseif ($capabilities[self::VIEW_MEMBERS]) {
            $items[] = [
                'key' => 'members',
                'visible' => true,
                'locked' => false,
                'label' => 'Team',
                'reason' => null,
            ];
        }

        // Bots: Pro+ unlocked; Free locked for conversion.
        if ($isAdmin || $capabilities[self::CREATE_API_TOKENS]) {
            $botsUnlocked = $capabilities[self::USE_BOTS];
            $items[] = [
                'key' => 'bots',
                'visible' => true,
                'locked' => ! $botsUnlocked,
                'label' => 'AI bot teammates',
                'reason' => $botsUnlocked ? null : 'Upgrade to Pro or Team to connect AI bot teammates.',
            ];
        }

        if ($isAdmin) {
            $items[] = [
                'key' => 'billing',
                'visible' => true,
                'locked' => false,
                'label' => 'Billing',
                'reason' => null,
            ];
        }

        // Unused intentionally — keeps planTier in the signature for matrix tests.
        unset($planTier);

        return $items;
    }
}
