import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import type { HomeCollection } from '@/components/home/types';
import { Badge } from '@/components/ui/badge';
import teamRoutes from '@/routes/teams';

function artifactCountLabel(count: number): string {
    return `${count} artifact${count === 1 ? '' : 's'}`;
}

export function CollectionList({
    teamSlug,
    collections,
}: {
    teamSlug: string;
    collections: HomeCollection[];
}) {
    const collectionsUrl = teamRoutes.collections.index.url({
        team: teamSlug,
    });

    return (
        <section aria-labelledby="home-collections-heading" className="min-w-0">
            <h2 id="home-collections-heading" className="eyebrow mb-3">
                Collections
            </h2>
            {collections.length === 0 ? (
                <>
                    <p className="min-w-0 text-sm break-words text-muted-foreground">
                        No collections yet.
                    </p>
                    <Link
                        href={collectionsUrl}
                        className="group mt-3 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary"
                    >
                        Create a collection
                        <ArrowRight className="size-4 transition-transform group-hover:translate-x-1" />
                    </Link>
                </>
            ) : (
                <>
                    <ul className="divide-y divide-border border-y border-border">
                        {collections.map((collection) => (
                            <li key={collection.id} className="min-w-0">
                                <Link
                                    href={collectionsUrl}
                                    className="group flex min-w-0 items-center gap-2 py-4"
                                >
                                    <span className="min-w-0 flex-1 font-semibold break-words">
                                        {collection.name}
                                    </span>
                                    {collection.canonical && (
                                        <Badge className="shrink-0">
                                            Pinned
                                        </Badge>
                                    )}
                                    <span className="shrink-0 text-sm whitespace-nowrap text-muted-foreground">
                                        {artifactCountLabel(
                                            collection.artifactCount,
                                        )}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <Link
                        href={collectionsUrl}
                        className="group mt-3 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary"
                    >
                        All collections
                        <ArrowRight className="size-4 transition-transform group-hover:translate-x-1" />
                    </Link>
                </>
            )}
        </section>
    );
}

/**
 * Flag-gated shelf: the same collections as tiles (name + artifact count +
 * canonical badge) above the recent list. Every tile links to the existing
 * collections page, so there are no dead controls.
 */
export function CollectionShelf({
    teamSlug,
    collections,
}: {
    teamSlug: string;
    collections: HomeCollection[];
}) {
    const collectionsUrl = teamRoutes.collections.index.url({
        team: teamSlug,
    });

    return (
        <section aria-labelledby="home-collections-shelf-heading">
            <h2 id="home-collections-shelf-heading" className="eyebrow mb-3">
                Collections
            </h2>
            {collections.length === 0 ? (
                <p className="min-w-0 text-sm break-words text-muted-foreground">
                    No collections yet.{' '}
                    <Link
                        href={collectionsUrl}
                        className="font-medium text-primary"
                    >
                        Create a collection
                    </Link>
                </p>
            ) : (
                <ul className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {collections.map((collection) => (
                        <li key={collection.id} className="min-w-0">
                            <Link
                                href={collectionsUrl}
                                className="flex min-h-11 min-w-0 flex-col gap-1 rounded-md border border-border px-3 py-2.5 transition-colors hover:bg-muted"
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <span className="min-w-0 flex-1 truncate font-semibold">
                                        {collection.name}
                                    </span>
                                    {collection.canonical && (
                                        <Badge className="shrink-0">
                                            Pinned
                                        </Badge>
                                    )}
                                </span>
                                <span className="text-sm whitespace-nowrap text-muted-foreground">
                                    {artifactCountLabel(
                                        collection.artifactCount,
                                    )}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
