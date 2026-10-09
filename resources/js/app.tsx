import { createInertiaApp, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

interface PageMeta {
    title?: string;
    description?: string;
}

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
    const { meta } = usePage<{ meta?: PageMeta }>().props;

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

        // Server meta titles already end with the brand ("… — artfct").
        return /\s—\s*artfct$/i.test(title) ? title : `${title} - ${appName}`;
    },
    progress: {
        color: '#4B5563',
    },
    layout: () => MetaLayout,
});
