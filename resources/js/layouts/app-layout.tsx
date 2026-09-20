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
    const { auth, teams, currentTeam, flash } = usePage<SharedProps>().props;
    const toastMessage = flash?.toast;

    useEffect(() => {
        if (toastMessage) {
            (toastMessage.type === 'error' ? toast.error : toast.success)(
                toastMessage.message,
            );
        }
    }, [toastMessage]);

    const team = currentTeam;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="border-b border-border">
                <div className="mx-auto flex h-14 max-w-5xl items-center gap-4 px-4">
                    <Link href="/" className="font-mono text-sm font-semibold">
                        artfct
                    </Link>

                    {team && (
                        <DropdownMenu>
                            <DropdownMenuTrigger className="flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted">
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

                    {team && (
                        <nav className="ml-2 flex items-center gap-4 text-sm">
                            <Link href={`/settings/teams/${team.slug}/console`}>
                                Artifacts
                            </Link>
                            <Link href={`/settings/teams/${team.slug}`}>
                                Team
                            </Link>
                            <Link href={`/settings/teams/${team.slug}/tokens`}>
                                API tokens
                            </Link>
                            <Link href={`/settings/teams/${team.slug}/billing`}>
                                Billing
                            </Link>
                            {teams.find((t) => t.slug === team.slug)?.role ===
                                'admin' && (
                                <Link
                                    href={`/settings/teams/${team.slug}/audit`}
                                >
                                    Audit log
                                </Link>
                            )}
                        </nav>
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
                                        onSelect={() => router.post('/logout')}
                                    >
                                        <LogOut className="size-4" /> Sign out
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>
            </header>

            {team?.paymentStatus === 'past_due' && (
                <div className="mx-auto max-w-5xl px-4 pt-4">
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

            <main className="mx-auto max-w-5xl px-4 py-8">{children}</main>
            <Toaster />
        </div>
    );
}
