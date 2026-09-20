import { Head, Link, router, usePage } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import type { SharedProps } from '@/types/shared';

interface PendingInvitation {
    code: string;
    inviterName: string;
    team: { name: string; slug: string };
}

interface SetupProgress {
    invitedTeammates: boolean;
    createdToken: boolean;
    choseAPlan: boolean;
}

export default function Dashboard({
    pendingInvitations,
    setup,
}: {
    pendingInvitations: PendingInvitation[];
    setup: SetupProgress | null;
}) {
    const { currentTeam } = usePage<SharedProps>().props;
    const base = currentTeam
        ? `/settings/teams/${currentTeam.slug}`
        : '/settings/teams';

    return (
        <>
            <Head title="Dashboard" />
            <h1 className="mb-6 text-2xl font-semibold">Dashboard</h1>

            <div className="flex flex-col gap-6">
                {pendingInvitations.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Pending invitations</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="divide-y divide-border">
                                {pendingInvitations.map((invitation) => (
                                    <li
                                        key={invitation.code}
                                        className="flex flex-wrap items-center gap-3 py-3 text-sm"
                                    >
                                        <span>
                                            {invitation.inviterName} invited you
                                            to{' '}
                                            <strong>
                                                {invitation.team.name}
                                            </strong>
                                        </span>
                                        <span className="ml-auto flex gap-2">
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/invitations/${invitation.code}/accept`,
                                                    )
                                                }
                                            >
                                                Accept
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.delete(
                                                        `/invitations/${invitation.code}`,
                                                    )
                                                }
                                            >
                                                Decline
                                            </Button>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {setup === null ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Create your first team</CardTitle>
                            <CardDescription>
                                A team owns your artifacts, tokens and billing.
                                You become its owner.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button asChild>
                                <Link href="/settings/teams">
                                    Create a team
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Get your team set up</CardTitle>
                            <CardDescription>
                                The few things a new team usually does first.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul className="flex flex-col gap-2 text-sm">
                                {[
                                    {
                                        done: setup.invitedTeammates,
                                        href: base,
                                        label: 'Invite your teammates',
                                    },
                                    {
                                        done: setup.createdToken,
                                        href: `${base}/tokens`,
                                        label: 'Create an API token for the CLI',
                                    },
                                    {
                                        done: setup.choseAPlan,
                                        href: `${base}/billing`,
                                        label: 'Choose a plan',
                                    },
                                ].map((step) => (
                                    <li
                                        key={step.label}
                                        className="flex items-center gap-2"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="font-mono"
                                        >
                                            {step.done ? '[x]' : '[ ]'}
                                        </span>
                                        <Link
                                            className={
                                                step.done
                                                    ? 'text-muted-foreground line-through'
                                                    : 'underline'
                                            }
                                            href={step.href}
                                        >
                                            {step.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

Dashboard.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
