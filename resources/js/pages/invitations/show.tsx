import { Head, Link, router } from '@inertiajs/react';

type State = 'sign_in' | 'ready' | 'accepted' | 'expired' | 'wrong_email';

interface Props {
    state: State;
    invitation: {
        code: string;
        email: string;
        role: string;
        teamName: string;
        inviterName: string | null;
        expiresAt: string | null;
    };
    signedInAs: string | null;
}

const messages: Record<Exclude<State, 'sign_in' | 'ready'>, string> = {
    accepted: 'This invitation has already been accepted.',
    expired: 'This invitation has expired. Ask an admin to send a new one.',
    wrong_email: 'This invitation was sent to a different email address.',
};

export default function InvitationShow({
    state,
    invitation,
    signedInAs,
}: Props) {
    const accept = () => router.post(`/invitations/${invitation.code}/accept`);
    const decline = () => router.delete(`/invitations/${invitation.code}`);

    return (
        <>
            <Head title={`Join ${invitation.teamName}`} />
            <div
                style={{
                    maxWidth: 480,
                    margin: '4rem auto',
                    fontFamily: 'ui-sans-serif, system-ui',
                }}
            >
                <h1>Join {invitation.teamName}</h1>
                <p>
                    {invitation.inviterName ?? 'An admin'} invited{' '}
                    <strong>{invitation.email}</strong> to join as{' '}
                    <strong>{invitation.role}</strong>.
                </p>

                {state === 'sign_in' && (
                    <p>
                        <Link href="/login">Sign in to accept</Link> as{' '}
                        {invitation.email}. You will return here afterwards.
                    </p>
                )}

                {state === 'ready' && (
                    <p>
                        <button onClick={accept}>Accept</button>{' '}
                        <button onClick={decline}>Decline</button>
                    </p>
                )}

                {state === 'wrong_email' && (
                    <p role="alert">
                        {messages.wrong_email} You are signed in as {signedInAs}
                        ; sign in with {invitation.email} to accept.
                    </p>
                )}

                {(state === 'accepted' || state === 'expired') && (
                    <p role="alert">{messages[state]}</p>
                )}
            </div>
        </>
    );
}
