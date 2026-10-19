import type { ReactNode } from 'react';

interface EmptyStateProps {
    title: string;
    description?: string;
    children?: ReactNode;
}

export function EmptyState({
    title,
    description,
    children,
}: EmptyStateProps) {
    return (
        <div className="rounded-lg border border-dashed p-6 text-center">
            <p className="font-medium">{title}</p>
            {description && (
                <p className="mt-1 text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {children && <div className="mt-4">{children}</div>}
        </div>
    );
}
