import { useId } from 'react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export function Section({
    title,
    description,
    children,
    className,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    className?: string;
}) {
    const headingId = useId();

    return (
        <section
            className={cn('border-t border-border py-6', className)}
            aria-labelledby={headingId}
        >
            <h2
                id={headingId}
                className="mb-1 font-serif text-xl tracking-tight"
            >
                {title}
            </h2>
            {description && (
                <p className="mb-4 text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            <div className={description ? '' : 'mt-4'}>{children}</div>
        </section>
    );
}

/** A definition list laid out as label/value rows separated by hairlines. */
export function FactList({
    items,
}: {
    items: { label: string; value: ReactNode }[];
}) {
    return (
        <dl className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
            {items.map((item) => (
                <div
                    key={item.label}
                    className="flex min-w-0 items-baseline justify-between gap-4 border-b border-border py-2 text-sm"
                >
                    <dt className="shrink-0 text-muted-foreground">
                        {item.label}
                    </dt>
                    <dd className="min-w-0 text-right break-words">
                        {item.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function MeterBar({
    value,
    max,
    label,
}: {
    value: number;
    max: number;
    label: string;
}) {
    const percent =
        max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;
    const tone =
        percent >= 100
            ? 'bg-destructive'
            : percent >= 80
              ? 'bg-warning'
              : 'bg-primary';

    return (
        <div className="flex items-center gap-3">
            <div
                role="progressbar"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-muted"
            >
                <div
                    className={cn('h-full rounded-full', tone)}
                    style={{ width: `${percent}%` }}
                />
            </div>
            <span className="w-10 shrink-0 text-right text-xs text-muted-foreground tabular-nums">
                {percent}%
            </span>
        </div>
    );
}
