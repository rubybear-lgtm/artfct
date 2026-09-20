import * as DropdownPrimitive from '@radix-ui/react-dropdown-menu';
import * as React from 'react';

import { cn } from '@/lib/utils';

export const DropdownMenu = DropdownPrimitive.Root;
export const DropdownMenuTrigger = DropdownPrimitive.Trigger;

export function DropdownMenuContent({
    className,
    sideOffset = 6,
    ...props
}: React.ComponentProps<typeof DropdownPrimitive.Content>) {
    return (
        <DropdownPrimitive.Portal>
            <DropdownPrimitive.Content
                sideOffset={sideOffset}
                className={cn('z-50 min-w-48 rounded-md border border-border bg-background p-1 shadow-md', className)}
                {...props}
            />
        </DropdownPrimitive.Portal>
    );
}

export function DropdownMenuItem({ className, ...props }: React.ComponentProps<typeof DropdownPrimitive.Item>) {
    return (
        <DropdownPrimitive.Item
            className={cn('flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm outline-none data-[highlighted]:bg-muted', className)}
            {...props}
        />
    );
}

export function DropdownMenuLabel({ className, ...props }: React.ComponentProps<typeof DropdownPrimitive.Label>) {
    return <DropdownPrimitive.Label className={cn('px-2 py-1.5 text-xs text-muted-foreground', className)} {...props} />;
}

export function DropdownMenuSeparator({ className, ...props }: React.ComponentProps<typeof DropdownPrimitive.Separator>) {
    return <DropdownPrimitive.Separator className={cn('my-1 h-px bg-border', className)} {...props} />;
}
