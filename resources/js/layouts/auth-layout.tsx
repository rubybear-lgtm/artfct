import type { ReactNode } from 'react';

import { Toaster } from '@/components/ui/sonner';

export default function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-background px-4 text-foreground">
            <div className="w-full max-w-sm">{children}</div>
            <Toaster />
        </div>
    );
}
