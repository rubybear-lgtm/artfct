import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Toaster } from '@/components/ui/sonner';
import { useAppTheme } from '@/lib/useAppTheme';

export default function AuthLayout({ children }: { children: ReactNode }) {
    useAppTheme();

    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-8 bg-background px-5 py-12 text-foreground">
            <Link href="/" className="font-serif text-3xl tracking-tight">
                Artfct
            </Link>
            <div className="w-full max-w-sm">{children}</div>
            <Toaster />
        </div>
    );
}
