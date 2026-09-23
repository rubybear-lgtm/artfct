import { router } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '@/layouts/app-layout';

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
    filters: {
        user_id: string | null;
        repo_url: string | null;
        agent: string | null;
        q: string | null;
    };
    nextCursor: string | null;
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

/** The row's name: its title, or the short id when it has none. */
function artifactTitle(artifact: Artifact) {
    return (
        artifact.title || (
            <span className="tabular-nums">{artifact.id.slice(0, 8)}</span>
        )
    );
}

export default function ConsoleIndex({
    team,
    artifacts,
    filters,
    nextCursor,
    isAdmin,
    canOpenArtifacts,
    collections,
    canCollect,
    indexingEnabled,
    indexing,
    indexingFailures,
}: ConsoleIndexProps) {
    const [localFilters, setLocalFilters] = useState(filters);
    const [confirmingRevoke, setConfirmingRevoke] = useState<string | null>(
        null,
    );
    const hasActionsColumn = isAdmin || canOpenArtifacts;

    const handleFilterChange = (key: keyof typeof filters, value: string) => {
        const newFilters = { ...localFilters, [key]: value || null };
        setLocalFilters(newFilters);

        // Build query string
        const params = new URLSearchParams();
        Object.entries(newFilters).forEach(([k, v]) => {
            if (v) {
                params.set(k, v as string);
            }
        });

        router.get(`/settings/teams/${team.slug}/console?${params.toString()}`);
    };

    const handleRevoke = (artifactId: string) => {
        if (confirmingRevoke !== artifactId) {
            setConfirmingRevoke(artifactId);

            return;
        }

        setConfirmingRevoke(null);
        router.patch(
            `/settings/teams/${team.slug}/console/artifacts/${artifactId}/revoke`,
        );
    };

    const handleExport = () => {
        router.get(`/settings/teams/${team.slug}/console/export`);
    };

    /**
     * The app's own open route, never a Worker URL and never a token: the
     * route authorizes the viewer, then either redirects a public artifact to
     * its public URL or mints a short-lived signed link for a secure one.
     */
    const openHref = (artifactId: string) =>
        `/settings/teams/${team.slug}/console/artifacts/${artifactId}/open`;

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
                                className="w-full rounded-md border border-border px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="mb-2 block text-sm font-medium text-foreground">
                                Agent
                            </label>
                            <select
                                value={localFilters.agent || ''}
                                onChange={(e) =>
                                    handleFilterChange('agent', e.target.value)
                                }
                                className="w-full rounded-md border border-border px-3 py-2 text-sm"
                            >
                                <option value="">All agents</option>
                                <option value="cursor">Cursor</option>
                                <option value="claude-code">Claude Code</option>
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
                                className="w-full rounded-md border border-border px-3 py-2 text-sm"
                            />
                        </div>
                        {isAdmin && (
                            <div>
                                <label className="mb-2 block text-sm font-medium text-foreground">
                                    Actions
                                </label>
                                <button
                                    onClick={handleExport}
                                    className="w-full rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
                                >
                                    Export All
                                </button>
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
                                    className="flex items-center gap-3 py-2"
                                >
                                    <span className="tabular-nums">
                                        {failure.artifact_id.slice(0, 8)}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {failure.attempts} attempts:{' '}
                                        {failure.reason}
                                    </span>
                                    {isAdmin && (
                                        <button
                                            className="ml-auto underline"
                                            onClick={() =>
                                                router.post(
                                                    `/settings/teams/${team.slug}/console/artifacts/${failure.artifact_id}/reindex`,
                                                )
                                            }
                                        >
                                            Retry
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Artifact List */}
                <div
                    className="overflow-hidden rounded-[10px] border border-border bg-paper"
                    data-testid="artifact-list"
                >
                    <table className="w-full">
                        <thead className="border-b border-border bg-muted">
                            <tr>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-foreground">
                                    Title
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-foreground">
                                    Repository
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-foreground">
                                    Agent
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
                        <tbody className="divide-y divide-border">
                            {artifacts.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={hasActionsColumn ? 6 : 5}
                                        className="px-6 py-4 text-center text-muted-foreground"
                                    >
                                        No artifacts found
                                    </td>
                                </tr>
                            ) : (
                                artifacts.map((artifact) => (
                                    <tr
                                        key={artifact.id}
                                        className="hover:bg-muted"
                                    >
                                        <td className="px-6 py-4">
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
                                            <div className="text-xs text-muted-foreground">
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
                                                        className="mt-1 rounded border border-border bg-background px-1 text-xs"
                                                        value=""
                                                        onChange={(e) => {
                                                            if (
                                                                e.target.value
                                                            ) {
                                                                router.post(
                                                                    `/settings/teams/${team.slug}/collections/${e.target.value}/artifacts`,
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
                                        <td className="px-6 py-4 text-sm text-muted-foreground">
                                            {artifact.provenance.repo_url ? (
                                                <a
                                                    href={
                                                        artifact.provenance
                                                            .repo_url
                                                    }
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-primary hover:underline"
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
                                        <td className="px-6 py-4 text-sm text-muted-foreground">
                                            {artifact.provenance.agent ||
                                                'Unknown'}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-muted-foreground">
                                            {new Date(
                                                artifact.created_at,
                                            ).toLocaleDateString()}
                                        </td>
                                        <td className="px-6 py-4">
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
                                            <td className="px-6 py-4 text-sm">
                                                <div className="flex items-center gap-3">
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
                                                                className="font-medium text-primary hover:underline"
                                                                data-testid="open-artifact"
                                                            >
                                                                Open
                                                            </a>
                                                        ))}
                                                    {isAdmin &&
                                                        !artifact.revoked_at && (
                                                            <button
                                                                onClick={() =>
                                                                    handleRevoke(
                                                                        artifact.id,
                                                                    )
                                                                }
                                                                className="font-medium text-destructive hover:text-red-900"
                                                            >
                                                                {confirmingRevoke ===
                                                                artifact.id
                                                                    ? 'Confirm revoke?'
                                                                    : 'Revoke'}
                                                            </button>
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
                {nextCursor && (
                    <div className="mt-6 flex justify-center">
                        <button
                            onClick={() => {
                                const params = new URLSearchParams();
                                params.set('cursor', nextCursor);
                                Object.entries(localFilters).forEach(
                                    ([k, v]) => {
                                        if (v) {
                                            params.set(k, v as string);
                                        }
                                    },
                                );
                                router.get(
                                    `/settings/teams/${team.slug}/console?${params.toString()}`,
                                );
                            }}
                            className="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground hover:opacity-90"
                        >
                            Load More
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

ConsoleIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
