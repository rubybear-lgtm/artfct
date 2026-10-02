import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const badgeVariants = cva('inline-flex items-center rounded-[5px] px-2 py-0.5 text-xs font-medium', {
    variants: {
        variant: {
            default: 'bg-muted text-foreground',
            success: 'bg-success/15 text-success',
            warning: 'bg-warning/15 text-warning',
            destructive: 'bg-destructive/15 text-destructive',
            outline: 'border border-border',
        },
    },
    defaultVariants: { variant: 'default' },
});

export function Badge({
    className,
    variant,
    ...props
}: React.ComponentProps<'span'> & VariantProps<typeof badgeVariants>) {
    return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}
