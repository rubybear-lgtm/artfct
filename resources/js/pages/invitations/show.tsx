import { Head, Link, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { home, logout } from '@/routes';
import invitations from '@/routes/invitations';

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

export default function InvitationShow({
    state,
    invitation,
    signedInAs,
}: Props) {
    const accept = () =>
        router.post(invitations.accept.url({ invitation: invitation.code }));
    const decline = () =>
        router.delete(invitations.decline.url({ invitation: invitation.code }));
    const signOut = () => router.post(logout.url());

    const join = (account: 'new' | 'existing') =>
        router.post(invitations.join.url({ invitation: invitation.code }), {
            account,
        });

    return (
        <>
            <Head title={`Join ${invitation.teamName}`} />
            <p className="mb-2 text-xs font-semibold tracking-[0.08em] text-primary uppercase">
                Team invitation
            </p>
            <h1 className="mb-2 font-serif text-3xl tracking-tight">
                Join {invitation.teamName}
            </h1>
            <p className="mb-6 text-sm text-muted-foreground">
                {invitation.inviterName ?? 'An admin'} invited{' '}
                <strong className="font-semibold text-foreground">
                    {invitation.email}
                </strong>{' '}
                to join as {invitation.role}.
            </p>

            {state === 'sign_in' && (
                <div className="flex flex-col gap-3">
                    <Button onClick={() => join('new')}>
                        Accept invitation
                    </Button>
                    <p className="text-center text-sm text-muted-foreground">
                        Already have an account?{' '}
                        <button
                            type="button"
                            onClick={() => join('existing')}
                            className="cursor-pointer font-semibold text-foreground underline underline-offset-4"
                        >
                            Sign in
                        </button>
                    </p>
                </div>
            )}

            {state === 'ready' && (
                <div className="flex flex-col gap-3">
                    <Button onClick={accept}>Accept invitation</Button>
                    <Button variant="outline" onClick={decline}>
                        Decline
                    </Button>
                </div>
            )}

            {state === 'wrong_email' && (
                <div className="flex flex-col gap-3">
                    <p role="alert" className="text-sm">
                        You are signed in as {signedInAs}. Sign out, then open
                        the invitation link again and continue as{' '}
                        {invitation.email}.
                    </p>
                    <Button onClick={signOut}>Sign out</Button>
                </div>
            )}

            {state === 'accepted' && (
                <div className="flex flex-col gap-3">
                    <p role="alert" className="text-sm">
                        This invitation has already been accepted.
                    </p>
                    <Button asChild>
                        <Link href={home.url()}>Go to Artfct</Link>
                    </Button>
                </div>
            )}

            {state === 'expired' && (
                <div className="flex flex-col gap-3">
                    <p role="alert" className="text-sm">
                        This invitation has expired. Ask{' '}
                        {invitation.inviterName ?? 'an admin'} to send a new
                        one.
                    </p>
                    <Button asChild variant="outline">
                        <Link href={home.url()}>Go to Artfct</Link>
                    </Button>
                </div>
            )}
        </>
    );
}

InvitationShow.layout = (page: React.ReactNode) => (
    <AuthLayout>{page}</AuthLayout>
);
