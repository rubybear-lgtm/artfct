import { Head, Link, usePage } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { dashboard, home } from '@/routes';
import type { SharedProps } from '@/types/shared';

interface Props {
    status: number;
    retryAfter?: number;
}

interface Copy {
    title: string;
    message: string;
}

const COPY: Record<number, Copy> = {
    403: {
        title: "You don't have access to this page",
        message: 'Ask a team admin if you think you should.',
    },
    404: {
        title: "We couldn't find that page",
        message: 'The link may be old or mistyped.',
    },
    419: {
        title: 'This page expired',
        message: 'Refresh and try again.',
    },
    429: {
        title: 'Too many tries',
        message: 'Wait a minute, then try again.',
    },
    500: {
        title: 'Something went wrong on our side',
        message: 'Try again in a moment.',
    },
    503: {
        title: 'Artfct is briefly unavailable',
        message: 'Try again in a few minutes.',
    },
};

export default function ErrorPage({ status, retryAfter }: Props) {
    const { auth, currentTeam } = usePage<SharedProps>().props;

    const copy = COPY[status] ?? COPY[500];

    const message =
        status === 429 && retryAfter !== undefined
            ? `Wait ${retryAfter} seconds, then try again.`
            : copy.message;

    const hasDashboard = auth.user !== null && currentTeam !== null;

    return (
        <>
            <Head title={copy.title} />
            <h1 className="mb-2 font-serif text-3xl tracking-tight">
                {copy.title}
            </h1>
            <p className="mb-6 text-sm text-muted-foreground">{message}</p>
            <div className="flex flex-col gap-3">
                {status === 419 && (
                    <Button onClick={() => window.location.reload()}>
                        Refresh
                    </Button>
                )}
                <Button
                    asChild
                    variant={status === 419 ? 'outline' : 'default'}
                >
                    {hasDashboard ? (
                        <Link
                            href={dashboard.url({
                                current_team: currentTeam.slug,
                            })}
                        >
                            Go to your dashboard
                        </Link>
                    ) : (
                        <Link href={home.url()}>Go to Artfct</Link>
                    )}
                </Button>
            </div>
        </>
    );
}

ErrorPage.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
