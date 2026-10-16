import { router } from '@inertiajs/react';
import { ChevronDown, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import type {
    HomeFilterCollection,
    HomeFilters,
} from '@/components/home/types';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';

interface SearchBlockProps {
    team: { slug: string; name: string };
    filters: HomeFilters;
    indexingEnabled: boolean;
    filterCollections: HomeFilterCollection[];
    onBusyChange: (busy: boolean) => void;
}

interface SearchDraft {
    q: string;
    collection: string;
    since: string;
}

/** Params for the dashboard URL: only non-empty values, scope only for mine. */
function dashboardParams(
    draft: SearchDraft,
    scope: HomeFilters['scope'],
): Record<string, string> {
    const params: Record<string, string> = {};

    if (draft.q.trim() !== '') {
        params.q = draft.q.trim();
    }

    if (draft.collection !== '') {
        params.collection = draft.collection;
    }

    if (draft.since !== '') {
        params.since = draft.since;
    }

    if (scope === 'mine') {
        params.scope = 'mine';
    }

    return params;
}

/**
 * The form owns its draft state, seeded from the server's filters. The page
 * keys it on those filters: a history restore keeps this page mounted and
 * only swaps its props, so without the key the inputs would still show the
 * query typed after the results sitting under them.
 */
export function SearchBlock({
    team,
    filters,
    indexingEnabled,
    filterCollections,
    onBusyChange,
}: SearchBlockProps) {
    const [draft, setDraft] = useState<SearchDraft>({
        q: filters.q,
        collection: filters.collection,
        since: filters.since,
    });
    const [filtersOpen, setFiltersOpen] = useState(
        filters.collection !== '' || filters.since !== '',
    );
    const inputRef = useRef<HTMLInputElement>(null);

    // Pressing "/" anywhere outside an editable field focuses the search.
    useEffect(() => {
        const focusOnSlash = (event: KeyboardEvent) => {
            if (event.key !== '/' || event.defaultPrevented) {
                return;
            }

            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (target) {
                const tag = target.tagName;

                if (
                    tag === 'INPUT' ||
                    tag === 'TEXTAREA' ||
                    tag === 'SELECT' ||
                    target.isContentEditable
                ) {
                    return;
                }
            }

            event.preventDefault();
            inputRef.current?.focus();
        };

        window.addEventListener('keydown', focusOnSlash);

        return () => window.removeEventListener('keydown', focusOnSlash);
    }, []);

    const visit = (params: Record<string, string>) => {
        onBusyChange(true);
        router.get(dashboard.url({ current_team: team.slug }), params, {
            onFinish: () => onBusyChange(false),
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit(dashboardParams(draft, filters.scope));
    };

    const clearQuery = () => {
        setDraft((previous) => ({ ...previous, q: '' }));

        if (filters.q !== '') {
            visit(dashboardParams({ ...draft, q: '' }, filters.scope));
        }
    };

    return (
        <section aria-labelledby="home-search-heading">
            <p className="eyebrow mb-3 min-w-0 break-words">{team.name}</p>
            <h1 id="home-search-heading" className="min-w-0 break-words">
                What are you looking for?
            </h1>
            <form onSubmit={submit} role="search" className="mt-6">
                <div className="relative">
                    <Search
                        aria-hidden="true"
                        className="absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        ref={inputRef}
                        aria-label="Search query"
                        placeholder="Search everything your team has shared"
                        value={draft.q}
                        disabled={!indexingEnabled}
                        onChange={(event) =>
                            setDraft({ ...draft, q: event.target.value })
                        }
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                clearQuery();
                            }
                        }}
                        className="h-14 bg-background pr-4 pl-12 text-lg focus-visible:border-primary focus-visible:ring-4 focus-visible:ring-primary/10"
                    />
                </div>
                <div className="mt-3">
                    <button
                        type="button"
                        aria-expanded={filtersOpen}
                        aria-controls="home-search-filters"
                        onClick={() => setFiltersOpen((open) => !open)}
                        disabled={!indexingEnabled}
                        className="inline-flex min-h-11 items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50 motion-safe:transition-colors"
                    >
                        Filters
                        <ChevronDown
                            aria-hidden="true"
                            className={`size-4 transition-transform motion-safe:duration-200 ${
                                filtersOpen ? 'rotate-180' : ''
                            }`}
                        />
                    </button>
                    {filtersOpen && (
                        <div
                            id="home-search-filters"
                            className="mt-2 flex min-w-0 flex-wrap items-end gap-3"
                        >
                            {filterCollections.length > 0 && (
                                <div className="flex min-w-0 flex-col gap-1.5">
                                    <label
                                        htmlFor="home-search-collection"
                                        className="text-sm font-medium"
                                    >
                                        Collection
                                    </label>
                                    <select
                                        id="home-search-collection"
                                        value={draft.collection}
                                        disabled={!indexingEnabled}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                collection: event.target.value,
                                            })
                                        }
                                        className="rounded-md border border-border bg-background px-2 py-2 text-sm disabled:opacity-50 max-md:min-h-[44px] max-md:text-base"
                                    >
                                        <option value="">
                                            All collections
                                        </option>
                                        {filterCollections.map((collection) => (
                                            <option
                                                key={collection.name}
                                                value={collection.name}
                                            >
                                                {collection.name}
                                                {collection.canonical
                                                    ? ' (canonical)'
                                                    : ''}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            <div className="flex min-w-0 flex-col gap-1.5">
                                <label
                                    htmlFor="home-search-since"
                                    className="text-sm font-medium"
                                >
                                    Since
                                </label>
                                <Input
                                    id="home-search-since"
                                    type="date"
                                    value={draft.since}
                                    disabled={!indexingEnabled}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            since: event.target.value,
                                        })
                                    }
                                    className="w-40"
                                />
                            </div>
                        </div>
                    )}
                </div>
            </form>
            {!indexingEnabled && (
                <p className="mt-3 min-w-0 text-sm break-words text-muted-foreground">
                    Search is turned off for this workspace right now. Recent
                    artifacts and collections still work.
                </p>
            )}
        </section>
    );
}
