import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { home, login, logout } from '@/routes';
import invitations from '@/routes/invitations';

type State =
    | 'sign_in'
    | 'ready'
    | 'accepted'
    | 'expired'
    | 'wrong_email'
    | 'invalid';

interface Props {
    state: State;
    invitation: {
        code: string;
        email: string;
        role: string;
        roleLabel: string;
        roleDescription: string;
        teamName: string;
        inviterName: string | null;
        expiresAt: string | null;
        dashboardUrl: string | null;
    } | null;
    signedInAs: string | null;
}

export default function InvitationShow({
    state,
    invitation,
    signedInAs,
}: Props) {
    // Stays true while the browser heads to sign-in, which can take a few
    // seconds on a phone; without it the page looks idle and invites a second
    // tap. Reset when the page is restored from the back/forward cache.
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const reset = (event: PageTransitionEvent) =>
            event.persisted && setBusy(false);
        window.addEventListener('pageshow', reset);

        return () => window.removeEventListener('pageshow', reset);
    }, []);

    if (state === 'invalid' || invitation === null) {
        return (
            <>
                <Head title="Invitation link is not valid" />
                <h1 className="mb-2 font-serif text-3xl tracking-tight">
                    This invitation link is no longer valid
                </h1>
                <p className="mb-6 text-sm text-muted-foreground">
                    It may have been cancelled, or the link was cut short. Ask
                    the person who invited you to send a new one.
                </p>
                <Button asChild variant="outline">
                    <Link href={home.url()}>Learn about Artfct</Link>
                </Button>
            </>
        );
    }

    const visitOptions = {
        onStart: () => setBusy(true),
        onError: () => setBusy(false),
        onCancel: () => setBusy(false),
        onNetworkError: () => setBusy(false),
    };

    const accept = () =>
        router.post(
            invitations.accept.url({ invitation: invitation.code }),
            {},
            visitOptions,
        );
    const decline = () =>
        router.delete(
            invitations.decline.url({ invitation: invitation.code }),
            visitOptions,
        );
    const signOut = () => router.post(logout.url(), {}, visitOptions);

    const join = (account: 'new' | 'existing') =>
        router.post(
            invitations.join.url({ invitation: invitation.code }),
            { account },
            visitOptions,
        );

    const article = /^[aeiou]/i.test(invitation.roleLabel) ? 'an' : 'a';
    const expiresAt = invitation.expiresAt
        ? new Date(invitation.expiresAt)
        : null;
    const expiryLabel = expiresAt
        ? `This invitation works until ${expiresAt.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })}`
        : null;

    return (
        <>
            <Head title={`Join ${invitation.teamName}`} />
            <p className="mb-2 text-xs font-semibold tracking-[0.08em] text-primary uppercase">
                Team invitation
            </p>
            <h1 className="mb-2 font-serif text-3xl tracking-tight">
                Join {invitation.teamName}
            </h1>
            <p className="mb-2 text-sm text-muted-foreground">
                {invitation.inviterName ?? 'An admin'} invited{' '}
                <strong className="font-semibold break-all text-foreground">
                    {invitation.email}
                </strong>{' '}
                to join as {article} {invitation.roleLabel}.
            </p>
            <p className="mb-6 text-sm text-muted-foreground">
                {invitation.roleDescription}
            </p>

            {state === 'sign_in' && (
                <div className="flex flex-col gap-3">
                    <Button onClick={() => join('new')} disabled={busy}>
                        {busy
                            ? 'Opening sign-up…'
                            : 'Create an account and join'}
                    </Button>
                    <p className="text-center text-sm text-muted-foreground">
                        Already have an account?{' '}
                        <button
                            type="button"
                            onClick={() => join('existing')}
                            disabled={busy}
                            className="cursor-pointer font-semibold text-foreground underline underline-offset-4"
                        >
                            Sign in
                        </button>
                    </p>
                    {expiryLabel && (
                        <p className="text-center text-sm text-muted-foreground">
                            {expiryLabel}
                        </p>
                    )}
                </div>
            )}

            {state === 'ready' && (
                <div className="flex flex-col gap-3">
                    <Button onClick={accept} disabled={busy}>
                        {busy ? 'Joining…' : 'Accept invitation'}
                    </Button>
                    <Button variant="outline" onClick={decline} disabled={busy}>
                        Decline
                    </Button>
                    {expiryLabel && (
                        <p className="text-center text-sm text-muted-foreground">
                            {expiryLabel}
                        </p>
                    )}
                </div>
            )}

            {state === 'wrong_email' && (
                <div className="flex flex-col gap-3">
                    <p role="alert" className="text-sm">
                        You are signed in as {signedInAs}. Sign out, then open
                        the invitation link again and continue as{' '}
                        {invitation.email}.
                    </p>
                    <Button onClick={signOut} disabled={busy}>
                        Sign out
                    </Button>
                </div>
            )}

            {state === 'accepted' && (
                <div className="flex flex-col gap-3">
                    <p role="alert" className="text-sm">
                        This invitation has already been accepted.
                    </p>
                    <Button asChild>
                        {invitation.dashboardUrl ? (
                            <Link href={invitation.dashboardUrl}>
                                Go to {invitation.teamName}
                            </Link>
                        ) : (
                            <Link href={login.url()}>Sign in</Link>
                        )}
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
                        <Link href={home.url()}>Learn about Artfct</Link>
                    </Button>
                </div>
            )}
        </>
    );
}

InvitationShow.layout = (page: React.ReactNode) => (
    <AuthLayout>{page}</AuthLayout>
);
