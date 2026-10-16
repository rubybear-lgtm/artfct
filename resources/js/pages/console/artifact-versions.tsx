import { Form, Head, Link } from '@inertiajs/react';

import ArtifactVersionController from '@/actions/App/Http/Controllers/ArtifactVersionController';
import ConsoleController from '@/actions/App/Http/Controllers/ConsoleController';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import consoleRoutes from '@/routes/console';

interface VersionRow {
    number: number;
    created_at: string | null;
    /** The publisher's display name, or null when they are no longer a member. */
    created_by: string | null;
    agent: string | null;
    title: string | null;
    current: boolean;
    restored_from: number | null;
}

interface Props {
    team: { slug: string; name: string };
    artifactId: string;
    title: string | null;
    currentVersion: number;
    versions: VersionRow[];
}

/** The name a person reads: the newest version's title, or a short id. */
function artifactName(title: string | null, artifactId: string): string {
    return title && title !== '' ? title : artifactId.slice(0, 8);
}

function formatDate(value: string | null): string {
    if (!value) {
        return 'Unknown date';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? 'Unknown date'
        : date.toLocaleDateString();
}

function versionCountLabel(count: number): string {
    return count === 1 ? '1 version' : `${count} versions`;
}

export default function ArtifactVersions({
    team,
    artifactId,
    title,
    currentVersion,
    versions,
}: Props) {
    // The console's own open route, addressed at one version: it authorizes the
    // viewer, resolves the tier, and mints on click, exactly like the list's
    // Open control. The version travels as a query parameter the route reads.
    const openHref = (version: number) =>
        ConsoleController.open.url(
            { team: team.slug, artifactId },
            { query: { version } },
        );

    return (
        <>
            <Head title="Version history" />
            <div>
                <div className="mb-8">
                    <p className="eyebrow mb-1">Version history</p>
                    <h1 className="min-w-0 break-words text-foreground">
                        {artifactName(title, artifactId)}
                    </h1>
                    <p className="mt-2 text-muted-foreground">
                        {versionCountLabel(versions.length)}. Version{' '}
                        {currentVersion} is current.
                    </p>
                    <Link
                        href={consoleRoutes.index.url({ team: team.slug })}
                        className="mt-3 inline-block text-sm font-medium text-primary hover:underline"
                    >
                        Back to artifacts
                    </Link>
                </div>

                {versions.length === 0 ? (
                    <EmptyState title="No versions yet">
                        <p className="text-sm">
                            This artifact has no completed versions.
                        </p>
                    </EmptyState>
                ) : (
                    <ul
                        className="border-b border-border"
                        data-testid="version-list"
                    >
                        {versions.map((version) => (
                            <li
                                key={version.number}
                                className="border-t border-border py-5"
                                data-testid={`version-row-${version.number}`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold text-foreground">
                                                Version {version.number}
                                            </span>
                                            {version.current && (
                                                <span className="rounded bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                                                    Current
                                                </span>
                                            )}
                                        </div>
                                        {version.title && (
                                            <p className="mt-1 text-sm break-words text-foreground">
                                                {version.title}
                                            </p>
                                        )}
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {formatDate(version.created_at)} ·{' '}
                                            {version.created_by ?? 'Unknown'}
                                            {version.agent
                                                ? ` · ${version.agent}`
                                                : ''}
                                        </p>
                                        {version.restored_from !== null && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Restored from version{' '}
                                                {version.restored_from}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <a
                                                href={openHref(version.number)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                data-testid={`version-view-${version.number}`}
                                                aria-label={`View version ${version.number}`}
                                            >
                                                View
                                            </a>
                                        </Button>
                                        {!version.current && (
                                            <Form
                                                action={ArtifactVersionController.restore.url(
                                                    {
                                                        team: team.slug,
                                                        artifactId,
                                                        version: version.number,
                                                    },
                                                )}
                                                method="post"
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        data-testid={`version-restore-${version.number}`}
                                                    >
                                                        Restore
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

ArtifactVersions.layout = (page: React.ReactNode) => (
    <AppLayout>{page}</AppLayout>
);
