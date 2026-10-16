import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import {
    RecentArtifactRow,
    SkeletonRows,
} from '@/components/home/artifact-row';
import type { HomeFilters, HomeRecentArtifact } from '@/components/home/types';
import consoleRoutes from '@/routes/console';
import teamRoutes from '@/routes/teams';

interface RecentArtifactsProps {
    teamSlug: string;
    scope: HomeFilters['scope'];
    recent: HomeRecentArtifact[];
    recentError: string | null;
    canOpenArtifacts: boolean;
    busy: boolean;
    onScopeChange: (scope: HomeFilters['scope']) => void;
}

function ScopeButton({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: string;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={`rounded px-3 py-1.5 text-sm font-medium transition-colors motion-safe:duration-200 max-md:min-h-11 ${
                active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:text-foreground'
            }`}
        >
            {children}
        </button>
    );
}

export function RecentArtifacts({
    teamSlug,
    scope,
    recent,
    recentError,
    canOpenArtifacts,
    busy,
    onScopeChange,
}: RecentArtifactsProps) {
    return (
        <section aria-labelledby="home-recent-heading" className="min-w-0">
            <div className="mb-3 flex min-w-0 flex-wrap items-center gap-3">
                <h2 id="home-recent-heading" className="eyebrow">
                    Recent
                </h2>
                <div
                    role="group"
                    aria-label="Whose artifacts to show"
                    className="ml-auto inline-flex rounded-md border border-border p-0.5"
                >
                    <ScopeButton
                        active={scope === 'team'}
                        onClick={() => onScopeChange('team')}
                    >
                        Team
                    </ScopeButton>
                    <ScopeButton
                        active={scope === 'mine'}
                        onClick={() => onScopeChange('mine')}
                    >
                        Mine
                    </ScopeButton>
                </div>
            </div>
            {recentError ? (
                <p className="min-w-0 text-sm break-words text-muted-foreground">
                    {recentError}
                </p>
            ) : busy && recent.length === 0 ? (
                <SkeletonRows />
            ) : recent.length === 0 ? (
                scope === 'team' ? (
                    <>
                        <p className="min-w-0 text-sm break-words text-muted-foreground">
                            Nothing shared yet. When your AI tools are
                            connected, what you choose to share shows up here.
                        </p>
                        <Link
                            href={teamRoutes.mcpConnections.index.url({
                                team: teamSlug,
                            })}
                            className="group mt-3 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary"
                        >
                            Connect your AI tools
                            <ArrowRight className="size-4 transition-transform group-hover:translate-x-1" />
                        </Link>
                    </>
                ) : (
                    <p className="min-w-0 text-sm break-words text-muted-foreground">
                        You haven&apos;t shared anything yet.
                    </p>
                )
            ) : (
                <ul className="divide-y divide-border border-y border-border">
                    {recent.map((item) => (
                        <RecentArtifactRow
                            key={item.id}
                            item={item}
                            canOpenArtifacts={canOpenArtifacts}
                        />
                    ))}
                </ul>
            )}
            <Link
                href={consoleRoutes.index.url({ team: teamSlug })}
                className="group mt-3 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary"
            >
                See all artifacts
                <ArrowRight className="size-4 transition-transform group-hover:translate-x-1" />
            </Link>
        </section>
    );
}
