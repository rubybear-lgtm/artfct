import { Head, Link, usePage } from '@inertiajs/react';

import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import teamRoutes from '@/routes/teams';
import type { SharedProps } from '@/types/shared';

type PlanName = 'free' | 'pro' | 'team' | 'enterprise';

interface Props {
    team: {
        slug: string;
        name: string;
        plan: PlanName;
        isEnterprise: boolean;
    };
    members: { total: number; admins: number };
    pendingInvitations: number;
    connections: { active: number; total?: number };
}

const PLAN_LABELS: Record<PlanName, string> = {
    free: 'Free',
    pro: 'Pro',
    team: 'Team',
    enterprise: 'Enterprise',
};

export default function TeamAdmin({
    team,
    members,
    pendingInvitations,
    connections,
}: Props) {
    const destinations = [
        {
            key: 'members',
            title: 'Members and invitations',
            description:
                'Invite people, change what they can do and remove access.',
            href: teamRoutes.edit.url({ team: team.slug }),
        },
        {
            key: 'connections',
            title: 'AI tool connections',
            description:
                'See which AI tools your team has connected, and disconnect any you do not recognize.',
            href: teamRoutes.mcpConnections.index.url({ team: team.slug }),
        },
        {
            key: 'tokens',
            title: 'Agent & API tokens',
            description:
                'Create agent tokens for AI assistants and API tokens for your scripts.',
            href: teamRoutes.tokens.index.url({ team: team.slug }),
        },
        {
            key: 'billing',
            title: 'Billing and usage',
            description:
                'Your plan, what you are using, invoices and payment method.',
            href: teamRoutes.billing.show.url({ team: team.slug }),
        },
        {
            key: 'authentication',
            title: 'Sign-in and security',
            description:
                'Choose how your team signs in and which sign-in methods you accept.',
            href: teamRoutes.authentication.show.url({ team: team.slug }),
        },
        {
            key: 'governance',
            title: 'Retention and legal holds',
            description:
                'Set how long artifacts are kept and protect the ones under review.',
            href: teamRoutes.governance.show.url({ team: team.slug }),
        },
        {
            key: 'audit',
            title: 'Audit log',
            description: 'Every admin action, who took it and when.',
            href: teamRoutes.audit.index.url({ team: team.slug }),
        },
    ];

    return (
        <>
            <Head title={`Admin · ${team.name}`} />
            <header className="max-w-2xl">
                <h1 className="font-serif text-3xl tracking-tight text-foreground">
                    Admin
                </h1>
                <p className="mt-2 text-muted-foreground">
                    Manage {team.name}: people, plan, sign-in and records.
                </p>
            </header>

            <dl className="mt-8 flex flex-wrap gap-x-12 gap-y-6 border-t border-border pt-6">
                <div>
                    <dt className="eyebrow">Members</dt>
                    <dd className="mt-1 font-serif text-3xl tabular-nums">
                        {members.total}
                    </dd>
                </div>
                <div>
                    <dt className="eyebrow">Pending invitations</dt>
                    <dd className="mt-1 font-serif text-3xl tabular-nums">
                        {pendingInvitations}
                    </dd>
                </div>
                <div>
                    <dt className="eyebrow">Active AI tools</dt>
                    <dd className="mt-1 font-serif text-3xl tabular-nums">
                        {connections.active}
                    </dd>
                    {connections.total !== undefined &&
                        connections.total > connections.active && (
                            <dd className="mt-0.5 text-xs text-muted-foreground tabular-nums">
                                {connections.total} total registered
                            </dd>
                        )}
                </div>
                <div>
                    <dt className="eyebrow">Plan</dt>
                    <dd
                        className="mt-1 font-serif text-3xl"
                        data-testid="admin-kpi-plan"
                    >
                        {PLAN_LABELS[team.plan] ?? team.plan}
                    </dd>
                </div>
            </dl>

            <ul className="mt-10">
                {destinations.map((destination) => (
                    <li
                        key={destination.key}
                        className="border-b border-border"
                    >
                        <Link
                            href={destination.href}
                            data-testid={`admin-link-${destination.key}`}
                            className="group flex min-w-0 items-baseline gap-4 py-5"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium text-foreground group-hover:text-[var(--accent)]">
                                    {destination.title}
                                </span>
                                <span className="mt-1 block text-sm text-muted-foreground">
                                    {destination.description}
                                </span>
                            </span>
                            <span
                                aria-hidden="true"
                                className="shrink-0 text-muted-foreground group-hover:text-[var(--accent)]"
                            >
                                →
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </>
    );
}

type RailSharedProps = SharedProps & {
    features?: Record<string, boolean>;
};

// AppLayout gains the optional railContext prop in the ShellRail slice; the
// cast keeps this page compiling (and flag-off identical) either way.
type AppLayoutWithRail = (props: {
    children: React.ReactNode;
    railContext?: React.ReactNode;
}) => React.JSX.Element;
const RailAppLayout = AppLayout as unknown as AppLayoutWithRail;

// Secondary admin destinations, mirrored from AppLayout's adminSubNav so the
// rail links route exactly as the sidebar sub-nav does today.
function AdminRailContext() {
    const { currentTeam, entitlements } = usePage<RailSharedProps>().props;
    const { url } = usePage();
    const team = currentTeam;

    if (!team) {
        return null;
    }

    const currentPath = url.split('?')[0];
    const isActive = (href: string, exact = false) =>
        currentPath === href || (!exact && url.startsWith(`${href}/`));
    const navItem = (key: string) =>
        entitlements?.nav?.find((item) => item.key === key);
    const membersNav = navItem('members');
    const botsNav = navItem('bots');
    const billingNav = navItem('billing');
    const lockedTitle = (reason: string | null | undefined) =>
        reason ?? 'Upgrade your plan to unlock this.';

    const items = [
        {
            href: teamRoutes.admin.show.url({ team: team.slug }),
            label: 'Overview',
            exact: true,
            locked: false,
            reason: null as string | null,
            key: 'overview',
        },
        {
            href: membersNav?.locked
                ? teamRoutes.billing.show.url({ team: team.slug })
                : teamRoutes.edit.url({ team: team.slug }),
            label: 'Members',
            exact: true,
            locked: membersNav?.locked === true,
            reason: membersNav?.reason ?? null,
            key: 'members',
        },
        {
            href: botsNav?.locked
                ? teamRoutes.billing.show.url({ team: team.slug })
                : teamRoutes.tokens.index.url({ team: team.slug }),
            label: botsNav?.locked ? 'AI bot teammates' : 'Agent & API tokens',
            exact: false,
            locked: botsNav?.locked === true,
            reason: botsNav?.reason ?? null,
            key: 'tokens',
        },
        ...(billingNav?.visible !== false
            ? [
                  {
                      href: teamRoutes.billing.show.url({
                          team: team.slug,
                      }),
                      label: 'Billing',
                      exact: false,
                      locked: false,
                      reason: null as string | null,
                      key: 'billing',
                  },
              ]
            : []),
        {
            href: teamRoutes.authentication.show.url({
                team: team.slug,
            }),
            label: 'Sign-in and security',
            exact: false,
            locked: false,
            reason: null as string | null,
            key: 'authentication',
        },
        {
            href: teamRoutes.governance.show.url({ team: team.slug }),
            label: 'Retention and legal holds',
            exact: false,
            locked: false,
            reason: null as string | null,
            key: 'governance',
        },
        {
            href: teamRoutes.audit.index.url({ team: team.slug }),
            label: 'Audit log',
            exact: false,
            locked: false,
            reason: null as string | null,
            key: 'audit',
        },
    ];

    return (
        <nav aria-label="Admin" data-testid="admin-rail-subnav">
            <p className="eyebrow">Admin</p>
            <ul className="mt-2 flex flex-col gap-px">
                {items.map((item) => {
                    const active =
                        !item.locked && isActive(item.href, item.exact);

                    return (
                        <li key={item.key}>
                            <Link
                                href={item.href}
                                aria-current={active ? 'page' : undefined}
                                data-testid={`admin-subnav-${item.key}`}
                                data-locked={item.locked ? 'true' : 'false'}
                                title={
                                    item.locked
                                        ? lockedTitle(item.reason)
                                        : undefined
                                }
                                className={cn(
                                    'flex min-h-8 items-center rounded-md px-2.5 py-1 text-[13px] leading-snug font-medium break-words transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring pointer-coarse:min-h-11',
                                    active
                                        ? 'bg-tint font-semibold text-foreground'
                                        : 'text-muted-foreground',
                                    item.locked && 'opacity-70',
                                )}
                            >
                                {item.label}
                                {item.locked ? ' · Upgrade' : ''}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

function TeamAdminLayout({ page }: { page: React.ReactNode }) {
    const { features } = usePage<RailSharedProps>().props;
    const unifiedRail = features?.['unified-rail'] === true;

    return (
        <RailAppLayout
            railContext={unifiedRail ? <AdminRailContext /> : undefined}
        >
            {page}
        </RailAppLayout>
    );
}

TeamAdmin.layout = (page: React.ReactNode) => <TeamAdminLayout page={page} />;
