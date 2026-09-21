import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';

import { Button } from '@/components/ui/button';
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

    const steps = [
        {
            done: setup?.invitedTeammates ?? false,
            href: base,
            label: 'Invite your teammates',
            detail: 'Everyone on the team can then find what gets shared.',
        },
        {
            done: setup?.createdToken ?? false,
            href: `${base}/tokens`,
            label: 'Connect your first AI tool',
            detail: 'So what it makes can be shared to the team.',
        },
        {
            done: setup?.choseAPlan ?? false,
            href: `${base}/billing`,
            label: 'Choose a plan',
            detail: 'Start with the free team trial.',
        },
    ];

    return (
        <>
            <Head title="Dashboard" />
            <header className="mb-10 border-b border-border pb-8">
                <p className="eyebrow mb-3">
                    {currentTeam ? currentTeam.name : 'Welcome'}
                </p>
                <h1 className="font-serif text-4xl leading-tight tracking-tight md:text-5xl">
                    {setup === null ? (
                        <>
                            Start with a <em className="text-primary">team</em>
                        </>
                    ) : (
                        <>
                            What your AI makes,{' '}
                            <em className="text-primary">remembered</em>
                        </>
                    )}
                </h1>
                <p className="mt-3 max-w-xl text-muted-foreground">
                    {setup === null
                        ? 'A team is where shared work lives. You become its owner.'
                        : 'Share the work worth keeping, and every AI tool on your team can read it, with sources.'}
                </p>
            </header>

            <div className="flex flex-col gap-12">
                {pendingInvitations.length > 0 && (
                    <section aria-labelledby="invitations">
                        <h2 id="invitations" className="eyebrow mb-3">
                            Invitations
                        </h2>
                        <ul className="divide-y divide-border border-y border-border">
                            {pendingInvitations.map((invitation) => (
                                <li
                                    key={invitation.code}
                                    className="flex flex-wrap items-center gap-3 py-4 text-sm"
                                >
                                    <span>
                                        {invitation.inviterName} invited you to{' '}
                                        <strong className="font-semibold">
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
                    </section>
                )}

                {setup === null ? (
                    <section>
                        <Button asChild>
                            <Link href="/settings/teams">Create a team</Link>
                        </Button>
                    </section>
                ) : (
                    <section aria-labelledby="setup">
                        <h2 id="setup" className="eyebrow mb-3">
                            Get your team set up
                        </h2>
                        <ol className="divide-y divide-border border-y border-border">
                            {steps.map((step, index) => (
                                <li key={step.label}>
                                    <Link
                                        href={step.href}
                                        className="group flex items-center gap-4 py-5"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={`flex size-8 shrink-0 items-center justify-center rounded-md text-sm font-semibold ${
                                                step.done
                                                    ? 'bg-success text-primary-foreground'
                                                    : 'bg-muted text-muted-foreground'
                                            }`}
                                        >
                                            {step.done ? (
                                                <Check className="size-4" />
                                            ) : (
                                                index + 1
                                            )}
                                        </span>
                                        <span className="flex flex-col">
                                            <span
                                                className={`font-semibold ${
                                                    step.done
                                                        ? 'text-muted-foreground line-through'
                                                        : ''
                                                }`}
                                            >
                                                {step.label}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {step.detail}
                                            </span>
                                        </span>
                                        <span className="sr-only">
                                            {step.done
                                                ? 'Done'
                                                : 'Not done yet'}
                                        </span>
                                        <ArrowRight className="ml-auto size-4 text-muted-foreground transition-transform group-hover:translate-x-1" />
                                    </Link>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}
            </div>
        </>
    );
}

Dashboard.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
