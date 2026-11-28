import { Head, Link, router, usePage } from '@inertiajs/react';
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
import { RowIcon, ShortDate } from '@/components/home/artifact-row';
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
import { cn } from '@/lib/utils';
import artifactsRoutes from '@/routes/artifacts';
import teamRoutes from '@/routes/teams';
import type { SharedProps } from '@/types/shared';

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

interface TemplateSummary {
    key: string;
    title: string;
    description: string;
    version: number;
    artifact_type: string;
    requires: string[];
    available: boolean;
    unavailable_reason: string | null;
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
    /** Every artifact type, from the server's enum, for the type filter. */
    artifactTypes: { value: string; label: string }[];
    /** True when the type filter matched more artifacts than the page lists. */
    typeFilterCapped: boolean;
    filters: {
        user_id: string | null;
        repo_url: string | null;
        agent: string | null;
        type: string | null;
        q: string | null;
    };
    nextCursor: string | null;
    cursor: string | null;
    isAdmin: boolean;
    canOpenArtifacts: boolean;
    collections: { id: number; name: string }[];
    canCollect: boolean;
    /** False until the templates feature is released: the section stays hidden. */
    templatesReleased: boolean;
    /** Viewers cannot pull: the section offers them no Use control. */
    canPullTemplates: boolean;
    templates: TemplateSummary[];
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

/**
 * The console's list visit, shared by the page and the rail facets. Every
 * filter travels as the page's existing query params (user_id, repo_url,
 * agent, q) through the existing index route, so a facet can only exist
 * when the backend honors its dimension. There is no collection filter on
 * the backend (and the page carries no counts), so the rail ships Owner
 * and Type facets only — never a dead Collections facet.
 */
function visitConsoleIndex(
    teamSlug: string,
    view: 'grid' | 'list',
    nextFilters: ConsoleIndexProps['filters'],
    {
        cursor = null,
        replace = true,
        nextView = view,
    }: {
        cursor?: string | null;
        replace?: boolean;
        nextView?: 'grid' | 'list';
    } = {},
) {
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
            { team: teamSlug },
            { query: Object.fromEntries(params.entries()) },
        ),
        {},
        { preserveState: true, preserveScroll: true, replace },
    );
}

/**
 * Known tools are always offered, so narrowing to one tool never hides the
 * others from the list; tools seen on this page and the active filter are
 * added. Shared by the page's dropdown and the rail's Type facet.
 */
function consoleAiTools(agents: string[], activeAgent: string | null) {
    return [
        ...new Set([
            ...Object.keys(AI_TOOL_NAMES),
            ...agents,
            ...(activeAgent ? [activeAgent] : []),
        ]),
    ].sort((a, b) => aiToolName(a).localeCompare(aiToolName(b)));
}

function ConsoleFacetButton({
    active,
    onClick,
    testId,
    children,
}: {
    active: boolean;
    onClick: () => void;
    testId: string;
    children: string;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            data-testid={testId}
            onClick={onClick}
            className={cn(
                'flex min-h-8 w-full cursor-pointer items-center rounded-md px-2.5 py-1 text-left text-[13px] font-medium break-words transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring pointer-coarse:min-h-11',
                active
                    ? 'bg-tint font-semibold text-foreground'
                    : 'text-muted-foreground',
            )}
        >
            {children}
        </button>
    );
}

/**
 * Screen-contextual rail for the Artifacts console: Owner (user_id) and
 * Type (agent) facets driving the page's existing filter params through
 * visitConsoleIndex. Rendered behind the unified-rail flag by
 * ConsoleIndexLayout below.
 */
function ConsoleRailContext() {
    const { team, view, filters, agents, currentUserId } = usePage<
        SharedProps &
            Pick<
                ConsoleIndexProps,
                'team' | 'view' | 'filters' | 'agents' | 'currentUserId'
            >
    >().props;
    const tools = consoleAiTools(agents, filters.agent);

    return (
        <nav
            aria-label="Artifact filters"
            data-testid="console-rail-facets"
            className="flex flex-col gap-4"
        >
            <div>
                <p className="px-2.5 text-xs font-medium text-muted-foreground">
                    Owner
                </p>
                <ul className="mt-1 flex flex-col gap-px">
                    <li>
                        <ConsoleFacetButton
                            testId="console-facet-owner-all"
                            active={!filters.user_id}
                            onClick={() =>
                                visitConsoleIndex(team.slug, view, {
                                    ...filters,
                                    user_id: null,
                                })
                            }
                        >
                            All
                        </ConsoleFacetButton>
                    </li>
                    <li>
                        <ConsoleFacetButton
                            testId="console-facet-owner-yours"
                            active={filters.user_id === currentUserId}
                            onClick={() =>
                                visitConsoleIndex(team.slug, view, {
                                    ...filters,
                                    user_id: currentUserId,
                                })
                            }
                        >
                            Yours
                        </ConsoleFacetButton>
                    </li>
                </ul>
            </div>
            <div>
                <p className="px-2.5 text-xs font-medium text-muted-foreground">
                    AI tool
                </p>
                <ul className="mt-1 flex flex-col gap-px">
                    <li>
                        <ConsoleFacetButton
                            testId="console-facet-type-all"
                            active={!filters.agent}
                            onClick={() =>
                                visitConsoleIndex(team.slug, view, {
                                    ...filters,
                                    agent: null,
                                })
                            }
                        >
                            All tools
                        </ConsoleFacetButton>
                    </li>
                    {tools.map((tool) => (
                        <li key={tool}>
                            <ConsoleFacetButton
                                testId={`console-facet-type-${tool}`}
                                active={filters.agent === tool}
                                onClick={() =>
                                    visitConsoleIndex(team.slug, view, {
                                        ...filters,
                                        agent: tool,
                                    })
                                }
                            >
                                {aiToolName(tool)}
                            </ConsoleFacetButton>
                        </li>
                    ))}
                </ul>
            </div>
        </nav>
    );
}

export default function ConsoleIndex({
    team,
    artifacts,
    view,
    currentUserId,
    previewUrls,
    agents,
    artifactTypes,
    typeFilterCapped,
    filters,
    nextCursor,
    cursor,
    isAdmin,
    canOpenArtifacts,
    collections,
    canCollect,
    templatesReleased,
    canPullTemplates,
    templates,
    indexingEnabled,
    indexing,
    indexingFailures,
}: ConsoleIndexProps) {
    const [localFilters, setLocalFilters] = useState(filters);
    const { features } = usePage<SharedProps>().props;
    const unifiedRail = features?.['unified-rail'] === true;
    /**
     * The Templates scope is a view toggle, not a backend filter: the worker
     * has no template-scope param, so it hides the artifact rows and leaves
     * the templates shelf standing. Only offered when templates exist.
     */
    const [templatesScope, setTemplatesScope] = useState(false);
    const templatesAvailable = templatesReleased && templates.length > 0;
    const [revoking, setRevoking] = useState<Artifact | null>(null);
    /**
     * The template pending confirmation, with the id its pull posts. The id
     * is minted when the dialog opens, so a double click on the confirm
     * button posts twice with one id and still makes one board.
     */
    const [useTemplate, setUseTemplate] = useState<{
        template: TemplateSummary;
        requestId: string;
    } | null>(null);
    const [pullingTemplate, setPullingTemplate] = useState(false);

    const openUseTemplate = (template: TemplateSummary) => {
        setUseTemplate({ template, requestId: crypto.randomUUID() });
    };

    const confirmUseTemplate = () => {
        if (useTemplate === null || pullingTemplate) {
            return;
        }

        setPullingTemplate(true);
        router.post(
            ConsoleController.pull.url({
                team: team.slug,
                template: useTemplate.template.key,
            }),
            { request_id: useTemplate.requestId },
            {
                preserveScroll: true,
                onFinish: () => {
                    setPullingTemplate(false);
                    setUseTemplate(null);
                },
            },
        );
    };
    const [searchOpen, setSearchOpen] = useState(Boolean(filters.q));
    const [filtersOpen, setFiltersOpen] = useState(
        Boolean(filters.repo_url || filters.agent || filters.type),
    );
    const pendingFilterVisit = useRef<ReturnType<typeof setTimeout> | null>(
        null,
    );
    const hasActionsColumn =
        isAdmin || canOpenArtifacts || (canCollect && collections.length > 0);
    // Yours (user_id) is a scope toggle, not a filter — Clear filters must not exit it.
    const hasActiveFilters = Boolean(
        localFilters.q ||
        localFilters.repo_url ||
        localFilters.agent ||
        localFilters.type,
    );
    const yoursOnly = Boolean(localFilters.user_id) && !hasActiveFilters;
    const aiTools = consoleAiTools(agents, localFilters.agent);

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
        visitConsoleIndex(team.slug, view, nextFilters, {
            cursor,
            replace,
            nextView,
        });
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
            user_id: localFilters.user_id,
            repo_url: null,
            agent: null,
            type: null,
            q: null,
        };
        setLocalFilters(cleared);
        setSearchOpen(false);
        setFiltersOpen(false);
        clearPendingFilterVisit();
        visitWithFilters(cleared);
    };

    const toggleSearchOpen = () => {
        if (searchOpen) {
            const nextFilters = { ...localFilters, q: null };
            setLocalFilters(nextFilters);
            clearPendingFilterVisit();
            visitWithFilters(nextFilters);
            setSearchOpen(false);

            return;
        }

        setSearchOpen(true);
    };

    const toggleFiltersOpen = () => {
        if (filtersOpen) {
            const nextFilters = {
                ...localFilters,
                repo_url: null,
                agent: null,
                type: null,
            };
            setLocalFilters(nextFilters);
            clearPendingFilterVisit();
            visitWithFilters(nextFilters);
            setFiltersOpen(false);

            return;
        }

        setFiltersOpen(true);
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
     * Direct short viewer link (`/a/<id>`), consistent with Home and Collections.
     */
    const openHref = (artifactId: string) =>
        artifactsRoutes.show.url({ artifactId });

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
                {unifiedRail && (
                    <div className="relative min-w-52 flex-1">
                        <label
                            htmlFor="console-command-search"
                            className="sr-only"
                        >
                            Search
                        </label>
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            id="console-command-search"
                            type="text"
                            data-testid="console-command-search"
                            value={localFilters.q || ''}
                            onChange={(e) =>
                                handleFilterChange('q', e.target.value)
                            }
                            placeholder="Search by title or description"
                            className="h-10 w-full rounded-md border border-border bg-background/60 pr-3 pl-9 text-sm text-foreground placeholder:text-muted-foreground max-md:min-h-[44px] max-md:text-base"
                        />
                    </div>
                )}
                <div
                    role="group"
                    aria-label="Whose artifacts to show"
                    className="inline-flex rounded-md border border-border p-0.5"
                >
                    <ScopeButton
                        active={!localFilters.user_id && !templatesScope}
                        onClick={() => {
                            setTemplatesScope(false);
                            handleSelectChange('user_id', '');
                        }}
                    >
                        All
                    </ScopeButton>
                    <ScopeButton
                        active={
                            localFilters.user_id === currentUserId &&
                            !templatesScope
                        }
                        onClick={() => {
                            setTemplatesScope(false);
                            handleSelectChange('user_id', currentUserId);
                        }}
                    >
                        Yours
                    </ScopeButton>
                    {unifiedRail && templatesAvailable && (
                        <ScopeButton
                            active={templatesScope}
                            onClick={() => setTemplatesScope(true)}
                        >
                            Templates
                        </ScopeButton>
                    )}
                </div>

                <div className="ml-auto flex items-center gap-1">
                    {!unifiedRail && (
                        <ToolbarButton
                            label="Search artifacts"
                            pressed={searchOpen || Boolean(localFilters.q)}
                            onClick={toggleSearchOpen}
                        >
                            <Search className="size-5" />
                        </ToolbarButton>
                    )}
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
                        pressed={
                            filtersOpen ||
                            Boolean(
                                localFilters.repo_url ||
                                localFilters.agent ||
                                localFilters.type,
                            )
                        }
                        onClick={toggleFiltersOpen}
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

            {!unifiedRail && searchOpen && (
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
                <div
                    className={`mb-6 grid grid-cols-1 gap-3 rounded-[10px] border border-border bg-paper p-4 ${unifiedRail ? 'md:grid-cols-2' : 'md:grid-cols-3'}`}
                >
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
                    {/* The rail owns the Type facet when the flag is on; the panel keeps Repository. */}
                    {!unifiedRail && (
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
                    )}
                    <div>
                        <label
                            htmlFor="type"
                            className="mb-1.5 block text-xs font-medium text-muted-foreground"
                        >
                            Type
                        </label>
                        <select
                            id="type"
                            name="type"
                            value={localFilters.type || ''}
                            onChange={(e) =>
                                handleSelectChange('type', e.target.value)
                            }
                            className="h-9 w-full rounded-md border border-border bg-background/60 px-3 text-sm text-foreground max-md:min-h-[44px] max-md:text-base"
                        >
                            <option value="">All types</option>
                            {artifactTypes.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
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

            {templatesReleased &&
                templates.length > 0 &&
                (!unifiedRail || templatesScope) && (
                    <section
                        aria-labelledby="templates-heading"
                        data-testid="template-list"
                        className="mb-8 border-t border-border pt-6"
                    >
                        <h2
                            id="templates-heading"
                            className="text-sm font-semibold"
                        >
                            Templates
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Start from a reviewed page instead of a blank one. A
                            pull makes a new board in your library with the
                            template&apos;s starter cards.
                        </p>
                        <ul className="mt-4 divide-y divide-border border-y border-border">
                            {templates.map((template) => (
                                <li
                                    key={template.key}
                                    data-testid={`template-row-${template.key}`}
                                    className="flex flex-col gap-3 py-4 md:flex-row md:items-start md:justify-between md:gap-6"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="min-w-0 font-semibold break-words text-foreground">
                                            {template.title}
                                        </p>
                                        {template.description && (
                                            <p className="mt-0.5 min-w-0 text-sm break-words text-muted-foreground">
                                                {template.description}
                                            </p>
                                        )}
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {template.available
                                                ? 'Ready to use'
                                                : template.unavailable_reason}
                                        </p>
                                    </div>
                                    {canPullTemplates && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={!template.available}
                                            onClick={() =>
                                                openUseTemplate(template)
                                            }
                                            data-testid={`use-template-${template.key}`}
                                        >
                                            Use template
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

            {typeFilterCapped && artifacts.length > 0 && (
                <p
                    className="mb-3 text-sm text-muted-foreground"
                    data-testid="type-filter-capped"
                >
                    Showing the 200 most recent of this type. Narrow the filter
                    to see others.
                </p>
            )}

            {/* Editorial Hairline Artifact List */}
            {!templatesScope && (
                <div data-testid="artifact-list">
                    {artifacts.length === 0 ? (
                        hasActiveFilters ? (
                            <EmptyState title="No artifacts match these filters">
                                <Button
                                    variant="outline"
                                    onClick={clearFilters}
                                >
                                    Clear filters
                                </Button>
                            </EmptyState>
                        ) : yoursOnly ? (
                            <EmptyState title="You haven’t shared any artifacts yet">
                                <p className="text-sm text-muted-foreground">
                                    Artifacts you share will show up here under
                                    Yours.
                                </p>
                            </EmptyState>
                        ) : (
                            <EmptyState title="No artifacts yet">
                                <p className="text-sm text-muted-foreground">
                                    Connect an AI tool, then share something it
                                    made. It will appear here.
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
                                // Revoked cards have no actions — do not open an empty menu for admins.
                                const hasMenu =
                                    openable ||
                                    canAddToCollection ||
                                    (isAdmin && !artifact.revoked_at);

                                return (
                                    <li
                                        key={artifact.id}
                                        data-testid={`artifact-card-${artifact.id}`}
                                        className="group flex min-w-0 flex-col overflow-hidden rounded-[10px] border border-border bg-paper transition-colors duration-200 hover:border-foreground/25"
                                    >
                                        <div className="relative overflow-hidden">
                                            <div className="transition-transform duration-300 ease-out group-hover:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover:scale-100">
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
                                            </div>
                                            {artifact.provenance.agent && (
                                                <span
                                                    aria-hidden="true"
                                                    className="absolute bottom-2 left-2 flex size-8 items-center justify-center rounded-md border border-border bg-paper transition-transform duration-200 group-hover:-translate-y-0.5 motion-reduce:transition-none motion-reduce:group-hover:translate-y-0"
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
                                                        className="min-w-0 font-semibold break-words text-muted-foreground max-md:block max-md:min-h-11 max-md:py-2"
                                                        data-testid="open-artifact-title-disabled"
                                                        title={
                                                            revokedOpenTooltip
                                                        }
                                                        aria-disabled="true"
                                                    >
                                                        {artifactTitle(
                                                            artifact,
                                                        )}
                                                    </span>
                                                ) : openable ? (
                                                    <a
                                                        href={openHref(
                                                            artifact.id,
                                                        )}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="min-w-0 font-semibold break-words text-foreground underline-offset-2 hover:text-[var(--accent)] hover:underline max-md:block max-md:min-h-11 max-md:py-2"
                                                        data-testid="open-artifact-title"
                                                    >
                                                        {artifactTitle(
                                                            artifact,
                                                        )}
                                                    </a>
                                                ) : (
                                                    <span className="min-w-0 font-semibold break-words text-foreground max-md:block max-md:min-h-11 max-md:py-2">
                                                        {artifactTitle(
                                                            artifact,
                                                        )}
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
                                                        <ShortDate
                                                            iso={
                                                                artifact.created_at
                                                            }
                                                        />
                                                    </span>
                                                    {artifact.revoked_at && (
                                                        <Badge variant="destructive">
                                                            Revoked
                                                        </Badge>
                                                    )}
                                                    {indexingEnabled &&
                                                        indexing[
                                                            artifact.id
                                                        ] === 'failed' && (
                                                            <Badge variant="warning">
                                                                Not searchable
                                                            </Badge>
                                                        )}
                                                </p>
                                            </div>
                                            {hasMenu && (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
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
                                                                            {
                                                                                c.name
                                                                            }
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
                                                        className="min-w-0 font-semibold break-words text-muted-foreground max-md:block max-md:min-h-11 max-md:py-2"
                                                        data-testid="open-artifact-title-disabled"
                                                        title={
                                                            revokedOpenTooltip
                                                        }
                                                        aria-disabled="true"
                                                    >
                                                        {artifactTitle(
                                                            artifact,
                                                        )}
                                                    </span>
                                                ) : canOpenArtifacts ? (
                                                    <a
                                                        href={openHref(
                                                            artifact.id,
                                                        )}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="min-w-0 font-semibold break-words text-foreground underline-offset-2 hover:text-[var(--accent)] hover:underline max-md:block max-md:min-h-11 max-md:py-2"
                                                        data-testid="open-artifact-title"
                                                    >
                                                        {artifactTitle(
                                                            artifact,
                                                        )}
                                                    </a>
                                                ) : (
                                                    <span className="min-w-0 font-semibold break-words text-foreground max-md:block max-md:min-h-11 max-md:py-2">
                                                        {artifactTitle(
                                                            artifact,
                                                        )}
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
                                                    {artifact.provenance
                                                        .agent && (
                                                        <AiToolIcon
                                                            tool={
                                                                artifact
                                                                    .provenance
                                                                    .agent
                                                            }
                                                            size={13}
                                                        />
                                                    )}
                                                    {aiToolName(
                                                        artifact.provenance
                                                            .agent,
                                                    )}
                                                </span>
                                                <span aria-hidden="true">
                                                    ·
                                                </span>
                                                {artifact.provenance
                                                    .repo_url ? (
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
                                                <span aria-hidden="true">
                                                    ·
                                                </span>
                                                <span className="tabular-nums">
                                                    <ShortDate
                                                        iso={
                                                            artifact.created_at
                                                        }
                                                    />
                                                </span>
                                                <span aria-hidden="true">
                                                    ·
                                                </span>
                                                <span data-testid="indexing-state">
                                                    Indexing:{' '}
                                                    {indexing[artifact.id] ??
                                                        'off'}
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
                                                collections.length > 0 &&
                                                !artifact.revoked_at && (
                                                    <select
                                                        aria-label={`Add ${artifact.title || artifact.id} to a collection`}
                                                        className="h-8 rounded-md border border-border bg-paper px-2.5 text-xs text-foreground max-md:min-h-[44px] max-md:text-base"
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
                                            {canOpenArtifacts &&
                                                (artifact.revoked_at ? (
                                                    <span
                                                        className="inline-flex h-8 cursor-not-allowed items-center rounded-md border border-border px-3 text-xs font-medium text-muted-foreground opacity-60 max-md:min-h-[44px]"
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
                                                        className="inline-flex h-8 items-center rounded-md border border-border bg-paper/70 px-3 text-xs font-medium text-foreground transition-colors hover:border-foreground/35 hover:bg-muted/60 max-md:min-h-[44px]"
                                                        data-testid="open-artifact"
                                                    >
                                                        Open
                                                    </a>
                                                ))}
                                            {isAdmin &&
                                                !artifact.revoked_at && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
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
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {/* Pagination */}
            {!templatesScope && (nextCursor || cursor) && (
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

            {useTemplate && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setUseTemplate(null);
                        }
                    }}
                    title={`Use ${useTemplate.template.title}?`}
                    description="This makes a new board in your library with the template's starter cards. A pull counts as a publish toward quota and the daily limit."
                    confirmLabel="Use template"
                    onConfirm={confirmUseTemplate}
                    cancelLabel="Not now"
                    destructive={false}
                    processing={pullingTemplate}
                />
            )}
        </div>
    );
}

function ConsoleIndexLayout({ page }: { page: React.ReactNode }) {
    const { features } = usePage<SharedProps>().props;

    return (
        <AppLayout
            railContext={
                features?.['unified-rail'] === true ? (
                    <ConsoleRailContext />
                ) : undefined
            }
        >
            {page}
        </AppLayout>
    );
}

ConsoleIndex.layout = (page: React.ReactNode) => (
    <ConsoleIndexLayout page={page} />
);
