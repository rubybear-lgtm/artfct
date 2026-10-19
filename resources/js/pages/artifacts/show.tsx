import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ExternalLink,
    PanelRightClose,
    PanelRightOpen,
} from 'lucide-react';
import { useState } from 'react';

import ArtifactComments from '@/components/artifact-comments';
import type { ArtifactComment } from '@/components/artifact-comments';
import ArtifactShareControl from '@/components/artifact-share-control';
import type {
    ArtifactEditAccess,
    ArtifactSharing,
} from '@/components/artifact-share-control';
import { Button } from '@/components/ui/button';
import ViewerLayout from '@/layouts/viewer-layout';
import { home, login } from '@/routes';
import artifacts from '@/routes/artifacts';
import consoleRoutes from '@/routes/console';

interface VersionRow {
    number: number;
    created_at: string | null;
    current: boolean;
}

interface Props {
    /** True when the page is the Worker's public read, not a signed-in member's own artifact. */
    publicView: boolean;
    /** True when a signed-out visitor may ask to sign in and edit a public artifact. */
    canSignInToEdit: boolean;
    team: { slug: string; name: string; publicSharingAllowed: boolean };
    artifact: {
        id: string;
        title: string | null;
        description: string | null;
        sharing: ArtifactSharing | null;
        editAccess: ArtifactEditAccess | null;
        canEdit: boolean;
        canChangeSharing: boolean;
        ownerName: string | null;
        updatedAt: string | null;
        version: number;
        versionCount: number;
    };
    selectedVersion: number | null;
    versions?: VersionRow[];
    frameUrl: string;
    viewerUrl: string;
    openUrl: string;
    /** Null when no download is offered — the public viewer. */
    downloadUrl: string | null;
    /** A signed-in member's discussion; absent in the public viewer. */
    comments?: ArtifactComment[];
}

/** The name a person reads: the title, or a short id when it has none. */
function artifactName(title: string | null, id: string): string {
    return title && title !== '' ? title : id.slice(0, 8);
}

function formatDate(value: string | null): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? null
        : date.toLocaleDateString(undefined, { dateStyle: 'medium' });
}

function versionLabel(version: number, count: number): string {
    return count > 1 ? `Version ${version} of ${count}` : `Version ${version}`;
}

export default function ArtifactShow({
    publicView,
    canSignInToEdit,
    team,
    artifact,
    selectedVersion,
    versions,
    frameUrl,
    viewerUrl,
    openUrl,
    downloadUrl,
    comments,
}: Props) {
    const name = artifactName(artifact.title, artifact.id);
    const tooltip = artifact.description
        ? `${name} — ${artifact.description}`
        : name;
    const shownVersion = selectedVersion ?? artifact.version;
    const updated = formatDate(artifact.updatedAt);
    const meta = [
        versionLabel(shownVersion, artifact.versionCount),
        artifact.ownerName,
        updated ? `Updated ${updated}` : null,
    ].filter((part): part is string => part !== null);

    const selectVersion = (version: number) => {
        router.get(
            artifacts.version.url({
                artifactId: artifact.id,
                version,
            }),
            {},
            { preserveScroll: true },
        );
    };

    const [panelOpen, setPanelOpen] = useState(false);
    const commentCount = comments?.length ?? 0;
    const backHref = publicView
        ? home.url()
        : consoleRoutes.index.url({ team: team.slug });

    return (
        <>
            <Head title={name} />

            {/*
                The layout is a full-viewport column: a thin header, then the
                artifact fills everything left. The details panel slides in
                beside the frame (over it below lg) instead of sitting under it.
            */}
            <header className="shrink-0 border-b border-border bg-background">
                <div className="flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 px-3 py-1.5 md:flex-nowrap">
                    <Link
                        href={backHref}
                        aria-label={
                            publicView ? 'Artfct home' : 'Back to artifacts'
                        }
                        className="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground max-md:size-11"
                    >
                        <ArrowLeft className="size-4" />
                    </Link>

                    <div className="flex min-w-0 flex-1 items-baseline gap-3">
                        <h1
                            className="truncate text-base! leading-6 font-medium"
                            title={tooltip}
                        >
                            {name}
                        </h1>
                        <p className="hidden min-w-0 items-center gap-x-2 truncate text-xs text-muted-foreground md:flex">
                            {meta.map((part, index) => (
                                <span
                                    key={part}
                                    className="flex items-center gap-x-2"
                                >
                                    {index > 0 && (
                                        <span aria-hidden="true">·</span>
                                    )}
                                    {part}
                                </span>
                            ))}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 max-md:order-last max-md:w-full">
                        {versions && versions.length > 1 && (
                            <label className="flex items-center gap-2 text-sm">
                                <span className="sr-only">Version</span>
                                <select
                                    value={shownVersion}
                                    onChange={(event) =>
                                        selectVersion(
                                            Number(event.target.value),
                                        )
                                    }
                                    data-testid="version-picker"
                                    className="h-8 rounded-md border border-border bg-transparent px-2 text-sm max-md:min-h-[44px] max-md:text-base"
                                >
                                    {versions.map((version) => (
                                        <option
                                            key={version.number}
                                            value={version.number}
                                        >
                                            Version {version.number}
                                            {version.current
                                                ? ' (current)'
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}

                        {!publicView && (
                            <ArtifactShareControl
                                artifactId={artifact.id}
                                sharing={artifact.sharing}
                                editAccess={artifact.editAccess}
                                publicSharingAllowed={team.publicSharingAllowed}
                                canChangeSharing={artifact.canChangeSharing}
                                viewerUrl={viewerUrl}
                            />
                        )}

                        <Button asChild variant="outline" size="sm">
                            <a
                                href={openUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                data-testid="open-artifact"
                            >
                                <ExternalLink className="size-3.5" /> Open
                            </a>
                        </Button>

                        {downloadUrl && (
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={downloadUrl}
                                    data-testid="download-artifact"
                                >
                                    Download
                                </a>
                            </Button>
                        )}

                        {canSignInToEdit && (
                            <a
                                href={login.url()}
                                className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                data-testid="sign-in-to-edit"
                            >
                                Sign in to edit
                            </a>
                        )}
                    </div>

                    <button
                        type="button"
                        onClick={() => setPanelOpen((open) => !open)}
                        aria-expanded={panelOpen}
                        aria-controls="artifact-panel"
                        aria-label={
                            panelOpen ? 'Close details' : 'Open details'
                        }
                        data-testid="artifact-panel-toggle"
                        className="relative inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground max-md:size-11"
                    >
                        {panelOpen ? (
                            <PanelRightClose className="size-4" />
                        ) : (
                            <PanelRightOpen className="size-4" />
                        )}
                        {commentCount > 0 && !panelOpen && (
                            <span className="absolute top-0.5 right-0.5 min-w-4 rounded-full bg-[var(--accent)] px-1 text-center text-[10px] leading-4 text-white">
                                {commentCount}
                            </span>
                        )}
                    </button>
                </div>
            </header>

            <div className="relative flex min-h-0 flex-1">
                <iframe
                    src={frameUrl}
                    title={name}
                    sandbox="allow-scripts allow-same-origin allow-popups allow-forms"
                    referrerPolicy="no-referrer"
                    data-testid="artifact-frame"
                    className="h-full min-w-0 flex-1 border-0 bg-paper"
                />

                {panelOpen && (
                    <aside
                        id="artifact-panel"
                        aria-label="Artifact details"
                        data-testid="artifact-panel"
                        className="absolute inset-y-0 right-0 z-10 flex w-full max-w-[380px] flex-col overflow-y-auto border-l border-border bg-background p-5 shadow-xl lg:static lg:z-auto lg:w-[380px] lg:shrink-0 lg:shadow-none"
                    >
                        <h2 className="text-lg font-normal">{name}</h2>
                        {artifact.description && (
                            <p className="mt-2 text-sm text-muted-foreground">
                                {artifact.description}
                            </p>
                        )}
                        <p className="mt-3 text-xs text-muted-foreground">
                            {meta.join(' · ')}
                        </p>

                        {!publicView && comments !== undefined && (
                            <ArtifactComments
                                artifactId={artifact.id}
                                comments={comments}
                                className="max-w-none"
                            />
                        )}
                    </aside>
                )}
            </div>
        </>
    );
}

ArtifactShow.layout = (page: React.ReactNode) => (
    <ViewerLayout>{page}</ViewerLayout>
);
