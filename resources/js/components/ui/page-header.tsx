import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface PageHeaderProps {
    eyebrow?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}

export function PageHeader({
    eyebrow,
    title,
    description,
    actions,
    className,
}: PageHeaderProps) {
    return (
        <div
            className={cn(
                'mb-8 flex flex-col gap-2 border-b border-border pb-6 sm:flex-row sm:items-end sm:justify-between sm:gap-6',
                className,
            )}
        >
            <div className="min-w-0 flex-1">
                {eyebrow && (
                    <p className="eyebrow mb-2 break-words">{eyebrow}</p>
                )}
                <h1 className="min-w-0 text-foreground break-words">{title}</h1>
                {description && (
                    <p className="mt-2 max-w-[62ch] text-[15px] leading-relaxed text-muted-foreground break-words">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="mt-2 flex shrink-0 flex-wrap items-center gap-2 sm:mt-0 max-md:[&_a]:min-h-[44px] max-md:[&_button]:min-h-[44px]">
                    {actions}
                </div>
            )}
        </div>
    );
}
