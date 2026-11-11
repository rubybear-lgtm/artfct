import { Link, router, usePage } from '@inertiajs/react';
import {
    ChevronsUpDown,
    FileText,
    Folder,
    House,
    LogOut,
    Menu,
    PanelLeft,
    Plug,
    ShieldCheck,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';

import { ThemeSwitch } from '@/components/home/theme-switch';
import { GITHUB } from '@/components/site-chrome';
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
import { useSidebarCollapsed } from '@/lib/useSidebarCollapsed';
import { cn } from '@/lib/utils';
import {
    blog,
    dashboard,
    docs,
    home,
    logout,
    privacy,
    support,
    terms,
} from '@/routes';
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
    const { auth, teams, currentTeam, quota, entitlements } =
        usePage<SharedProps>().props;
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
    // Desktop sidebar width: expanded by default, icon rail when collapsed.
    const [collapsed, toggleCollapsed] = useSidebarCollapsed();

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
    // Admins (team leads) get the Admin area; members and viewers stay on the
    // teammate path. Capabilities come from the server entitlements object.
    const isAdmin =
        entitlements?.capabilities?.view_admin === true ||
        team?.isAdmin === true;
    const navItem = (key: string) =>
        entitlements?.nav?.find((item) => item.key === key);
    const membersNav = navItem('members');
    const botsNav = navItem('bots');
    const billingNav = navItem('billing');
    const lockedTitle = (reason: string | null | undefined) =>
        reason ?? 'Upgrade your plan to unlock this.';
    const currentPath = url.split('?')[0];
    const isActive = (href: string, exact = false) =>
        currentPath === href || (!exact && url.startsWith(`${href}/`));
    const sidebarLinkClass = (active: boolean) =>
        cn(
            'flex min-h-9 items-center gap-3 rounded-md border-l-2 px-2.5 text-sm font-medium transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring pointer-coarse:min-h-11',
            active
                ? 'border-[var(--accent)] bg-tint text-foreground'
                : 'border-transparent text-muted-foreground',
            collapsed && 'justify-center px-0',
        );
    const sidebarLink = (item: {
        key: string;
        href: string;
        label: string;
        icon: LucideIcon;
        active: boolean;
        // 'true' marks a section that contains the current page, so the
        // current destination (a sub-link) is the only one marked 'page'.
        current?: 'page' | 'true';
        testId?: string;
    }) => (
        <Link
            key={item.key}
            href={item.href}
            title={collapsed ? item.label : undefined}
            aria-current={item.active ? (item.current ?? 'page') : undefined}
            data-testid={item.testId}
            className={sidebarLinkClass(item.active)}
        >
            <item.icon
                aria-hidden="true"
                className={cn(
                    'size-[18px] shrink-0',
                    item.active && 'text-[var(--accent)]',
                )}
            />
            <span className={cn('min-w-0 truncate', collapsed && 'sr-only')}>
                {item.label}
            </span>
        </Link>
    );

    // Admin settings pages the Admin nav item stays active on. Members (the
    // team edit page) is exact: every other settings page shares its prefix.
    const adminPaths = team
        ? [
              teamRoutes.admin.show.url({ team: team.slug }),
              teamRoutes.tokens.index.url({ team: team.slug }),
              teamRoutes.billing.show.url({ team: team.slug }),
              teamRoutes.authentication.show.url({ team: team.slug }),
              teamRoutes.governance.show.url({ team: team.slug }),
              teamRoutes.audit.index.url({ team: team.slug }),
          ]
        : [];
    const onAdminPage =
        isAdmin &&
        team !== null &&
        (currentPath === teamRoutes.edit.url({ team: team.slug }) ||
            adminPaths.some(
                (href) =>
                    currentPath === href || currentPath.startsWith(`${href}/`),
            ));

    const adminSubNav = team
        ? [
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
                  label: botsNav?.locked
                      ? 'AI bot teammates'
                      : 'Agent & API tokens',
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
          ]
        : [];

    // When no unlocked sub-page matches (locked Members or bots pages), Admin
    // itself is the best current destination, so it reports 'page'.
    const adminLeafActive = adminSubNav.some(
        (item) => !item.locked && isActive(item.href, item.exact),
    );
    const adminCurrent = adminLeafActive ? 'true' : 'page';

    const mobilePrimaryNav = team
        ? [
              {
                  href: dashboard.url({ current_team: team.slug }),
                  key: 'dashboard',
                  label: 'Home',
              },
              {
                  href: consoleRoutes.index.url({ team: team.slug }),
                  key: 'artifacts',
                  label: 'Artifacts',
              },
              {
                  href: teamRoutes.collections.index.url({ team: team.slug }),
                  key: 'collections',
                  label: 'Collections',
              },
              {
                  href: teamRoutes.mcpConnections.index.url({
                      team: team.slug,
                  }),
                  key: 'connections',
                  label: 'AI tool connections',
              },
          ]
        : [];

    const mobileTeammateNav = team
        ? [
              ...mobilePrimaryNav,
              {
                  href: teamRoutes.edit.url({ team: team.slug }),
                  key: 'team',
                  label: 'Team',
              },
          ]
        : [];

    const mobileAdminNav =
        team && isAdmin
            ? [
                  {
                      href: teamRoutes.admin.show.url({ team: team.slug }),
                      key: 'admin',
                      label: 'Overview',
                  },
                  {
                      href: teamRoutes.edit.url({ team: team.slug }),
                      key: 'team',
                      label: 'Members',
                  },
                  {
                      href: teamRoutes.tokens.index.url({ team: team.slug }),
                      key: 'tokens',
                      label: 'Agent & API tokens',
                  },
                  {
                      href: teamRoutes.billing.show.url({ team: team.slug }),
                      key: 'billing',
                      label: 'Billing',
                  },
                  {
                      href: teamRoutes.authentication.show.url({
                          team: team.slug,
                      }),
                      key: 'authentication',
                      label: 'Sign-in and security',
                  },
                  {
                      href: teamRoutes.governance.show.url({
                          team: team.slug,
                      }),
                      key: 'governance',
                      label: 'Retention and legal holds',
                  },
                  {
                      href: teamRoutes.audit.index.url({ team: team.slug }),
                      key: 'audit',
                      label: 'Audit log',
                  },
              ]
            : [];

    const mobileNavLink = (item: {
        href: string;
        key: string;
        label: string;
    }) => (
        <Link
            key={item.key}
            href={item.href}
            onClick={closeMenu}
            data-testid={`mobile-nav-${item.key}`}
            className={`flex min-h-[44px] items-center border-b border-border text-[15px] font-medium break-words ${currentPath === item.href ? 'text-foreground' : 'text-muted-foreground'}`}
        >
            {item.label}
        </Link>
    );

    return (
        <div className="flex min-h-screen bg-background text-foreground">
            <aside
                aria-label="Sidebar"
                data-testid="sidebar"
                data-collapsed={collapsed ? 'true' : 'false'}
                className={cn(
                    'sticky top-0 hidden h-dvh shrink-0 flex-col gap-4 border-r border-border bg-paper py-4 transition-[width] duration-200 motion-reduce:transition-none md:flex',
                    collapsed ? 'w-16 px-2.5' : 'w-[248px] px-3',
                )}
            >
                <div
                    className={cn(
                        'flex items-center justify-between px-1.5',
                        collapsed && 'flex-col gap-3 px-0',
                    )}
                >
                    <Link
                        href={
                            team
                                ? dashboard.url({ current_team: team.slug })
                                : home.url()
                        }
                        aria-label="Artfct"
                        className="inline-flex items-center font-serif text-[26px] leading-none font-medium tracking-[-0.02em]"
                    >
                        {collapsed ? 'A' : 'Artfct'}
                    </Link>
                    <button
                        type="button"
                        onClick={toggleCollapsed}
                        aria-label={
                            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
                        }
                        aria-expanded={!collapsed}
                        title={
                            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
                        }
                        data-testid="sidebar-toggle"
                        className="inline-flex size-8 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring pointer-coarse:size-11"
                    >
                        <PanelLeft className="size-4" aria-hidden="true" />
                    </button>
                </div>

                {team && (
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            title={collapsed ? team.name : undefined}
                            aria-label={
                                collapsed
                                    ? `Switch team, current team ${team.name}`
                                    : undefined
                            }
                            className={cn(
                                'flex w-full cursor-pointer items-center gap-2 rounded-md border border-border bg-background px-2.5 py-2 text-sm font-medium transition-colors hover:border-foreground/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring pointer-coarse:min-h-11',
                                collapsed && 'justify-center px-0',
                            )}
                        >
                            <span
                                aria-hidden="true"
                                className="grid size-[22px] shrink-0 place-items-center rounded-[5px] bg-muted text-[11px] font-bold"
                            >
                                {team.name.slice(0, 1).toUpperCase()}
                            </span>
                            {!collapsed && (
                                <>
                                    <span className="min-w-0 flex-1 truncate text-left">
                                        {team.name}
                                    </span>
                                    <ChevronsUpDown className="size-3.5 shrink-0 text-muted-foreground" />
                                </>
                            )}
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            side={collapsed ? 'right' : 'bottom'}
                        >
                            <DropdownMenuLabel>Switch team</DropdownMenuLabel>
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
                                All teams
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}

                {team && (
                    <nav
                        aria-label="Primary"
                        className="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto"
                    >
                        {sidebarLink({
                            key: 'dashboard',
                            href: dashboard.url({ current_team: team.slug }),
                            label: 'Home',
                            icon: House,
                            active: isActive(
                                dashboard.url({ current_team: team.slug }),
                            ),
                        })}
                        {sidebarLink({
                            key: 'artifacts',
                            href: consoleRoutes.index.url({ team: team.slug }),
                            label: 'Artifacts',
                            icon: FileText,
                            active: isActive(
                                consoleRoutes.index.url({ team: team.slug }),
                            ),
                        })}
                        {sidebarLink({
                            key: 'collections',
                            href: teamRoutes.collections.index.url({
                                team: team.slug,
                            }),
                            label: 'Collections',
                            icon: Folder,
                            active: isActive(
                                teamRoutes.collections.index.url({
                                    team: team.slug,
                                }),
                            ),
                        })}
                        {sidebarLink({
                            key: 'connections',
                            href: teamRoutes.mcpConnections.index.url({
                                team: team.slug,
                            }),
                            label: 'AI tool connections',
                            icon: Plug,
                            active: isActive(
                                teamRoutes.mcpConnections.index.url({
                                    team: team.slug,
                                }),
                            ),
                        })}
                        {isAdmin ? (
                            <>
                                <div
                                    role="separator"
                                    className="my-2 border-t border-border"
                                />
                                {collapsed ? (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger
                                            aria-label="Admin"
                                            aria-current={
                                                onAdminPage
                                                    ? adminCurrent
                                                    : undefined
                                            }
                                            title="Admin"
                                            data-testid="nav-admin"
                                            className={cn(
                                                sidebarLinkClass(onAdminPage),
                                                'w-full cursor-pointer',
                                            )}
                                        >
                                            <ShieldCheck
                                                aria-hidden="true"
                                                className={cn(
                                                    'size-[18px] shrink-0',
                                                    onAdminPage &&
                                                        'text-[var(--accent)]',
                                                )}
                                            />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="start"
                                            side="right"
                                        >
                                            <DropdownMenuLabel>
                                                Admin
                                            </DropdownMenuLabel>
                                            {adminSubNav.map((item) => (
                                                <DropdownMenuItem
                                                    key={item.key}
                                                    data-testid={`admin-menu-${item.key}`}
                                                    aria-current={
                                                        !item.locked &&
                                                        isActive(
                                                            item.href,
                                                            item.exact,
                                                        )
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                    data-locked={
                                                        item.locked
                                                            ? 'true'
                                                            : 'false'
                                                    }
                                                    title={
                                                        item.locked
                                                            ? lockedTitle(
                                                                  item.reason,
                                                              )
                                                            : undefined
                                                    }
                                                    className={cn(
                                                        !item.locked &&
                                                            isActive(
                                                                item.href,
                                                                item.exact,
                                                            ) &&
                                                            'bg-tint font-semibold',
                                                        item.locked &&
                                                            'opacity-70',
                                                    )}
                                                    onSelect={() =>
                                                        router.visit(item.href)
                                                    }
                                                >
                                                    {item.label}
                                                    {item.locked
                                                        ? ' · Upgrade'
                                                        : ''}
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                ) : (
                                    sidebarLink({
                                        key: 'admin',
                                        href: teamRoutes.admin.show.url({
                                            team: team.slug,
                                        }),
                                        label: 'Admin',
                                        icon: ShieldCheck,
                                        active: onAdminPage,
                                        current: adminCurrent,
                                        testId: 'nav-admin',
                                    })
                                )}
                                {onAdminPage && !collapsed && (
                                    <div
                                        data-testid="admin-subnav"
                                        className="mt-0.5 mb-1 ml-[21px] flex flex-col gap-px border-l border-border pl-2.5"
                                    >
                                        {adminSubNav.map((item) => {
                                            const active =
                                                !item.locked &&
                                                isActive(item.href, item.exact);

                                            return (
                                                <Link
                                                    key={item.key}
                                                    href={item.href}
                                                    aria-current={
                                                        active
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                    data-testid={`admin-subnav-${item.key}`}
                                                    data-locked={
                                                        item.locked
                                                            ? 'true'
                                                            : 'false'
                                                    }
                                                    title={
                                                        item.locked
                                                            ? lockedTitle(
                                                                  item.reason,
                                                              )
                                                            : undefined
                                                    }
                                                    className={cn(
                                                        'flex min-h-8 items-center rounded-md px-2.5 py-1 text-[13px] leading-snug font-medium break-words transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring pointer-coarse:min-h-11',
                                                        active
                                                            ? 'bg-tint font-semibold text-foreground'
                                                            : 'text-muted-foreground',
                                                        item.locked &&
                                                            'opacity-70',
                                                    )}
                                                >
                                                    {item.label}
                                                    {item.locked
                                                        ? ' · Upgrade'
                                                        : ''}
                                                </Link>
                                            );
                                        })}
                                    </div>
                                )}
                            </>
                        ) : membersNav?.visible ? (
                            sidebarLink({
                                key: 'team',
                                href: teamRoutes.edit.url({ team: team.slug }),
                                label: 'Team',
                                icon: Users,
                                active: isActive(
                                    teamRoutes.edit.url({ team: team.slug }),
                                    true,
                                ),
                                testId: 'nav-team',
                            })
                        ) : null}
                    </nav>
                )}

                <div className="mt-auto flex flex-col gap-2 border-t border-border pt-3">
                    <div
                        className={cn(
                            'flex items-center justify-between px-1',
                            collapsed && 'justify-center',
                        )}
                    >
                        {!collapsed && (
                            <span className="text-xs text-muted-foreground">
                                Theme
                            </span>
                        )}
                        <ThemeSwitch />
                    </div>
                    {auth.user && (
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                aria-label="Account menu"
                                className={cn(
                                    'flex w-full cursor-pointer items-center gap-2.5 rounded-md p-1.5 text-left transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring pointer-coarse:min-h-11',
                                    collapsed && 'justify-center',
                                )}
                            >
                                <Avatar>
                                    <AvatarFallback>
                                        {initials(auth.user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                {!collapsed && (
                                    <>
                                        <span className="min-w-0 flex-1 leading-tight">
                                            <span className="block truncate text-[13px] font-semibold">
                                                {auth.user.name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {auth.user.email}
                                            </span>
                                        </span>
                                        <ChevronsUpDown className="size-3.5 shrink-0 text-muted-foreground" />
                                    </>
                                )}
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="start"
                                side={collapsed ? 'right' : 'top'}
                            >
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
                                {team && !isAdmin && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.visit(
                                                teamRoutes.tokens.index.url({
                                                    team: team.slug,
                                                }),
                                            )
                                        }
                                    >
                                        Agent & API tokens
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem
                                    onSelect={() => router.visit(docs.url())}
                                >
                                    Documentation
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() => router.visit(blog.url())}
                                >
                                    Blog
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => router.post(logout.url())}
                                >
                                    <LogOut className="size-4" /> Sign out
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 border-b border-border bg-background/94 backdrop-blur-md md:hidden">
                    <div className="flex h-[60px] min-w-0 items-center gap-2 px-4">
                        <Link
                            href={
                                team
                                    ? dashboard.url({ current_team: team.slug })
                                    : home.url()
                            }
                            className="inline-flex min-h-11 shrink-0 items-center font-serif text-[26px] font-medium tracking-[-0.02em]"
                        >
                            Artfct
                        </Link>
                        <div className="ml-auto flex shrink-0 items-center gap-2">
                            <ThemeSwitch />
                            {auth.user && (
                                <button
                                    type="button"
                                    className="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-md text-sm font-medium transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    aria-label="Open menu"
                                    data-testid="mobile-menu-button"
                                    ref={mobileMenuButtonRef}
                                    onClick={() => setMenuOpen(true)}
                                >
                                    <Menu className="size-5" />
                                </button>
                            )}
                        </div>
                    </div>
                    {auth.user && (
                        <Dialog open={menuOpen} onOpenChange={setMenuOpen}>
                            <DialogContent
                                data-testid="mobile-menu"
                                className="top-0 left-0 h-dvh max-h-dvh w-[min(88vw,360px)] max-w-none translate-x-0 translate-y-0 rounded-none border-y-0 border-l-0 md:hidden"
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
                                            {(isAdmin
                                                ? mobilePrimaryNav
                                                : mobileTeammateNav
                                            ).map(mobileNavLink)}
                                            {isAdmin && (
                                                <>
                                                    <h2 className="mt-4 mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                        Admin
                                                    </h2>
                                                    {mobileAdminNav.map(
                                                        mobileNavLink,
                                                    )}
                                                </>
                                            )}
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
                                                All teams
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
                                            {team && !isAdmin && (
                                                <Link
                                                    href={teamRoutes.tokens.index.url(
                                                        { team: team.slug },
                                                    )}
                                                    onClick={closeMenu}
                                                    data-testid="mobile-nav-account-tokens"
                                                    className="flex min-h-[44px] items-center text-[15px] font-medium"
                                                >
                                                    Agent & API tokens
                                                </Link>
                                            )}
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
                                                <LogOut className="size-4" />{' '}
                                                Sign out
                                            </button>
                                        </div>
                                    </section>
                                </div>
                            </DialogContent>
                        </Dialog>
                    )}
                </header>

                {team && quota && banner && (
                    <div className="mx-auto w-full max-w-[1120px] min-w-0 px-4 pt-4 break-words md:px-5 xl:max-w-[1280px] 2xl:max-w-[1480px]">
                        <Alert
                            variant={quota.exceeded ? 'warning' : 'default'}
                            role="status"
                        >
                            {banner}{' '}
                            {isAdmin ? (
                                <Link
                                    className="underline"
                                    href={teamRoutes.billing.show.url({
                                        team: team.slug,
                                    })}
                                >
                                    See usage
                                </Link>
                            ) : (
                                'Ask a team admin to upgrade.'
                            )}
                        </Alert>
                    </div>
                )}
                {team?.onTrial && team.trialDaysLeft != null && (
                    <div
                        className="mx-auto w-full max-w-[1120px] min-w-0 px-4 pt-4 break-words md:px-5 xl:max-w-[1280px] 2xl:max-w-[1480px]"
                        data-testid="trial-banner"
                    >
                        <Alert
                            variant={
                                team.trialDaysLeft <= 7 ? 'warning' : 'default'
                            }
                            role="status"
                        >
                            {team.trialDaysLeft === 0
                                ? 'Your Team trial ends today.'
                                : `Team trial: ${team.trialDaysLeft} day${team.trialDaysLeft === 1 ? '' : 's'} left.`}{' '}
                            After that you keep Free — no charge, nothing
                            deleted.{' '}
                            {isAdmin ? (
                                <Link
                                    className="underline"
                                    href={teamRoutes.billing.show.url({
                                        team: team.slug,
                                    })}
                                >
                                    Upgrade to Pro or Team
                                </Link>
                            ) : (
                                'Ask a team admin to upgrade.'
                            )}
                        </Alert>
                    </div>
                )}
                {team?.paymentStatus === 'past_due' && (
                    <div className="mx-auto w-full max-w-[1120px] min-w-0 px-4 pt-4 break-words md:px-5 xl:max-w-[1280px] 2xl:max-w-[1480px]">
                        <Alert variant="warning" role="status">
                            Payment is past due. New artifacts are blocked;
                            existing ones keep serving.{' '}
                            {isAdmin ? (
                                <Link
                                    className="underline"
                                    href={teamRoutes.billing.show.url({
                                        team: team.slug,
                                    })}
                                >
                                    Fix billing
                                </Link>
                            ) : (
                                'Ask a team admin to update payment.'
                            )}
                        </Alert>
                    </div>
                )}

                <main className="mx-auto w-full max-w-[1120px] min-w-0 flex-1 px-4 py-8 md:px-5 md:py-12 xl:max-w-[1280px] 2xl:max-w-[1480px]">
                    {children}
                </main>
                <footer className="border-t border-border">
                    <div className="mx-auto flex max-w-[1120px] min-w-0 flex-wrap items-baseline justify-between gap-4 px-4 py-7 text-sm text-muted-foreground md:px-5 xl:max-w-[1280px] 2xl:max-w-[1480px] max-md:[&_a]:inline-flex max-md:[&_a]:min-h-11 max-md:[&_a]:min-w-11 max-md:[&_a]:items-center">
                        <div className="flex items-baseline gap-3">
                            <span className="font-serif text-xl font-medium tracking-[-0.02em] text-foreground">
                                Artfct
                            </span>
                            <span>© {new Date().getFullYear()} Artfct</span>
                        </div>
                        <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                            <Link
                                className="transition-colors hover:text-foreground"
                                href={docs.url()}
                            >
                                Documentation
                            </Link>
                            <Link
                                className="transition-colors hover:text-foreground"
                                href={blog.url()}
                            >
                                Blog
                            </Link>
                            <a
                                className="transition-colors hover:text-foreground"
                                href={GITHUB}
                                target="_blank"
                                rel="noreferrer"
                            >
                                GitHub
                            </a>
                            <Link
                                className="transition-colors hover:text-foreground"
                                href={support.url()}
                            >
                                Support
                            </Link>
                            <Link
                                className="transition-colors hover:text-foreground"
                                href={terms.url()}
                            >
                                Terms
                            </Link>
                            <Link
                                className="transition-colors hover:text-foreground"
                                href={privacy.url()}
                            >
                                Privacy
                            </Link>
                        </div>
                    </div>
                </footer>
                <Toaster />
            </div>
        </div>
    );
}
