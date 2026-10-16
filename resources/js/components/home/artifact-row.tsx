import { FileText } from 'lucide-react';

import { AiToolIcon } from '@/components/ai-tool-icon';
import type {
    HomeRecentArtifact,
    HomeSearchResult,
} from '@/components/home/types';
import { Badge } from '@/components/ui/badge';
import { aiToolName } from '@/lib/ai-tools';

/** Short date as the reader expects it, e.g. "Oct 4". */
export function shortDate(iso: string): string {
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
    }).format(new Date(iso));
}

/** The 30px icon well every artifact row starts with. */
export function RowIcon() {
    return (
        <span
            aria-hidden="true"
            className="flex size-[30px] shrink-0 items-center justify-center rounded-md bg-muted"
        >
            <FileText className="size-4 text-muted-foreground" />
        </span>
    );
}

/** Three pulsing placeholder rows while a search visit is in flight. */
export function SkeletonRows() {
    return (
        <ul
            aria-hidden="true"
            className="divide-y divide-border border-y border-border"
        >
            {[0, 1, 2].map((index) => (
                <li key={index} className="flex min-w-0 items-start gap-3 py-4">
                    <span className="size-[30px] shrink-0 rounded-md bg-muted motion-safe:animate-pulse" />
                    <span className="flex min-w-0 flex-1 flex-col gap-2 py-1">
                        <span className="block h-4 w-2/3 rounded bg-muted motion-safe:animate-pulse" />
                        <span className="block h-3 w-1/3 rounded bg-muted motion-safe:animate-pulse" />
                    </span>
                </li>
            ))}
        </ul>
    );
}

function rowTitle(item: { title: string; id: string }): string {
    return item.title || item.id.slice(0, 8);
}

export function RecentArtifactRow({
    item,
    canOpenArtifacts,
}: {
    item: HomeRecentArtifact;
    canOpenArtifacts: boolean;
}) {
    const clickable = canOpenArtifacts && !item.revoked;
    const meta = [
        ...(item.authorName ? [item.authorName] : []),
        shortDate(item.createdAt),
        ...(item.agent ? [aiToolName(item.agent)] : []),
    ].join(' · ');

    return (
        <li className="flex min-w-0 items-start gap-3 py-4">
            <RowIcon />
            <div className="flex min-w-0 flex-1 flex-col">
                {clickable ? (
                    <a
                        href={item.openUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="line-clamp-2 min-w-0 font-semibold break-words hover:underline"
                    >
                        {rowTitle(item)}
                    </a>
                ) : (
                    <span
                        className={`line-clamp-2 min-w-0 font-semibold break-words ${
                            item.revoked ? 'text-muted-foreground' : ''
                        }`}
                    >
                        {rowTitle(item)}
                    </span>
                )}
                <span className="mt-0.5 min-w-0 text-sm break-words text-muted-foreground">
                    {meta}
                </span>
                {item.description && (
                    <span className="mt-0.5 line-clamp-2 min-w-0 text-sm break-words text-muted-foreground">
                        {item.description}
                    </span>
                )}
                {item.revoked && (
                    <span className="mt-1.5">
                        <Badge variant="outline">No longer shared</Badge>
                    </span>
                )}
            </div>
        </li>
    );
}

export function SearchResultRow({
    result,
    canOpenArtifacts,
}: {
    result: HomeSearchResult;
    canOpenArtifacts: boolean;
}) {
    return (
        <li className="flex min-w-0 items-start gap-3 py-4">
            <RowIcon />
            <div className="flex min-w-0 flex-1 flex-col">
                {canOpenArtifacts ? (
                    <a
                        className="line-clamp-2 min-w-0 font-semibold break-words hover:underline max-md:block max-md:min-h-11 max-md:py-2"
                        data-testid="search-result-link"
                        href={result.openUrl}
                        target="_blank"
                        rel="noreferrer"
                    >
                        {rowTitle(result)}
                    </a>
                ) : (
                    <span className="line-clamp-2 min-w-0 font-semibold break-words">
                        {rowTitle(result)}
                    </span>
                )}
                {result.agent && (
                    <span className="mt-0.5 flex min-w-0 items-center gap-1.5 text-sm break-words text-muted-foreground">
                        <AiToolIcon tool={result.agent} size={14} />
                        {aiToolName(result.agent)}
                    </span>
                )}
                {result.description && (
                    <span className="mt-0.5 line-clamp-2 min-w-0 text-sm break-words text-muted-foreground">
                        {result.description}
                    </span>
                )}
                <span className="mt-0.5 min-w-0 text-sm break-words">
                    {result.snippet}
                </span>
                {result.canonical && (
                    <span className="mt-1.5">
                        <Badge>canonical</Badge>
                    </span>
                )}
            </div>
        </li>
    );
}
