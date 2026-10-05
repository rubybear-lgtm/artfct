import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import ConsoleController from '@/actions/App/Http/Controllers/ConsoleController';
import CollectionController from '@/actions/App/Http/Controllers/Teams/CollectionController';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { aiToolName, AI_TOOL_NAMES } from '@/lib/ai-tools';
import teamRoutes from '@/routes/teams';

interface Artifact {
    id: string;
    title: string;
    description: string;
    created_at: string;
    revoked_at: string | null;
    provenance: {
        agent: string | null;
        repo_url: string | null;
    };
}

interface ConsoleIndexProps {
    team: {
        id: number;
        name: string;
        slug: string;
    };
    artifacts: Artifact[];
    /** The AI tools the listed artifacts were produced with. */
    agents: string[];
    filters: {
        user_id: string | null;
        repo_url: string | null;
        agent: string | null;
        q: string | null;
    };
    nextCursor: string | null;
    cursor: string | null;
    isAdmin: boolean;
    canOpenArtifacts: boolean;
    collections: { id: number; name: string }[];
    canCollect: boolean;
    indexingEnabled: boolean;
    indexing: Record<string, 'indexed' | 'pending' | 'failed' | 'off'>;
    indexingFailures: {
        artifact_id: string;
        attempts: number;
        reason: string;
        failed_at: string;
    }[];
}

/** The row's name as plain text: its title, or the short id when it has none. */
function artifactName(artifact: Artifact) {
    return artifact.title || artifact.id.slice(0, 8);
}

/** The row's name: its title, or the short id when it has none. */
function artifactTitle(artifact: Artifact) {
    return (
        artifact.title || (
            <span className="tabular-nums">{artifactName(artifact)}</span>
        )
    );
}

export default function ConsoleIndex({
    team,
    artifacts,
    agents,
    filters,
    nextCursor,
    cursor,
    isAdmin,
    canOpenArtifacts,
    collections,
    canCollect,
    indexingEnabled,
    indexing,
    indexingFailures,
}: ConsoleIndexProps) {
    const [localFilters, setLocalFilters] = useState(filters);
    const [revoking, setRevoking] = useState<Artifact | null>(null);
    const pendingFilterVisit = useRef<ReturnType<typeof setTimeout> | null>(
        null,
    );
    const hasActionsColumn = isAdmin || canOpenArtifacts;
    const hasActiveFilters = Object.values(localFilters).some(Boolean);
    // Known tools are always offered, so narrowing to one tool never hides the
    // others from the list; tools seen on this page and the active filter are added.
    const aiTools = [
        ...new Set([
            ...Object.keys(AI_TOOL_NAMES),
            ...agents,
            ...(localFilters.agent ? [localFilters.agent] : []),
        ]),
    ].sort((a, b) => aiToolName(a).localeCompare(aiToolName(b)));

    // A pending debounced visit must not outlive the page it was scheduled on.
    useEffect(() => {
        return () => {
            if (pendingFilterVisit.current !== null) {
                clearTimeout(pendingFilterVisit.current);
            }
        };
    }, []);

    const visitWithFilters = (
        nextFilters: typeof filters,
        {
            cursor = null,
            replace = true,
        }: { cursor?: string | null; replace?: boolean } = {},
    ) => {
        const params = new URLSearchParams();

        if (cursor) {
            params.set('cursor', cursor);
        }

        Object.entries(nextFilters).forEach(([key, value]) => {
            if (value) {
                params.set(key, value);
            }
        });

        router.get(
            ConsoleController.index.url(
                { team: team.slug },
                { query: Object.fromEntries(params.entries()) },
            ),
            {},
            { preserveState: true, preserveScroll: true, replace },
        );
    };

    const clearPendingFilterVisit = () => {
        if (pendingFilterVisit.current !== null) {
            clearTimeout(pendingFilterVisit.current);
            pendingFilterVisit.current = null;
        }
    };

    // Free-text filters wait for a pause in typing: one visit and one history
    // entry per keystroke is what a debounce is for.
    const handleFilterChange = (key: keyof typeof filters, value: string) => {
        const nextFilters = { ...localFilters, [key]: value || null };
        setLocalFilters(nextFilters);

        clearPendingFilterVisit();
        pendingFilterVisit.current = setTimeout(() => {
            pendingFilterVisit.current = null;
            visitWithFilters(nextFilters);
        }, 300);
    };

    // A select commits a whole value at once, so it needs no debounce.
    const handleSelectChange = (key: keyof typeof filters, value: string) => {
        const nextFilters = { ...localFilters, [key]: value || null };
        setLocalFilters(nextFilters);
        clearPendingFilterVisit();
        visitWithFilters(nextFilters);
    };

    const clearFilters = () => {
        const cleared: typeof filters = {
            user_id: null,
            repo_url: null,
            agent: null,
            q: null,
        };
        setLocalFilters(cleared);
        clearPendingFilterVisit();
        visitWithFilters(cleared);
    };

    const handleRevoke = () => {
        if (revoking === null) {
            return;
        }

        const artifactId = revoking.id;
        setRevoking(null);
        router.patch(
            ConsoleController.revoke.url({ team: team.slug, artifactId }),
        );
    };

    /**
     * The app's own open route, never a Worker URL and never a token: the
     * route authorizes the viewer, then either redirects a public artifact to
     * its public URL or mints a short-lived signed link for a secure one.
     */
    const openHref = (artifactId: string) =>
        ConsoleController.open.url({ team: team.slug, artifactId });

    const revokedOpenTooltip =
        'This artifact is revoked, so it can no longer be opened.';

    return (
        <div>
            <div>
                <div className="mb-8">
                    <h1 className="text-foreground">Artifacts</h1>
                    <p className="mt-2 text-muted-foreground">
                        Manage artifacts produced by your team
                    </p>
                </div>

                {/* Filters */}
                <div className="mb-8 rounded-[10px] border border-border bg-paper p-6">
                    <h2 className="mb-4 text-lg font-semibold text-foreground">
                        Filters
                    </h2>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label
                                htmlFor="repo_url"
                                className="mb-2 block text-sm font-medium text-foreground"
                            >
                                Repository
                            </label>
                            <input
                                id="repo_url"
                                name="repo_url"
                                type="text"
                                value={localFilters.repo_url || ''}
                                onChange={(e) =>
                                    handleFilterChange(
                                        'repo_url',
                                        e.target.value,
                                    )
                                }
                                placeholder="github.com/example/repo"
                                className="w-full rounded-md border border-border px-3 py-2 text-sm max-md:min-h-[44px] max-md:text-base"
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="agent"
                                className="mb-2 block text-sm font-medium text-foreground"
                            >
                                AI tool
                            </label>
                            <select
                                id="agent"
                                name="agent"
                                value={localFilters.agent || ''}
                                onChange={(e) =>
                                    handleSelectChange('agent', e.target.value)
                                }
                                className="w-full rounded-md border border-border px-3 py-2 text-sm max-md:min-h-[44px] max-md:text-base"
                            >
                                <option value="">All AI tools</option>
                                {aiTools.map((agent) => (
                                    <option key={agent} value={agent}>
                                        {aiToolName(agent)}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-2 block text-sm font-medium text-foreground">
                                Search
                            </label>
                            <input
                                type="text"
                                value={localFilters.q || ''}
                                onChange={(e) =>
                                    handleFilterChange('q', e.target.value)
                                }
                                placeholder="Title or description..."
                                className="w-full rounded-md border border-border px-3 py-2 text-sm max-md:min-h-[44px] max-md:text-base"
                            />
                        </div>
                        {isAdmin && (
                            <div>
                                <label className="mb-2 block text-sm font-medium text-foreground">
                                    Actions
                                </label>
                                <Button
                                    asChild
                                    className="w-full rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
                                >
                                    <a
                                        href={ConsoleController.export.url({
                                            team: team.slug,
                                        })}
                                    >
                                        Download export (.zip)
                                    </a>
                                </Button>
                            </div>
                        )}
                    </div>
                </div>

                {!indexingEnabled && (
                    <p className="text-sm text-muted-foreground">
                        Search indexing is turned off on this environment, so
                        new artifacts are not indexed yet.
                    </p>
                )}
                {indexingEnabled && indexingFailures.length > 0 && (
                    <div className="rounded-[10px] border border-border bg-paper p-4">
                        <h2 className="mb-2 text-sm font-semibold">
                            Indexing failures
                        </h2>
                        <ul className="divide-y divide-border text-sm">
                            {indexingFailures.map((failure) => (
                                <li
                                    key={failure.artifact_id}
                                    className="flex flex-wrap items-center gap-3 py-2"
                                >
                                    <span className="break-all tabular-nums">
                                        {failure.artifact_id.slice(0, 8)}
                                    </span>
                                    <span className="break-words text-muted-foreground">
                                        {failure.attempts} attempts:{' '}
                                        {failure.reason}
                                    </span>
                                    {isAdmin && (
                                        <Button
                                            className="ml-auto underline"
                                            onClick={() =>
                                                router.post(
                                                    ConsoleController.reindex.url(
                                                        {
                                                            team: team.slug,
                                                            artifactId:
                                                                failure.artifact_id,
                                                        },
                                                    ),
                                                )
                                            }
                                        >
                                            Retry
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Artifact List */}
                <div
                    className="overflow-x-auto rounded-[10px] border border-border bg-paper max-md:p-3"
                    data-testid="artifact-list"
                >
                    <table className="w-full">
                        <thead className="border-b border-border bg-muted max-md:hidden">
                            <tr>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-foreground">
                                    Title
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-foreground">
                                    Repository
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-foreground">
                                    AI tool
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-foreground">
                                    Created
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-foreground">
                                    Status
                                </th>
                                {hasActionsColumn && (
                                    <th className="px-6 py-3 text-left text-sm font-semibold text-foreground">
                                        Actions
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border max-md:block max-md:space-y-3 max-md:divide-y-0">
                            {artifacts.length === 0 ? (
                                <tr className="max-md:block max-md:rounded-lg max-md:border max-md:border-border max-md:p-4">
                                    <td
                                        colSpan={hasActionsColumn ? 6 : 5}
                                        className="px-6 py-4 text-center text-muted-foreground max-md:block max-md:border-0 max-md:px-0 max-md:py-1"
                                    >
                                        {hasActiveFilters ? (
                                            <EmptyState title="No artifacts match these filters">
                                                <Button
                                                    variant="outline"
                                                    onClick={clearFilters}
                                                >
                                                    Clear filters
                                                </Button>
                                            </EmptyState>
                                        ) : (
                                            <EmptyState title="No artifacts yet">
                                                <p className="text-sm">
                                                    Connect an AI tool, then
                                                    share something it made. It
                                                    will appear here.
                                                </p>
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    className="mt-4"
                                                >
                                                    <Link
                                                        href={teamRoutes.mcpConnections.index.url(
                                                            {
                                                                team: team.slug,
                                                            },
                                                        )}
                                                    >
                                                        AI tool connections
                                                    </Link>
                                                </Button>
                                            </EmptyState>
                                        )}
                                    </td>
                                </tr>
                            ) : (
                                artifacts.map((artifact) => (
                                    <tr
                                        key={artifact.id}
                                        className="hover:bg-muted max-md:block max-md:rounded-lg max-md:border max-md:border-border max-md:p-4"
                                    >
                                        <td className="min-w-0 px-6 py-4 break-words max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                            <span
                                                aria-hidden="true"
                                                className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                            >
                                                Title
                                            </span>
                                            {artifact.revoked_at ? (
                                                <span
                                                    className="text-sm font-medium text-muted-foreground"
                                                    data-testid="open-artifact-title-disabled"
                                                    title={revokedOpenTooltip}
                                                    aria-disabled="true"
                                                >
                                                    {artifactTitle(artifact)}
                                                </span>
                                            ) : canOpenArtifacts ? (
                                                <a
                                                    href={openHref(artifact.id)}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-sm font-medium text-foreground underline-offset-2 hover:text-primary hover:underline"
                                                    data-testid="open-artifact-title"
                                                >
                                                    {artifactTitle(artifact)}
                                                </a>
                                            ) : (
                                                <span className="text-sm font-medium text-foreground">
                                                    {artifactTitle(artifact)}
                                                </span>
                                            )}
                                            <div className="text-xs break-words text-muted-foreground">
                                                {artifact.description}
                                            </div>
                                            <div
                                                className="text-xs text-muted-foreground"
                                                data-testid="indexing-state"
                                            >
                                                Indexing:{' '}
                                                {indexing[artifact.id] ?? 'off'}
                                            </div>
                                            {canCollect &&
                                                collections.length > 0 && (
                                                    <select
                                                        aria-label={`Add ${artifact.title || artifact.id} to a collection`}
                                                        className="mt-1 rounded border border-border bg-background px-1 text-xs max-md:min-h-[44px] max-md:text-base"
                                                        value=""
                                                        onChange={(e) => {
                                                            if (
                                                                e.target.value
                                                            ) {
                                                                router.post(
                                                                    CollectionController.addArtifact.url(
                                                                        {
                                                                            team: team.slug,
                                                                            collection:
                                                                                Number(
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                                ),
                                                                        },
                                                                    ),
                                                                    {
                                                                        artifact_id:
                                                                            artifact.id,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        <option value="">
                                                            Add to collection…
                                                        </option>
                                                        {collections.map(
                                                            (c) => (
                                                                <option
                                                                    key={c.id}
                                                                    value={c.id}
                                                                >
                                                                    {c.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                )}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-muted-foreground max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                            <span
                                                aria-hidden="true"
                                                className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                            >
                                                Repository
                                            </span>
                                            {artifact.provenance.repo_url ? (
                                                <a
                                                    href={
                                                        artifact.provenance
                                                            .repo_url
                                                    }
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="break-all text-primary hover:underline"
                                                >
                                                    {artifact.provenance.repo_url
                                                        .split('/')
                                                        .slice(-2)
                                                        .join('/')}
                                                </a>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Unknown
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-muted-foreground max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                            <span
                                                aria-hidden="true"
                                                className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                            >
                                                AI tool
                                            </span>
                                            {aiToolName(
                                                artifact.provenance.agent,
                                            )}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-muted-foreground max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                            <span
                                                aria-hidden="true"
                                                className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                            >
                                                Created
                                            </span>
                                            {new Date(
                                                artifact.created_at,
                                            ).toLocaleDateString()}
                                        </td>
                                        <td className="px-6 py-4 max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                            <span
                                                aria-hidden="true"
                                                className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                            >
                                                Status
                                            </span>
                                            {artifact.revoked_at ? (
                                                <span className="inline-block rounded bg-destructive/15 px-2 py-1 text-xs font-semibold text-destructive">
                                                    Revoked
                                                </span>
                                            ) : (
                                                <span className="inline-block rounded bg-success/15 px-2 py-1 text-xs font-semibold text-success">
                                                    Active
                                                </span>
                                            )}
                                        </td>
                                        {hasActionsColumn && (
                                            <td className="px-6 py-4 text-sm max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                                <span
                                                    aria-hidden="true"
                                                    className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                >
                                                    Actions
                                                </span>
                                                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                    {canOpenArtifacts &&
                                                        (artifact.revoked_at ? (
                                                            <span
                                                                className="cursor-not-allowed font-medium text-muted-foreground"
                                                                data-testid="open-artifact-disabled"
                                                                title={
                                                                    revokedOpenTooltip
                                                                }
                                                                aria-disabled="true"
                                                            >
                                                                Open
                                                            </span>
                                                        ) : (
                                                            <a
                                                                href={openHref(
                                                                    artifact.id,
                                                                )}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="font-medium text-primary hover:underline max-md:inline-flex max-md:min-h-[44px] max-md:items-center"
                                                                data-testid="open-artifact"
                                                            >
                                                                Open
                                                            </a>
                                                        ))}
                                                    {isAdmin &&
                                                        !artifact.revoked_at && (
                                                            <Button
                                                                variant="outline"
                                                                aria-label={`Revoke ${artifactName(artifact)}`}
                                                                onClick={() =>
                                                                    setRevoking(
                                                                        artifact,
                                                                    )
                                                                }
                                                            >
                                                                Revoke
                                                            </Button>
                                                        )}
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {(nextCursor || cursor) && (
                    <div className="mt-6 flex flex-wrap justify-center gap-3">
                        {cursor && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    visitWithFilters(localFilters, {
                                        cursor: null,
                                        replace: false,
                                    })
                                }
                            >
                                First page
                            </Button>
                        )}
                        {nextCursor && (
                            <Button
                                onClick={() =>
                                    visitWithFilters(localFilters, {
                                        cursor: nextCursor,
                                        replace: false,
                                    })
                                }
                            >
                                Next page
                            </Button>
                        )}
                    </div>
                )}

                {revoking && (
                    <ConfirmDialog
                        open
                        onOpenChange={(open) => {
                            if (!open) {
                                setRevoking(null);
                            }
                        }}
                        title={`Revoke ${artifactName(revoking)}?`}
                        description="People and AI tools lose access to it right away. This cannot be undone."
                        confirmLabel="Revoke artifact"
                        onConfirm={handleRevoke}
                    />
                )}
            </div>
        </div>
    );
}

ConsoleIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
