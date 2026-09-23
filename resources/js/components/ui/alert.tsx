import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const alertVariants = cva('rounded-md border px-4 py-3 text-sm', {
    variants: {
        variant: {
            default: 'border-border bg-muted',
            warning: 'border-warning/40 bg-warning/10',
            destructive: 'border-destructive/40 bg-destructive/10',
        },
    },
    defaultVariants: { variant: 'default' },
});

export function Alert({
    className,
    variant,
    ...props
}: React.ComponentProps<'div'> & VariantProps<typeof alertVariants>) {
    return <div role="alert" className={cn(alertVariants({ variant }), className)} {...props} />;
}
