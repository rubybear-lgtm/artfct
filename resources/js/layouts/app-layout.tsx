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
import type { SharedProps } from '@/types/shared';

function initials(name: string) {
    return name
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

export default function AppLayout({ children }: { children: ReactNode }) {
    const { auth, teams, currentTeam, flash, quota } =
        usePage<SharedProps>().props;
    const { url } = usePage();
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
                        href="/"
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
                                                `/settings/teams/${item.slug}/switch`,
                                            )
                                        }
                                    >
                                        {item.name}
                                    </DropdownMenuItem>
                                ))}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() =>
                                        router.visit('/settings/teams')
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
                                            router.get('/settings/account')
                                        }
                                    >
                                        Account settings
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        onSelect={() => router.visit('/docs')}
                                    >
                                        Documentation
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        onSelect={() => router.visit('/blog')}
                                    >
                                        Blog
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onSelect={() => router.post('/logout')}
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
                                href={`/settings/teams/${team.slug}/console`}
                                className={navLinkClass(
                                    `/settings/teams/${team.slug}/console`,
                                )}
                            >
                                Artifacts
                            </Link>
                            <Link
                                href={`/settings/teams/${team.slug}/search`}
                                className={navLinkClass(
                                    `/settings/teams/${team.slug}/search`,
                                )}
                            >
                                Search
                            </Link>
                            <Link
                                href={`/settings/teams/${team.slug}/collections`}
                                className={navLinkClass(
                                    `/settings/teams/${team.slug}/collections`,
                                )}
                            >
                                Collections
                            </Link>
                            <Link
                                href={`/settings/teams/${team.slug}`}
                                className={navLinkClass(
                                    `/settings/teams/${team.slug}`,
                                    true,
                                )}
                            >
                                Team
                            </Link>
                            <Link
                                href={`/settings/teams/${team.slug}/tokens`}
                                className={navLinkClass(
                                    `/settings/teams/${team.slug}/tokens`,
                                )}
                            >
                                API tokens
                            </Link>
                            <Link
                                href={`/settings/teams/${team.slug}/mcp-connections`}
                                className={navLinkClass(
                                    `/settings/teams/${team.slug}/mcp-connections`,
                                )}
                            >
                                MCP connections
                            </Link>
                            <Link
                                href={`/settings/teams/${team.slug}/billing`}
                                className={navLinkClass(
                                    `/settings/teams/${team.slug}/billing`,
                                )}
                            >
                                Billing
                            </Link>
                            {teams.find((t) => t.slug === team.slug)?.role ===
                                'admin' && (
                                <>
                                    <Link
                                        href={`/settings/teams/${team.slug}/authentication`}
                                        className={navLinkClass(
                                            `/settings/teams/${team.slug}/authentication`,
                                        )}
                                    >
                                        Authentication
                                    </Link>
                                    <Link
                                        href={`/settings/teams/${team.slug}/governance`}
                                        className={navLinkClass(
                                            `/settings/teams/${team.slug}/governance`,
                                        )}
                                    >
                                        Governance
                                    </Link>
                                    <Link
                                        href={`/settings/teams/${team.slug}/audit`}
                                        className={navLinkClass(
                                            `/settings/teams/${team.slug}/audit`,
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

            {team && quota?.exceeded && (
                <div className="mx-auto max-w-5xl px-5 pt-4">
                    <Alert variant="warning">
                        This team is over its plan limits. New artifacts are
                        blocked; existing ones keep serving.{' '}
                        <Link
                            className="underline"
                            href={`/settings/teams/${team.slug}/billing`}
                        >
                            See usage
                        </Link>
                    </Alert>
                </div>
            )}
            {team && quota?.warning && !quota.exceeded && (
                <div className="mx-auto max-w-5xl px-5 pt-4">
                    <Alert>
                        This team has used over 80% of a plan limit.{' '}
                        <Link
                            className="underline"
                            href={`/settings/teams/${team.slug}/billing`}
                        >
                            See usage
                        </Link>
                    </Alert>
                </div>
            )}
            {team?.paymentStatus === 'past_due' && (
                <div className="mx-auto max-w-5xl px-5 pt-4">
                    <Alert variant="warning">
                        Payment is past due. New artifacts are blocked; existing
                        ones keep serving.{' '}
                        <Link
                            className="underline"
                            href={`/settings/teams/${team.slug}/billing`}
                        >
                            Fix billing
                        </Link>
                    </Alert>
                </div>
            )}

            <main className="mx-auto max-w-5xl px-5 py-12">{children}</main>
            <footer className="border-t border-border">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-5 py-6 text-sm text-muted-foreground">
                    <Link className="hover:text-foreground" href="/docs">
                        Documentation
                    </Link>
                    <Link className="hover:text-foreground" href="/blog">
                        Blog
                    </Link>
                    <Link className="hover:text-foreground" href="/terms">
                        Terms
                    </Link>
                    <Link className="hover:text-foreground" href="/privacy">
                        Privacy
                    </Link>
                </div>
            </footer>
            <Toaster />
        </div>
    );
}
