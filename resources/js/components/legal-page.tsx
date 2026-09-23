import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Alert } from '@/components/ui/alert';
import { useAppTheme } from '@/lib/useAppTheme';
import { home, privacy, terms } from '@/routes';

export function LegalPage({
    title,
    version,
    children,
}: {
    title: string;
    version: string;
    children: ReactNode;
}) {
    useAppTheme();

    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title={title} />
            <main className="mx-auto max-w-2xl px-5 py-12">
                <Link
                    href={home.url()}
                    className="font-serif text-2xl tracking-tight"
                >
                    Artfct
                </Link>
                <h1 className="mt-6 mb-2 text-2xl font-semibold">{title}</h1>
                <Alert variant="warning">
                    Draft text (version {version}). It describes how the service
                    works today and has not had legal review. It is not legal
                    advice.
                </Alert>
                <div className="mt-6 flex flex-col gap-4 text-sm leading-6">
                    {children}
                </div>
                <p className="mt-8 flex gap-4 text-sm">
                    <Link className="underline" href={terms.url()}>
                        Terms
                    </Link>
                    <Link className="underline" href={privacy.url()}>
                        Privacy
                    </Link>
                </p>
            </main>
        </div>
    );
}

export function Section({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section>
            <h2 className="mb-1 text-base font-semibold">{title}</h2>
            <div className="flex flex-col gap-2">{children}</div>
        </section>
    );
}
