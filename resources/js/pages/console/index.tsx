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
        <div className="min-h-screen bg-gray-50 px-4 py-12">
            <div className="mx-auto max-w-7xl">
                <div className="mb-8">
                    <h1 className="text-3xl font-bold text-gray-900">
                        {team.name} Console
                    </h1>
                    <p className="mt-2 text-gray-600">
                        Manage artifacts produced by your team
                    </p>
                </div>

                {/* Filters */}
                <div className="mb-8 rounded-lg bg-white p-6 shadow-sm">
                    <h2 className="mb-4 text-lg font-semibold text-gray-900">
                        Filters
                    </h2>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label
                                htmlFor="repo_url"
                                className="mb-2 block text-sm font-medium text-gray-700"
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
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="mb-2 block text-sm font-medium text-gray-700">
                                Agent
                            </label>
                            <select
                                value={localFilters.agent || ''}
                                onChange={(e) =>
                                    handleFilterChange('agent', e.target.value)
                                }
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            >
                                <option value="">All agents</option>
                                <option value="cursor">Cursor</option>
                                <option value="claude-code">Claude Code</option>
                            </select>
                        </div>
                        <div>
                            <label className="mb-2 block text-sm font-medium text-gray-700">
                                Search
                            </label>
                            <input
                                type="text"
                                value={localFilters.q || ''}
                                onChange={(e) =>
                                    handleFilterChange('q', e.target.value)
                                }
                                placeholder="Title or description..."
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                        </div>
                        {isAdmin && (
                            <div>
                                <label className="mb-2 block text-sm font-medium text-gray-700">
                                    Actions
                                </label>
                                <button
                                    onClick={handleExport}
                                    className="w-full rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                                >
                                    Export All
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Artifact List */}
                <div
                    className="overflow-hidden rounded-lg bg-white shadow-sm"
                    data-testid="artifact-list"
                >
                    <table className="w-full">
                        <thead className="border-b border-gray-200 bg-gray-50">
                            <tr>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">
                                    Title
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">
                                    Repository
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">
                                    Agent
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">
                                    Created
                                </th>
                                <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">
                                    Status
                                </th>
                                {isAdmin && (
                                    <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">
                                        Actions
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200">
                            {artifacts.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={isAdmin ? 6 : 5}
                                        className="px-6 py-4 text-center text-gray-500"
                                    >
                                        No artifacts found
                                    </td>
                                </tr>
                            ) : (
                                artifacts.map((artifact) => (
                                    <tr
                                        key={artifact.id}
                                        className="hover:bg-gray-50"
                                    >
                                        <td className="px-6 py-4">
                                            <div className="text-sm font-medium text-gray-900">
                                                {artifact.title}
                                            </div>
                                            <div className="text-xs text-gray-500">
                                                {artifact.description}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-600">
                                            {artifact.provenance.repo_url ? (
                                                <a
                                                    href={
                                                        artifact.provenance
                                                            .repo_url
                                                    }
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-blue-600 hover:underline"
                                                >
                                                    {artifact.provenance.repo_url
                                                        .split('/')
                                                        .slice(-2)
                                                        .join('/')}
                                                </a>
                                            ) : (
                                                <span className="text-gray-400">
                                                    Unknown
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-600">
                                            {artifact.provenance.agent ||
                                                'Unknown'}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-600">
                                            {new Date(
                                                artifact.created_at,
                                            ).toLocaleDateString()}
                                        </td>
                                        <td className="px-6 py-4">
                                            {artifact.revoked_at ? (
                                                <span className="inline-block rounded bg-red-100 px-2 py-1 text-xs font-semibold text-red-800">
                                                    Revoked
                                                </span>
                                            ) : (
                                                <span className="inline-block rounded bg-green-100 px-2 py-1 text-xs font-semibold text-green-800">
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
                                                        className="font-medium text-red-600 hover:text-red-900"
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
                            className="rounded-md bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700"
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
