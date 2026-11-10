import { createInertiaApp, Head, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';

import { initAnalytics } from '@/lib/analytics';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

interface PageMeta {
    title?: string;
    description?: string;
}

interface AnalyticsShared {
    enabled: boolean;
    apiKey: string | null;
    host: string | null;
    environment: string | null;
}

type AnalyticsPageProps = {
    meta?: PageMeta;
    analytics?: AnalyticsShared;
    auth?: { user?: { id: number | string } | null };
    currentTeam?: {
        id?: number;
        slug: string;
        name?: string;
        plan?: string;
        isPersonal?: boolean;
    } | null;
    [key: string]: unknown;
};

/**
 * Renders the server-provided meta as the default head, so server-rendered
 * pages carry the same title and description as the Blade fallback. Pages
 * that set their own `<Head>` override these by `head-key`.
 */
function DefaultMeta({ meta }: { meta?: PageMeta }) {
    if (!meta?.title) {
        return null;
    }

    return (
        <Head>
            <title>{meta.title}</title>
            <meta
                head-key="description"
                name="description"
                content={meta.description}
            />
            <meta
                head-key="og:title"
                property="og:title"
                content={meta.title}
            />
            <meta
                head-key="og:description"
                property="og:description"
                content={meta.description}
            />
            <meta
                head-key="twitter:title"
                name="twitter:title"
                content={meta.title}
            />
            <meta
                head-key="twitter:description"
                name="twitter:description"
                content={meta.description}
            />
        </Head>
    );
}

function MetaLayout({ children }: { children: ReactNode }) {
    const { meta, analytics, auth, currentTeam } =
        usePage<AnalyticsPageProps>().props;

    useEffect(() => {
        initAnalytics(analytics, auth?.user, currentTeam ?? null);
    }, [analytics, auth?.user, currentTeam]);

    return (
        <>
            <DefaultMeta meta={meta} />
            {children}
        </>
    );
}

createInertiaApp({
    title: (title) => {
        if (!title) {
            return appName;
        }

        // Add the brand once, and only to a title that does not name it yet.
        return /artfct/i.test(title) ? title : `${title} — Artfct`;
    },
    progress: {
        color: '#4B5563',
    },
    layout: () => MetaLayout,
});
