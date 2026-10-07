import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { CollectionList } from '@/components/home/collection-list';
import { RecentArtifacts } from '@/components/home/recent-artifacts';
import { SearchBlock } from '@/components/home/search-block';
import { SearchResults } from '@/components/home/search-results';
import { SetupStrip } from '@/components/home/setup-strip';
import type {
    DashboardProps,
    HomeFilters,
    PendingInvitation,
} from '@/components/home/types';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import invitations from '@/routes/invitations';
import teamRoutes from '@/routes/teams';
import type { SharedProps } from '@/types/shared';

export default function Dashboard({
    pendingInvitations,
    setup,
    home,
}: DashboardProps) {
    const { currentTeam } = usePage<SharedProps>().props;
    const [declining, setDeclining] = useState<PendingInvitation | null>(null);
    const [busy, setBusy] = useState(false);

    const steps = [
        {
            done: setup?.invitedTeammates ?? false,
            href: currentTeam
                ? teamRoutes.edit.url({ team: currentTeam.slug })
                : teamRoutes.index.url(),
            label: 'Invite your teammates',
            detail: 'Everyone on the team can then find what gets shared.',
        },
        {
            done: setup?.connectedMcp ?? false,
            href: currentTeam
                ? teamRoutes.mcpConnections.index.url({
                      team: currentTeam.slug,
                  })
                : teamRoutes.index.url(),
            label: 'Connect your AI tools',
            detail: 'So what they make can be shared with the team, and they can find what the team has shared.',
        },
        {
            done: setup?.choseAPlan ?? false,
            href: currentTeam
                ? teamRoutes.billing.show.url({ team: currentTeam.slug })
                : teamRoutes.index.url(),
            label: 'Choose a plan',
            detail: 'Start with the free team trial.',
        },
    ];
    const setupComplete =
        setup !== null &&
        setup.invitedTeammates &&
        setup.connectedMcp &&
        setup.choseAPlan;

    const changeScope = (scope: HomeFilters['scope']) => {
        if (!home || scope === home.filters.scope) {
            return;
        }

        const params: Record<string, string> = {};

        if (home.filters.q !== '') {
            params.q = home.filters.q;
        }

        if (home.filters.collection !== '') {
            params.collection = home.filters.collection;
        }

        if (home.filters.since !== '') {
            params.since = home.filters.since;
        }

        if (scope === 'mine') {
            params.scope = 'mine';
        }

        setBusy(true);
        router.get(dashboard.url({ current_team: home.team.slug }), params, {
            preserveScroll: true,
            only: ['home'],
            onFinish: () => setBusy(false),
        });
    };

    return (
        <>
            <Head title="Home" />
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
                                    className="flex min-w-0 flex-wrap items-center gap-3 py-4 text-sm"
                                >
                                    <span className="min-w-0 flex-1 break-words">
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
                                                    invitations.accept.url({
                                                        invitation:
                                                            invitation.code,
                                                    }),
                                                )
                                            }
                                        >
                                            Accept
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setDeclining(invitation)
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

                {home === null ? (
                    <>
                        <header className="mb-10 border-b border-border pb-8">
                            <p className="eyebrow mb-3 min-w-0 break-words">
                                {currentTeam ? currentTeam.name : 'Welcome'}
                            </p>
                            <h1 className="font-serif text-4xl leading-tight tracking-tight md:text-5xl">
                                Start with a{' '}
                                <em className="text-primary">team</em>
                            </h1>
                            <p className="mt-3 max-w-xl text-muted-foreground">
                                A team is where shared work lives. You become
                                its owner.
                            </p>
                        </header>
                        <section>
                            <Button asChild>
                                <Link href={teamRoutes.index.url()}>
                                    Create a team
                                </Link>
                            </Button>
                        </section>
                    </>
                ) : (
                    <>
                        <SearchBlock
                            key={JSON.stringify(home.filters)}
                            team={home.team}
                            filters={home.filters}
                            indexingEnabled={home.indexingEnabled}
                            filterCollections={home.filterCollections}
                            onBusyChange={setBusy}
                        />

                        {setup !== null && !setupComplete && (
                            <SetupStrip steps={steps} />
                        )}

                        <div
                            aria-busy={busy}
                            className={`motion-safe:transition-opacity motion-safe:duration-200 ${
                                busy ? 'opacity-60' : ''
                            }`}
                        >
                            {home.filters.q !== '' ? (
                                <SearchResults
                                    teamSlug={home.team.slug}
                                    filters={home.filters}
                                    searched={home.searched}
                                    results={home.results}
                                    searchError={home.searchError}
                                    canOpenArtifacts={home.canOpenArtifacts}
                                    busy={busy}
                                    onBusyChange={setBusy}
                                />
                            ) : (
                                <div className="grid grid-cols-1 gap-12 min-[800px]:grid-cols-[1.6fr_1fr] min-[800px]:gap-10">
                                    <RecentArtifacts
                                        teamSlug={home.team.slug}
                                        scope={home.filters.scope}
                                        recent={home.recent}
                                        recentError={home.recentError}
                                        canOpenArtifacts={home.canOpenArtifacts}
                                        busy={busy}
                                        onScopeChange={changeScope}
                                    />
                                    <CollectionList
                                        teamSlug={home.team.slug}
                                        collections={home.collections}
                                    />
                                </div>
                            )}
                        </div>
                    </>
                )}
            </div>
            <ConfirmDialog
                open={declining !== null}
                onOpenChange={(open) => !open && setDeclining(null)}
                title={`Decline the invitation to ${declining?.team.name}?`}
                description="You can only join later if someone invites you again."
                confirmLabel="Decline invitation"
                destructive={false}
                onConfirm={() => {
                    if (declining) {
                        router.delete(
                            invitations.decline.url({
                                invitation: declining.code,
                            }),
                        );
                    }

                    setDeclining(null);
                }}
            />
        </>
    );
}

Dashboard.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
