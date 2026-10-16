import * as SeparatorPrimitive from '@radix-ui/react-separator';
import * as React from 'react';

import { cn } from '@/lib/utils';

export function Separator({ className, ...props }: React.ComponentProps<typeof SeparatorPrimitive.Root>) {
    return <SeparatorPrimitive.Root className={cn('shrink-0 bg-border data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full', className)} {...props} />;
}
