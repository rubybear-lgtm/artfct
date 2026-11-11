import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { useAppTheme } from '@/lib/useAppTheme';
import { health } from '@/routes/ops';
import { index as featuresIndex } from '@/routes/ops/features';
import { index as tenantsIndex } from '@/routes/ops/tenants';

/**
 * Standalone shell for artfct staff. It never reads `currentTeam`: the
 * operator console sits outside the team URL space.
 */
export default function OpsLayout({ children }: { children: ReactNode }) {
    useAppTheme();

    const { url } = usePage();
    const path = url.split('?')[0];
    const tenantsUrl = tenantsIndex.url();
    const healthUrl = health.url();

    const items = [
        {
            key: 'tenants',
            label: 'Tenants',
            href: tenantsUrl,
            active: path === tenantsUrl || path.startsWith(`${tenantsUrl}/`),
        },
        {
            key: 'health',
            label: 'Health',
            href: healthUrl,
            active: path === healthUrl,
        },
        {
            key: 'features',
            label: 'Features',
            href: featuresIndex.url(),
            active:
                path === featuresIndex.url() ||
                path.startsWith(`${featuresIndex.url()}/`),
        },
    ];

    items[0].active =
        items[0].active && !items.slice(1).some((item) => item.active);

    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="border-b border-border bg-background">
                <div className="mx-auto flex h-16 max-w-5xl min-w-0 items-center gap-3 px-4 md:px-5">
                    <Link
                        href={tenantsUrl}
                        className="inline-flex shrink-0 items-center font-serif text-2xl tracking-tight max-md:min-h-11"
                    >
                        Artfct
                    </Link>
                    <span className="eyebrow border-l border-border pl-3">
                        Operator console
                    </span>
                </div>
                <nav
                    aria-label="Operator console"
                    className="mx-auto max-w-5xl overflow-x-auto px-4 md:px-5"
                >
                    <ul className="flex w-max items-center gap-x-6 text-sm font-medium whitespace-nowrap">
                        {items.map((item) => (
                            <li key={item.key}>
                                <Link
                                    href={item.href}
                                    aria-current={
                                        item.active ? 'page' : undefined
                                    }
                                    className={`inline-flex border-b-2 py-3 transition-colors hover:text-foreground max-md:min-h-11 max-md:items-center ${
                                        item.active
                                            ? 'border-primary text-foreground'
                                            : 'border-transparent text-muted-foreground'
                                    }`}
                                >
                                    {item.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            </header>

            <main className="mx-auto max-w-5xl min-w-0 px-4 py-8 md:px-5 md:py-12">
                {children}
            </main>
        </div>
    );
}
