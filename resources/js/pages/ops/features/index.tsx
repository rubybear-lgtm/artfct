import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import OpsLayout from '@/layouts/ops-layout';
import { show } from '@/routes/ops/features';

type FeatureSummary = {
    key: string;
    name: string;
    description: string;
    state: string;
};

export default function Features({
    environment,
    releases,
}: {
    environment: string;
    releases: FeatureSummary[];
}) {
    return (
        <>
            <Head title="Feature releases" />
            <div className="mb-8">
                <h1 className="mb-2">Feature releases</h1>
                <p className="text-sm text-muted-foreground">
                    Control availability in{' '}
                    <strong className="text-foreground">{environment}</strong>.
                    Changes apply only to this environment.
                </p>
            </div>
            {releases.length === 0 ? (
                <EmptyState
                    title="No features registered yet"
                    description="New features appear here when they are added to the application. Every new feature starts off."
                />
            ) : (
                <ul className="divide-y divide-border border-y border-border">
                    {releases.map((feature) => (
                        <li key={feature.key}>
                            <Link
                                href={show(feature.key)}
                                className="flex min-h-20 items-center justify-between gap-4 py-5 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                            >
                                <div className="min-w-0">
                                    <h2 className="font-serif text-xl break-words">
                                        {feature.name}
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {feature.description}
                                    </p>
                                </div>
                                <Badge
                                    variant={
                                        feature.state === 'Released'
                                            ? 'success'
                                            : 'default'
                                    }
                                >
                                    {feature.state}
                                </Badge>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

Features.layout = (page: React.ReactNode) => <OpsLayout>{page}</OpsLayout>;
