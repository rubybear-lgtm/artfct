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

export default function Dashboard({
    pendingInvitations,
}: {
    pendingInvitations: PendingInvitation[];
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

                <Card>
                    <CardHeader>
                        <CardTitle>Get your team set up</CardTitle>
                        <CardDescription>
                            The few things a new team usually does first.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="flex flex-col gap-2 text-sm">
                            <li>
                                <Link className="underline" href={base}>
                                    Invite your teammates
                                </Link>
                            </li>
                            <li>
                                <Link
                                    className="underline"
                                    href={`${base}/tokens`}
                                >
                                    Create an API token for the CLI
                                </Link>
                            </li>
                            <li>
                                <Link
                                    className="underline"
                                    href={`${base}/billing`}
                                >
                                    Choose a plan
                                </Link>
                            </li>
                            <li>
                                <Link
                                    className="underline"
                                    href="/settings/teams"
                                >
                                    Your orgs
                                </Link>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
