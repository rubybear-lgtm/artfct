import * as React from 'react';

import { cn } from '@/lib/utils';

export function Table({ className, ...props }: React.ComponentProps<'table'>) {
    return (
        <div className="w-full overflow-x-auto">
            <table className={cn('w-full text-left text-sm', className)} {...props} />
        </div>
    );
}

export function TableHead({ className, ...props }: React.ComponentProps<'th'>) {
    return <th className={cn('px-3 py-2 text-xs font-medium text-muted-foreground', className)} {...props} />;
}

export function TableRow({ className, ...props }: React.ComponentProps<'tr'>) {
    return <tr className={cn('border-t border-border', className)} {...props} />;
}

export function TableCell({ className, ...props }: React.ComponentProps<'td'>) {
    return <td className={cn('px-3 py-2 align-middle', className)} {...props} />;
}
