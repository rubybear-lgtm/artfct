import { Link, router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, LogOut } from 'lucide-react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';

import { Alert } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Toaster } from '@/components/ui/sonner';
import { useAppTheme } from '@/lib/useAppTheme';
import { blog, dashboard, docs, home, logout, privacy, terms } from '@/routes';
import accountRoutes from '@/routes/account';
import consoleRoutes from '@/routes/console';
import teamRoutes from '@/routes/teams';
import type { SharedProps } from '@/types/shared';

function initials(name: string) {
    return name
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

type Quota = NonNullable<SharedProps['quota']>;
type QuotaDimension = Quota['storage'];

const quotaDimensionKinds = ['storage', 'artifacts'] as const;

type QuotaDimensionKind = (typeof quotaDimensionKinds)[number];

function formatBytes(bytes: number) {
    if (bytes >= 1024 ** 3) {
        return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
    }

    if (bytes >= 1024 ** 2) {
        return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    }

    if (bytes >= 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${bytes} B`;
}

function quotaAmount(kind: QuotaDimensionKind, dimension: QuotaDimension) {
    return kind === 'storage'
        ? `${formatBytes(dimension.used)} of ${formatBytes(dimension.limit)}`
        : `${dimension.used} of ${dimension.limit}`;
}

function quotaDimensionLabel(kind: QuotaDimensionKind) {
    return kind === 'storage' ? 'storage' : 'monthly artifact';
}

/**
 * One sentence naming every limit the team has hit or is close to, so the
 * banner says which limit and how much rather than "a plan limit".
 */
function quotaMessage(teamName: string, quota: Quota) {
    const qualifying = quotaDimensionKinds.filter(
        (kind) => quota[kind].warning || quota[kind].exceeded,
    );

    if (qualifying.length === 0) {
        return null;
    }

    const clauses = qualifying.map((kind) => {
        const dimension = quota[kind];
        const label = quotaDimensionLabel(kind);
        const amount = quotaAmount(kind, dimension);

        return dimension.exceeded
            ? `reached its ${label} limit (${amount})`
            : `used ${Math.round(dimension.percent * 100)}% of its ${label} limit (${amount})`;
    });

    const suffix = quota.exceeded
        ? ' New shares are paused until you upgrade or free up space.'
        : '';

    return `${teamName} has ${clauses.join(' and ')}.${suffix}`;
}

export default function AppLayout({ children }: { children: ReactNode }) {
    const { auth, teams, currentTeam, quota } = usePage<SharedProps>().props;
    const { flash, url } = usePage();
    const toastMessage = flash?.toast;

    useEffect(() => {
        if (toastMessage) {
            (toastMessage.type === 'error' ? toast.error : toast.success)(
                toastMessage.message,
            );
        }
    }, [toastMessage]);

    useAppTheme();

    const team = currentTeam;
    const banner = team && quota ? quotaMessage(team.name, quota) : null;
    const navLinkClass = (href: string, exact = false) =>
        `border-b-2 py-3 transition-colors hover:text-foreground ${
            url.split('?')[0] === href || (!exact && url.startsWith(`${href}/`))
                ? 'border-primary text-foreground'
                : 'border-transparent text-muted-foreground'
        }`;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="border-b border-border bg-background">
                <div className="mx-auto flex h-16 max-w-5xl items-center gap-4 px-5">
                    <Link
                        href={
                            team
                                ? dashboard.url({ current_team: team.slug })
                                : home.url()
                        }
                        className="font-serif text-2xl tracking-tight"
                    >
                        Artfct
                    </Link>

                    {team && (
                        <DropdownMenu>
                            <DropdownMenuTrigger className="flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm whitespace-nowrap hover:bg-muted">
                                {team.name}
                                <ChevronsUpDown className="size-4 text-muted-foreground" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                <DropdownMenuLabel>
                                    Switch team
                                </DropdownMenuLabel>
                                {teams.map((item) => (
                                    <DropdownMenuItem
                                        key={item.slug}
                                        onSelect={() =>
                                            router.post(
                                                teamRoutes.switch.url({
                                                    team: item.slug,
                                                }),
                                            )
                                        }
                                    >
                                        {item.name}
                                    </DropdownMenuItem>
                                ))}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() =>
                                        router.visit(teamRoutes.index.url())
                                    }
                                >
                                    All teams and new team
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}

                    <div className="ml-auto">
                        {auth.user && (
                            <DropdownMenu>
                                <DropdownMenuTrigger aria-label="Account menu">
                                    <Avatar>
                                        <AvatarFallback>
                                            {initials(auth.user.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuLabel>
                                        {auth.user.email}
                                    </DropdownMenuLabel>
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.get(accountRoutes.show.url())
                                        }
                                    >
                                        Account settings
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.visit(docs.url())
                                        }
                                    >
                                        Documentation
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.visit(blog.url())
                                        }
                                    >
                                        Blog
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.post(logout.url())
                                        }
                                    >
                                        <LogOut className="size-4" /> Sign out
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>
                {team && (
                    <div className="mx-auto max-w-5xl overflow-x-auto px-5">
                        <nav className="flex w-max items-center gap-x-6 text-sm font-medium whitespace-nowrap">
                            <Link
                                href={dashboard.url({
                                    current_team: team.slug,
                                })}
                                className={navLinkClass(
                                    dashboard.url({ current_team: team.slug }),
                                )}
                            >
                                Dashboard
                            </Link>
                            <Link
                                href={consoleRoutes.index.url({
                                    team: team.slug,
                                })}
                                className={navLinkClass(
                                    consoleRoutes.index.url({
                                        team: team.slug,
                                    }),
                                )}
                            >
                                Artifacts
                            </Link>
                            <Link
                                href={teamRoutes.search.url({
                                    team: team.slug,
                                })}
                                className={navLinkClass(
                                    teamRoutes.search.url({ team: team.slug }),
                                )}
                            >
                                Search
                            </Link>
                            <Link
                                href={teamRoutes.collections.index.url({
                                    team: team.slug,
                                })}
                                className={navLinkClass(
                                    teamRoutes.collections.index.url({
                                        team: team.slug,
                                    }),
                                )}
                            >
                                Collections
                            </Link>
                            <Link
                                href={teamRoutes.edit.url({ team: team.slug })}
                                className={navLinkClass(
                                    teamRoutes.edit.url({ team: team.slug }),
                                    true,
                                )}
                            >
                                Team
                            </Link>
                            <Link
                                href={teamRoutes.tokens.index.url({
                                    team: team.slug,
                                })}
                                className={navLinkClass(
                                    teamRoutes.tokens.index.url({
                                        team: team.slug,
                                    }),
                                )}
                            >
                                API tokens
                            </Link>
                            <Link
                                href={teamRoutes.mcpConnections.index.url({
                                    team: team.slug,
                                })}
                                className={navLinkClass(
                                    teamRoutes.mcpConnections.index.url({
                                        team: team.slug,
                                    }),
                                )}
                            >
                                AI tool connections
                            </Link>
                            <Link
                                href={teamRoutes.billing.show.url({
                                    team: team.slug,
                                })}
                                className={navLinkClass(
                                    teamRoutes.billing.show.url({
                                        team: team.slug,
                                    }),
                                )}
                            >
                                Billing
                            </Link>
                            {teams.find((t) => t.slug === team.slug)?.role ===
                                'admin' && (
                                <>
                                    <Link
                                        href={teamRoutes.authentication.show.url(
                                            { team: team.slug },
                                        )}
                                        className={navLinkClass(
                                            teamRoutes.authentication.show.url({
                                                team: team.slug,
                                            }),
                                        )}
                                    >
                                        Authentication
                                    </Link>
                                    <Link
                                        href={teamRoutes.governance.show.url({
                                            team: team.slug,
                                        })}
                                        className={navLinkClass(
                                            teamRoutes.governance.show.url({
                                                team: team.slug,
                                            }),
                                        )}
                                    >
                                        Governance
                                    </Link>
                                    <Link
                                        href={teamRoutes.audit.index.url({
                                            team: team.slug,
                                        })}
                                        className={navLinkClass(
                                            teamRoutes.audit.index.url({
                                                team: team.slug,
                                            }),
                                        )}
                                    >
                                        Audit log
                                    </Link>
                                </>
                            )}
                        </nav>
                    </div>
                )}
            </header>

            {team && quota && banner && (
                <div className="mx-auto max-w-5xl px-5 pt-4">
                    <Alert
                        variant={quota.exceeded ? 'warning' : 'default'}
                        role="status"
                    >
                        {banner}{' '}
                        <Link
                            className="underline"
                            href={teamRoutes.billing.show.url({
                                team: team.slug,
                            })}
                        >
                            See usage
                        </Link>
                    </Alert>
                </div>
            )}
            {team?.paymentStatus === 'past_due' && (
                <div className="mx-auto max-w-5xl px-5 pt-4">
                    <Alert variant="warning" role="status">
                        Payment is past due. New artifacts are blocked; existing
                        ones keep serving.{' '}
                        <Link
                            className="underline"
                            href={teamRoutes.billing.show.url({
                                team: team.slug,
                            })}
                        >
                            Fix billing
                        </Link>
                    </Alert>
                </div>
            )}

            <main className="mx-auto max-w-5xl px-5 py-12">{children}</main>
            <footer className="border-t border-border">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-5 py-6 text-sm text-muted-foreground">
                    <Link className="hover:text-foreground" href={docs.url()}>
                        Documentation
                    </Link>
                    <Link className="hover:text-foreground" href={blog.url()}>
                        Blog
                    </Link>
                    <Link className="hover:text-foreground" href={terms.url()}>
                        Terms
                    </Link>
                    <Link
                        className="hover:text-foreground"
                        href={privacy.url()}
                    >
                        Privacy
                    </Link>
                </div>
            </footer>
            <Toaster />
        </div>
    );
}
