import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';

import { Toaster } from '@/components/ui/sonner';
import { useAppTheme } from '@/lib/useAppTheme';

/**
 * A full-viewport shell for viewing one artifact: no app nav, no footer, no
 * width cap. The page supplies its own thin header; the artifact fills the rest.
 */
export default function ViewerLayout({ children }: { children: ReactNode }) {
    const { flash } = usePage();
    const toastMessage = flash?.toast;

    useEffect(() => {
        if (toastMessage) {
            (toastMessage.type === 'error' ? toast.error : toast.success)(
                toastMessage.message,
            );
        }
    }, [toastMessage]);

    useAppTheme();

    return (
        <div className="flex h-dvh min-w-0 flex-col overflow-hidden bg-background text-foreground">
            {children}
            <Toaster />
        </div>
    );
}
