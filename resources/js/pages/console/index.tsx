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
}

export default function ConsoleIndex({
    team,
    artifacts,
    filters,
    nextCursor,
    isAdmin,
}: ConsoleIndexProps) {
    const [localFilters, setLocalFilters] = useState(filters);
    const [confirmingRevoke, setConfirmingRevoke] = useState<string | null>(
        null,
    );

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

    return (
        <div>
            <div>
                <div className="mb-8">
                    <h1 className="text-3xl font-bold text-foreground">
                        {team.name} Console
                    </h1>
                    <p className="mt-2 text-muted-foreground">
                        Manage artifacts produced by your team
                    </p>
                </div>

                {/* Filters */}
                <div className="mb-8 rounded-lg bg-background p-6 shadow-sm">
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

                {/* Artifact List */}
                <div
                    className="overflow-hidden rounded-lg bg-background shadow-sm"
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
                                {isAdmin && (
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
                                        colSpan={isAdmin ? 6 : 5}
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
                                            <div className="text-sm font-medium text-foreground">
                                                {artifact.title || (
                                                    <span className="font-mono">
                                                        {artifact.id.slice(
                                                            0,
                                                            8,
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {artifact.description}
                                            </div>
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
                                        {isAdmin && (
                                            <td className="px-6 py-4 text-sm">
                                                {!artifact.revoked_at && (
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
