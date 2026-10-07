import { Link, router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, LogOut, Menu } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';

import { Alert } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
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
    const [menuOpen, setMenuOpen] = useState(false);
    const closeMenu = () => setMenuOpen(false);
    // The trigger lives outside the Dialog Root, so Radix cannot return
    // focus to it on close: do it explicitly when the menu closes.
    const mobileMenuButtonRef = useRef<HTMLButtonElement>(null);

    // The phone menu is replaced by the desktop nav at md and up: closing
    // it on the way up keeps a hidden overlay from trapping focus.
    useEffect(() => {
        const query = window.matchMedia('(min-width: 768px)');
        const closeOnDesktop = (event: MediaQueryListEvent) => {
            if (event.matches) {
                setMenuOpen(false);
            }
        };
        query.addEventListener('change', closeOnDesktop);

        return () => query.removeEventListener('change', closeOnDesktop);
    }, []);
    const isTeamAdmin =
        team !== null &&
        teams.find((t) => t.slug === team.slug)?.role === 'admin';
    const navLinkClass = (href: string, exact = false) =>
        `border-b-2 py-3 transition-colors hover:text-foreground ${
            url.split('?')[0] === href || (!exact && url.startsWith(`${href}/`))
                ? 'border-primary text-foreground'
                : 'border-transparent text-muted-foreground'
        }`;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="border-b border-border bg-background">
                <div className="mx-auto flex h-16 max-w-5xl min-w-0 items-center gap-2 px-4 md:gap-4 md:px-5">
                    <Link
                        href={
                            team
                                ? dashboard.url({ current_team: team.slug })
                                : home.url()
                        }
                        className="inline-flex shrink-0 items-center font-serif text-2xl tracking-tight max-md:min-h-11"
                    >
                        Artfct
                    </Link>

                    {team && (
                        <DropdownMenu>
                            <DropdownMenuTrigger className="flex max-w-[36vw] min-w-0 items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm whitespace-nowrap hover:bg-muted max-md:min-h-11 md:max-w-none">
                                <span className="min-w-0 truncate">
                                    {team.name}
                                </span>
                                <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
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

                    <div className="ml-auto flex shrink-0 items-center gap-1">
                        {auth.user && (
                            <>
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        aria-label="Account menu"
                                        className="inline-flex items-center justify-center max-md:min-h-11 max-md:min-w-11"
                                    >
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
                                                router.get(
                                                    accountRoutes.show.url(),
                                                )
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
                                            <LogOut className="size-4" /> Sign
                                            out
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <button
                                    type="button"
                                    className="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-md text-sm font-medium transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring md:hidden"
                                    aria-label="Open menu"
                                    data-testid="mobile-menu-button"
                                    ref={mobileMenuButtonRef}
                                    onClick={() => setMenuOpen(true)}
                                >
                                    <Menu className="size-5" />
                                </button>
                            </>
                        )}
                    </div>
                </div>
                {team && (
                    <div className="mx-auto hidden max-w-5xl overflow-x-auto px-5 md:block">
                        <nav className="flex w-max items-center gap-x-6 text-sm font-medium whitespace-nowrap">
                            <Link
                                href={dashboard.url({
                                    current_team: team.slug,
                                })}
                                className={navLinkClass(
                                    dashboard.url({ current_team: team.slug }),
                                )}
                            >
                                Home
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
                {auth.user && (
                    <Dialog open={menuOpen} onOpenChange={setMenuOpen}>
                        <DialogContent
                            data-testid="mobile-menu"
                            className="md:hidden"
                            aria-label="Site menu"
                            onCloseAutoFocus={(event) => {
                                event.preventDefault();
                                mobileMenuButtonRef.current?.focus();
                            }}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <DialogTitle>Menu</DialogTitle>
                                    <DialogDescription>
                                        Team navigation, team switching and
                                        account actions.
                                    </DialogDescription>
                                </div>
                                <DialogClose asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="shrink-0"
                                    >
                                        Close menu
                                    </Button>
                                </DialogClose>
                            </div>
                            <div className="mt-4 flex flex-col gap-6">
                                {team && (
                                    <nav
                                        aria-label="Team"
                                        className="flex flex-col"
                                    >
                                        {[
                                            {
                                                href: dashboard.url({
                                                    current_team: team.slug,
                                                }),
                                                key: 'dashboard',
                                                label: 'Home',
                                            },
                                            {
                                                href: consoleRoutes.index.url({
                                                    team: team.slug,
                                                }),
                                                key: 'artifacts',
                                                label: 'Artifacts',
                                            },
                                            {
                                                href: teamRoutes.collections.index.url(
                                                    {
                                                        team: team.slug,
                                                    },
                                                ),
                                                key: 'collections',
                                                label: 'Collections',
                                            },
                                            {
                                                href: teamRoutes.edit.url({
                                                    team: team.slug,
                                                }),
                                                key: 'team',
                                                label: 'Team',
                                            },
                                            {
                                                href: teamRoutes.tokens.index.url(
                                                    {
                                                        team: team.slug,
                                                    },
                                                ),
                                                key: 'tokens',
                                                label: 'API tokens',
                                            },
                                            {
                                                href: teamRoutes.mcpConnections.index.url(
                                                    {
                                                        team: team.slug,
                                                    },
                                                ),
                                                key: 'connections',
                                                label: 'AI tool connections',
                                            },
                                            {
                                                href: teamRoutes.billing.show.url(
                                                    {
                                                        team: team.slug,
                                                    },
                                                ),
                                                key: 'billing',
                                                label: 'Billing',
                                            },
                                            ...(isTeamAdmin
                                                ? [
                                                      {
                                                          href: teamRoutes.authentication.show.url(
                                                              {
                                                                  team: team.slug,
                                                              },
                                                          ),
                                                          key: 'authentication',
                                                          label: 'Authentication',
                                                      },
                                                      {
                                                          href: teamRoutes.governance.show.url(
                                                              {
                                                                  team: team.slug,
                                                              },
                                                          ),
                                                          key: 'governance',
                                                          label: 'Governance',
                                                      },
                                                      {
                                                          href: teamRoutes.audit.index.url(
                                                              {
                                                                  team: team.slug,
                                                              },
                                                          ),
                                                          key: 'audit',
                                                          label: 'Audit log',
                                                      },
                                                  ]
                                                : []),
                                        ].map((item) => (
                                            <Link
                                                key={item.label}
                                                href={item.href}
                                                onClick={closeMenu}
                                                data-testid={`mobile-nav-${item.key}`}
                                                className={`flex min-h-[44px] items-center border-b border-border text-[15px] font-medium break-words ${url.split('?')[0] === item.href ? 'text-foreground' : 'text-muted-foreground'}`}
                                            >
                                                {item.label}
                                            </Link>
                                        ))}
                                    </nav>
                                )}
                                {team && (
                                    <section aria-label="Switch team">
                                        <h2 className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Switch team
                                        </h2>
                                        <ul className="flex flex-col">
                                            {teams.map((item) => (
                                                <li key={item.slug}>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            closeMenu();
                                                            router.post(
                                                                teamRoutes.switch.url(
                                                                    {
                                                                        team: item.slug,
                                                                    },
                                                                ),
                                                            );
                                                        }}
                                                        aria-current={
                                                            item.slug ===
                                                            team.slug
                                                                ? 'true'
                                                                : undefined
                                                        }
                                                        className="flex min-h-[44px] w-full items-center gap-2 py-2 text-left text-[15px] break-words"
                                                    >
                                                        <span className="min-w-0 flex-1 break-words">
                                                            {item.name}
                                                        </span>
                                                        {item.slug ===
                                                            team.slug && (
                                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                                current
                                                            </span>
                                                        )}
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                        <Link
                                            href={teamRoutes.index.url()}
                                            onClick={closeMenu}
                                            className="flex min-h-[44px] items-center text-[15px] font-medium text-muted-foreground"
                                        >
                                            All teams and new team
                                        </Link>
                                    </section>
                                )}
                                <section aria-label="Account">
                                    <h2 className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        Account
                                    </h2>
                                    <p className="py-1 text-sm break-all text-muted-foreground">
                                        {auth.user.email}
                                    </p>
                                    <div className="flex flex-col">
                                        <Link
                                            href={accountRoutes.show.url()}
                                            onClick={closeMenu}
                                            className="flex min-h-[44px] items-center text-[15px] font-medium"
                                        >
                                            Account settings
                                        </Link>
                                        <Link
                                            href={docs.url()}
                                            onClick={closeMenu}
                                            className="flex min-h-[44px] items-center text-[15px] font-medium"
                                        >
                                            Documentation
                                        </Link>
                                        <Link
                                            href={blog.url()}
                                            onClick={closeMenu}
                                            className="flex min-h-[44px] items-center text-[15px] font-medium"
                                        >
                                            Blog
                                        </Link>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                closeMenu();
                                                router.post(logout.url());
                                            }}
                                            className="flex min-h-[44px] items-center gap-2 text-left text-[15px] font-medium"
                                        >
                                            <LogOut className="size-4" /> Sign
                                            out
                                        </button>
                                    </div>
                                </section>
                            </div>
                        </DialogContent>
                    </Dialog>
                )}
            </header>

            {team && quota && banner && (
                <div className="mx-auto max-w-5xl min-w-0 px-4 pt-4 break-words md:px-5">
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
                <div className="mx-auto max-w-5xl min-w-0 px-4 pt-4 break-words md:px-5">
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

            <main className="mx-auto max-w-5xl min-w-0 px-4 py-8 md:px-5 md:py-12">
                {children}
            </main>
            <footer className="border-t border-border">
                <div className="mx-auto flex max-w-5xl min-w-0 flex-wrap items-center gap-x-6 gap-y-2 px-4 py-6 text-sm text-muted-foreground md:px-5 max-md:[&_a]:inline-flex max-md:[&_a]:min-h-11 max-md:[&_a]:min-w-11 max-md:[&_a]:items-center">
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
