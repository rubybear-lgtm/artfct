import * as React from 'react';

import { cn } from '@/lib/utils';

export function Input({ className, type = 'text', ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            type={type}
            className={cn(
                'h-9 w-full rounded-md border border-border bg-transparent px-3 text-sm placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}
