import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { SearchResultRow, SkeletonRows } from '@/components/home/artifact-row';
import type { HomeFilters, HomeSearchResult } from '@/components/home/types';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

interface SearchResultsProps {
    teamSlug: string;
    filters: HomeFilters;
    searched: boolean;
    results: HomeSearchResult[];
    searchError: string | null;
    canOpenArtifacts: boolean;
    busy: boolean;
    onBusyChange: (busy: boolean) => void;
}

/**
 * Results fade in on arrival. The hidden starting state only applies
 * when motion is allowed, so reduced-motion readers never lose content.
 */
function FadingList({ children }: { children: React.ReactNode }) {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const frame = requestAnimationFrame(() =>
            requestAnimationFrame(() => setVisible(true)),
        );

        return () => cancelAnimationFrame(frame);
    }, []);

    return (
        <div
            className={`motion-safe:transition-opacity motion-safe:duration-200 ${
                visible ? 'opacity-100' : 'motion-safe:opacity-0'
            }`}
        >
            {children}
        </div>
    );
}

export function SearchResults({
    teamSlug,
    filters,
    searched,
    results,
    searchError,
    canOpenArtifacts,
    busy,
    onBusyChange,
}: SearchResultsProps) {
    const clearSearch = () => {
        const params: Record<string, string> = {};

        if (filters.scope === 'mine') {
            params.scope = 'mine';
        }

        onBusyChange(true);
        router.get(dashboard.url({ current_team: teamSlug }), params, {
            onFinish: () => onBusyChange(false),
        });
    };

    if (searchError) {
        return (
            <section aria-label="Search results" className="min-w-0">
                <Alert variant="warning">{searchError}</Alert>
            </section>
        );
    }

    if (busy && results.length === 0) {
        return (
            <section aria-label="Search results" className="min-w-0">
                <SkeletonRows />
            </section>
        );
    }

    if (searched && results.length === 0) {
        return (
            <section aria-label="Search results" className="min-w-0">
                <p className="min-w-0 font-semibold break-words">
                    Nothing matches &ldquo;{filters.q}&rdquo;.
                </p>
                <p className="mt-1 min-w-0 text-sm break-words text-muted-foreground">
                    Try different words or clear the filters.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    onClick={clearSearch}
                    className="mt-4"
                >
                    Clear search
                </Button>
            </section>
        );
    }

    return (
        <section aria-label="Search results" className="min-w-0">
            <FadingList>
                <ul className="divide-y divide-border border-y border-border">
                    {results.map((result) => (
                        <SearchResultRow
                            key={result.id}
                            result={result}
                            canOpenArtifacts={canOpenArtifacts}
                        />
                    ))}
                </ul>
            </FadingList>
        </section>
    );
}
