import { Head, Link, router } from '@inertiajs/react';

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
    return (
        <>
            <Head title="Dashboard" />
            <div
                style={{
                    maxWidth: 640,
                    margin: '2rem auto',
                    fontFamily: 'ui-sans-serif, system-ui',
                }}
            >
                <h1>Dashboard</h1>
                <p>
                    <Link href="/settings/teams">Your orgs</Link>
                </p>

                {pendingInvitations.length > 0 && (
                    <section>
                        <h2>Pending invitations</h2>
                        <ul>
                            {pendingInvitations.map((invitation) => (
                                <li key={invitation.code}>
                                    {invitation.inviterName} invited you to{' '}
                                    {invitation.team.name}{' '}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                `/invitations/${invitation.code}/accept`,
                                            )
                                        }
                                    >
                                        Accept
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.delete(
                                                `/invitations/${invitation.code}`,
                                            )
                                        }
                                    >
                                        Decline
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
