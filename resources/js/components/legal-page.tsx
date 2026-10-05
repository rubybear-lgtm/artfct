import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { SitePage } from '@/components/site-chrome';
import { Alert } from '@/components/ui/alert';

export function LegalPage({
    title,
    version,
    children,
}: {
    title: string;
    version: string;
    children: ReactNode;
}) {
    return (
        <SitePage>
            <Head title={title} />
            <main className="mx-auto max-w-2xl px-5 py-12">
                <h1 className="mb-2 text-2xl font-semibold">{title}</h1>
                <Alert variant="warning">
                    Draft text (version {version}). It describes how the service
                    works today and has not had legal review. It is not legal
                    advice.
                </Alert>
                <div className="mt-6 flex flex-col gap-4 text-sm leading-6">
                    {children}
                </div>
            </main>
        </SitePage>
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
