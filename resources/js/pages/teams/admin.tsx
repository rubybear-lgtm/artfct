import { Head, Link } from '@inertiajs/react';

import AppLayout from '@/layouts/app-layout';
import teamRoutes from '@/routes/teams';

type PlanName = 'free' | 'team' | 'enterprise';

interface Props {
    team: {
        slug: string;
        name: string;
        plan: PlanName;
        isEnterprise: boolean;
    };
    members: { total: number; admins: number };
    pendingInvitations: number;
    connections: { active: number };
}

const PLAN_LABELS: Record<PlanName, string> = {
    free: 'Free',
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
            title: 'API tokens',
            description:
                'Create and revoke the tokens your scripts use to share artifacts.',
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
                    <dt className="eyebrow">Connected AI tools</dt>
                    <dd className="mt-1 font-serif text-3xl tabular-nums">
                        {connections.active}
                    </dd>
                </div>
                <div>
                    <dt className="eyebrow">Plan</dt>
                    <dd className="mt-1 font-serif text-3xl">
                        {PLAN_LABELS[team.plan]}
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
                                <span className="block font-medium text-foreground group-hover:text-primary">
                                    {destination.title}
                                </span>
                                <span className="mt-1 block text-sm text-muted-foreground">
                                    {destination.description}
                                </span>
                            </span>
                            <span
                                aria-hidden="true"
                                className="shrink-0 text-muted-foreground group-hover:text-primary"
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

TeamAdmin.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
