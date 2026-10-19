import { Head, Link, router } from '@inertiajs/react';
import {
    LayoutGrid,
    List,
    MoreHorizontal,
    Search,
    SlidersHorizontal,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import ArtifactVersionController from '@/actions/App/Http/Controllers/ArtifactVersionController';
import ConsoleController from '@/actions/App/Http/Controllers/ConsoleController';
import CollectionController from '@/actions/App/Http/Controllers/Teams/CollectionController';
import { AiToolIcon } from '@/components/ai-tool-icon';
import { ArtifactPreview } from '@/components/artifact-preview';
import { RowIcon, shortDate } from '@/components/home/artifact-row';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
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
    view: 'grid' | 'list';
    currentUserId: string;
    /** Session-authenticated preview URLs by artifact id; revoked artifacts have none. */
    previewUrls: Record<string, string>;
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

function ToolbarButton({
    label,
    pressed,
    onClick,
    children,
}: {
    label: string;
    pressed?: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            aria-pressed={pressed}
            onClick={onClick}
            className={`inline-flex size-10 cursor-pointer items-center justify-center rounded-md transition-colors max-md:size-11 ${
                pressed
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
            }`}
        >
            {children}
        </button>
    );
}

export default function ConsoleIndex({
    team,
    artifacts,
    view,
    currentUserId,
    previewUrls,
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
    const [searchOpen, setSearchOpen] = useState(Boolean(filters.q));
    const [filtersOpen, setFiltersOpen] = useState(
        Boolean(filters.repo_url || filters.agent),
    );
    const pendingFilterVisit = useRef<ReturnType<typeof setTimeout> | null>(
        null,
    );
    const hasActionsColumn =
        isAdmin || canOpenArtifacts || (canCollect && collections.length > 0);
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
            nextView = view,
        }: {
            cursor?: string | null;
            replace?: boolean;
            nextView?: 'grid' | 'list';
        } = {},
    ) => {
        const params = new URLSearchParams();

        if (nextView === 'list') {
            params.set('view', 'list');
        }

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
            <Head title="Artifacts" />
            <PageHeader
                eyebrow={team.name}
                title="Artifacts"
                description="Everything your team has shared, in one place."
            />

            <div className="mb-6 flex flex-wrap items-center gap-3">
                <div
                    role="group"
                    aria-label="Whose artifacts to show"
                    className="inline-flex rounded-md border border-border p-0.5"
                >
                    <ScopeButton
                        active={!localFilters.user_id}
                        onClick={() => handleSelectChange('user_id', '')}
                    >
                        All
                    </ScopeButton>
                    <ScopeButton
                        active={localFilters.user_id === currentUserId}
                        onClick={() =>
                            handleSelectChange('user_id', currentUserId)
                        }
                    >
                        Yours
                    </ScopeButton>
                </div>

                <div className="ml-auto flex items-center gap-1">
                    <ToolbarButton
                        label="Search artifacts"
                        pressed={searchOpen}
                        onClick={() => setSearchOpen((open) => !open)}
                    >
                        <Search className="size-5" />
                    </ToolbarButton>
                    <ToolbarButton
                        label={
                            view === 'grid' ? 'Show as list' : 'Show as grid'
                        }
                        onClick={() =>
                            visitWithFilters(localFilters, {
                                nextView: view === 'grid' ? 'list' : 'grid',
                                replace: false,
                            })
                        }
                    >
                        {view === 'grid' ? (
                            <List className="size-5" />
                        ) : (
                            <LayoutGrid className="size-5" />
                        )}
                    </ToolbarButton>
                    <ToolbarButton
                        label="Filter artifacts"
                        pressed={filtersOpen}
                        onClick={() => setFiltersOpen((open) => !open)}
                    >
                        <SlidersHorizontal className="size-5" />
                    </ToolbarButton>
                    {isAdmin && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    aria-label="More actions"
                                    className="inline-flex size-10 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground max-md:size-11"
                                >
                                    <MoreHorizontal className="size-5" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild>
                                    <a
                                        href={ConsoleController.export.url({
                                            team: team.slug,
                                        })}
                                    >
                                        Download export (.zip)
                                    </a>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </div>

            {searchOpen && (
                <div className="mb-6">
                    <label htmlFor="console-search" className="sr-only">
                        Search
                    </label>
                    <div className="relative">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            id="console-search"
                            type="text"
                            autoFocus
                            value={localFilters.q || ''}
                            onChange={(e) =>
                                handleFilterChange('q', e.target.value)
                            }
                            placeholder="Search by title or description"
                            className="h-10 w-full rounded-md border border-border bg-background/60 pr-3 pl-9 text-sm text-foreground placeholder:text-muted-foreground max-md:min-h-[44px] max-md:text-base"
                        />
                    </div>
                </div>
            )}

            {filtersOpen && (
                <div className="mb-6 grid grid-cols-1 gap-3 rounded-[10px] border border-border bg-paper p-4 md:grid-cols-2">
                    <div>
                        <label
                            htmlFor="repo_url"
                            className="mb-1.5 block text-xs font-medium text-muted-foreground"
                        >
                            Repository
                        </label>
                        <input
                            id="repo_url"
                            name="repo_url"
                            type="text"
                            value={localFilters.repo_url || ''}
                            onChange={(e) =>
                                handleFilterChange('repo_url', e.target.value)
                            }
                            placeholder="github.com/example/repo"
                            className="h-9 w-full rounded-md border border-border bg-background/60 px-3 text-sm text-foreground placeholder:text-muted-foreground max-md:min-h-[44px] max-md:text-base"
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="agent"
                            className="mb-1.5 block text-xs font-medium text-muted-foreground"
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
                            className="h-9 w-full rounded-md border border-border bg-background/60 px-3 text-sm text-foreground max-md:min-h-[44px] max-md:text-base"
                        >
                            <option value="">All AI tools</option>
                            {aiTools.map((agent) => (
                                <option key={agent} value={agent}>
                                    {aiToolName(agent)}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            )}

            {hasActiveFilters && (
                <div className="mb-6 flex items-center justify-between text-xs text-muted-foreground">
                    <span>Filtered results</span>
                    <button
                        type="button"
                        onClick={clearFilters}
                        className="cursor-pointer font-medium text-[var(--accent)] hover:underline"
                    >
                        Clear filters
                    </button>
                </div>
            )}

            {!indexingEnabled && (
                <p className="mb-6 text-sm text-muted-foreground">
                    Search indexing is turned off on this environment, so new
                    artifacts are not indexed yet.
                </p>
            )}
            {indexingEnabled && indexingFailures.length > 0 && (
                <div className="mb-6 rounded-[10px] border border-border bg-paper p-4">
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
                                        variant="outline"
                                        size="sm"
                                        className="ml-auto"
                                        onClick={() =>
                                            router.post(
                                                ConsoleController.reindex.url({
                                                    team: team.slug,
                                                    artifactId:
                                                        failure.artifact_id,
                                                }),
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

            {/* Editorial Hairline Artifact List */}
            <div data-testid="artifact-list">
                {artifacts.length === 0 ? (
                    hasActiveFilters ? (
                        <EmptyState title="No artifacts match these filters">
                            <Button variant="outline" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        </EmptyState>
                    ) : (
                        <EmptyState title="No artifacts yet">
                            <p className="text-sm text-muted-foreground">
                                Connect an AI tool, then share something it
                                made. It will appear here.
                            </p>
                            <Button asChild variant="outline" className="mt-4">
                                <Link
                                    href={teamRoutes.mcpConnections.index.url({
                                        team: team.slug,
                                    })}
                                >
                                    AI tool connections
                                </Link>
                            </Button>
                        </EmptyState>
                    )
                ) : view === 'grid' ? (
                    <ul
                        data-testid="artifact-grid"
                        className="grid grid-cols-1 gap-x-5 gap-y-6 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        {artifacts.map((artifact) => {
                            const previewUrl = previewUrls[artifact.id];
                            const openable =
                                canOpenArtifacts && !artifact.revoked_at;
                            const canAddToCollection =
                                canCollect &&
                                collections.length > 0 &&
                                !artifact.revoked_at;
                            const hasMenu =
                                !artifact.revoked_at ||
                                isAdmin ||
                                canAddToCollection;

                            return (
                                <li
                                    key={artifact.id}
                                    data-testid={`artifact-card-${artifact.id}`}
                                    className="flex min-w-0 flex-col overflow-hidden rounded-[10px] border border-border bg-paper"
                                >
                                    <div className="relative">
                                        {previewUrl ? (
                                            <ArtifactPreview
                                                previewUrl={previewUrl}
                                                title={`Preview of ${artifactName(artifact)}`}
                                                decorative
                                                className="border-b border-border"
                                            />
                                        ) : (
                                            <div
                                                aria-hidden="true"
                                                className="flex aspect-[8/5] w-full items-center justify-center border-b border-border bg-muted/50"
                                            >
                                                <RowIcon />
                                            </div>
                                        )}
                                        {artifact.provenance.agent && (
                                            <span
                                                aria-hidden="true"
                                                className="absolute bottom-2 left-2 flex size-8 items-center justify-center rounded-md border border-border bg-paper"
                                            >
                                                <AiToolIcon
                                                    tool={
                                                        artifact.provenance
                                                            .agent
                                                    }
                                                    size={16}
                                                />
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex min-w-0 items-start gap-2 p-4">
                                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                                            {artifact.revoked_at ? (
                                                <span
                                                    className="min-w-0 font-semibold break-words text-muted-foreground"
                                                    data-testid="open-artifact-title-disabled"
                                                    title={revokedOpenTooltip}
                                                    aria-disabled="true"
                                                >
                                                    {artifactTitle(artifact)}
                                                </span>
                                            ) : openable ? (
                                                <a
                                                    href={openHref(artifact.id)}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="min-w-0 font-semibold break-words text-foreground underline-offset-2 hover:text-[var(--accent)] hover:underline"
                                                    data-testid="open-artifact-title"
                                                >
                                                    {artifactTitle(artifact)}
                                                </a>
                                            ) : (
                                                <span className="min-w-0 font-semibold break-words text-foreground">
                                                    {artifactTitle(artifact)}
                                                </span>
                                            )}
                                            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                                <span>
                                                    {aiToolName(
                                                        artifact.provenance
                                                            .agent,
                                                    )}
                                                </span>
                                                <span aria-hidden="true">
                                                    ·
                                                </span>
                                                <span className="tabular-nums">
                                                    {shortDate(
                                                        artifact.created_at,
                                                    )}
                                                </span>
                                                {artifact.revoked_at && (
                                                    <Badge variant="destructive">
                                                        Revoked
                                                    </Badge>
                                                )}
                                                {indexingEnabled &&
                                                    indexing[artifact.id] ===
                                                        'failed' && (
                                                        <Badge variant="warning">
                                                            Not searchable
                                                        </Badge>
                                                    )}
                                            </p>
                                        </div>
                                        {hasMenu && (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <button
                                                        type="button"
                                                        aria-label={`Actions for ${artifactName(artifact)}`}
                                                        className="-mt-1 -mr-2 inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground max-md:size-11"
                                                    >
                                                        <MoreHorizontal className="size-5" />
                                                    </button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    {openable && (
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <a
                                                                href={openHref(
                                                                    artifact.id,
                                                                )}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                Open
                                                            </a>
                                                        </DropdownMenuItem>
                                                    )}
                                                    {!artifact.revoked_at && (
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <Link
                                                                href={ArtifactVersionController.show.url(
                                                                    {
                                                                        team: team.slug,
                                                                        artifactId:
                                                                            artifact.id,
                                                                    },
                                                                )}
                                                                data-testid={`artifact-history-${artifact.id}`}
                                                            >
                                                                History
                                                            </Link>
                                                        </DropdownMenuItem>
                                                    )}
                                                    {canAddToCollection && (
                                                        <>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuLabel>
                                                                Add to
                                                                collection
                                                            </DropdownMenuLabel>
                                                            {collections.map(
                                                                (c) => (
                                                                    <DropdownMenuItem
                                                                        key={
                                                                            c.id
                                                                        }
                                                                        onSelect={() =>
                                                                            router.post(
                                                                                CollectionController.addArtifact.url(
                                                                                    {
                                                                                        team: team.slug,
                                                                                        collection:
                                                                                            c.id,
                                                                                    },
                                                                                ),
                                                                                {
                                                                                    artifact_id:
                                                                                        artifact.id,
                                                                                },
                                                                                {
                                                                                    preserveScroll: true,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        {c.name}
                                                                    </DropdownMenuItem>
                                                                ),
                                                            )}
                                                        </>
                                                    )}
                                                    {isAdmin &&
                                                        !artifact.revoked_at && (
                                                            <>
                                                                <DropdownMenuSeparator />
                                                                <DropdownMenuItem
                                                                    className="text-destructive"
                                                                    onSelect={() =>
                                                                        setRevoking(
                                                                            artifact,
                                                                        )
                                                                    }
                                                                >
                                                                    Revoke
                                                                </DropdownMenuItem>
                                                            </>
                                                        )}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <ul className="divide-y divide-border border-y border-border">
                        {artifacts.map((artifact) => (
                            <li
                                key={artifact.id}
                                className="flex flex-col gap-3 py-4 transition-colors md:flex-row md:items-start md:justify-between md:gap-6"
                            >
                                <div className="flex min-w-0 flex-1 items-start gap-3">
                                    <RowIcon />
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            {artifact.revoked_at ? (
                                                <span
                                                    className="min-w-0 font-semibold break-words text-muted-foreground"
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
                                                    className="min-w-0 font-semibold break-words text-foreground underline-offset-2 hover:text-[var(--accent)] hover:underline"
                                                    data-testid="open-artifact-title"
                                                >
                                                    {artifactTitle(artifact)}
                                                </a>
                                            ) : (
                                                <span className="min-w-0 font-semibold break-words text-foreground">
                                                    {artifactTitle(artifact)}
                                                </span>
                                            )}
                                            {artifact.revoked_at ? (
                                                <Badge variant="destructive">
                                                    Revoked
                                                </Badge>
                                            ) : (
                                                <Badge variant="success">
                                                    Active
                                                </Badge>
                                            )}
                                        </div>

                                        {artifact.description && (
                                            <p className="min-w-0 text-sm break-words text-muted-foreground">
                                                {artifact.description}
                                            </p>
                                        )}

                                        <div className="mt-0.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-muted-foreground">
                                            <span className="inline-flex items-center gap-1.5">
                                                {artifact.provenance.agent && (
                                                    <AiToolIcon
                                                        tool={
                                                            artifact.provenance
                                                                .agent
                                                        }
                                                        size={13}
                                                    />
                                                )}
                                                {aiToolName(
                                                    artifact.provenance.agent,
                                                )}
                                            </span>
                                            <span aria-hidden="true">·</span>
                                            {artifact.provenance.repo_url ? (
                                                <a
                                                    href={
                                                        artifact.provenance
                                                            .repo_url
                                                    }
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="font-mono text-xs break-all text-[var(--accent)] hover:underline"
                                                >
                                                    {artifact.provenance.repo_url
                                                        .split('/')
                                                        .slice(-2)
                                                        .join('/')}
                                                </a>
                                            ) : (
                                                <span>Unknown repo</span>
                                            )}
                                            <span aria-hidden="true">·</span>
                                            <span className="tabular-nums">
                                                {shortDate(artifact.created_at)}
                                            </span>
                                            <span aria-hidden="true">·</span>
                                            <span data-testid="indexing-state">
                                                Indexing:{' '}
                                                {indexing[artifact.id] ?? 'off'}
                                            </span>
                                            {!artifact.revoked_at && (
                                                <>
                                                    <span aria-hidden="true">
                                                        ·
                                                    </span>
                                                    <Link
                                                        href={ArtifactVersionController.show.url(
                                                            {
                                                                team: team.slug,
                                                                artifactId:
                                                                    artifact.id,
                                                            },
                                                        )}
                                                        className="font-medium text-[var(--accent)] hover:underline"
                                                        data-testid={`artifact-history-${artifact.id}`}
                                                    >
                                                        History
                                                    </Link>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {hasActionsColumn && (
                                    <div className="flex shrink-0 flex-wrap items-center gap-2 md:pt-0.5">
                                        {canCollect &&
                                            collections.length > 0 && (
                                                <select
                                                    aria-label={`Add ${artifact.title || artifact.id} to a collection`}
                                                    className="h-8 rounded-md border border-border bg-paper px-2.5 text-xs text-foreground max-md:min-h-[44px] max-md:text-base"
                                                    value=""
                                                    onChange={(e) => {
                                                        if (e.target.value) {
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
                                                    {collections.map((c) => (
                                                        <option
                                                            key={c.id}
                                                            value={c.id}
                                                        >
                                                            {c.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                        {canOpenArtifacts &&
                                            (artifact.revoked_at ? (
                                                <span
                                                    className="inline-flex h-8 cursor-not-allowed items-center rounded-md border border-border px-3 text-xs font-medium text-muted-foreground opacity-60 max-md:min-h-[44px]"
                                                    data-testid="open-artifact-disabled"
                                                    title={revokedOpenTooltip}
                                                    aria-disabled="true"
                                                >
                                                    Open
                                                </span>
                                            ) : (
                                                <a
                                                    href={openHref(artifact.id)}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex h-8 items-center rounded-md border border-border bg-paper/70 px-3 text-xs font-medium text-foreground transition-colors hover:border-foreground/35 hover:bg-muted/60 max-md:min-h-[44px]"
                                                    data-testid="open-artifact"
                                                >
                                                    Open
                                                </a>
                                            ))}
                                        {isAdmin && !artifact.revoked_at && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                aria-label={`Revoke ${artifactName(artifact)}`}
                                                onClick={() =>
                                                    setRevoking(artifact)
                                                }
                                            >
                                                Revoke
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
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
    );
}

ConsoleIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
