import { Head, router } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';

import ArtifactViewerController from '@/actions/App/Http/Controllers/ArtifactViewerController';
import ArtifactShareControl from '@/components/artifact-share-control';
import type {
    ArtifactEditAccess,
    ArtifactSharing,
} from '@/components/artifact-share-control';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';

interface VersionRow {
    number: number;
    created_at: string | null;
    current: boolean;
}

interface Props {
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
    downloadUrl: string;
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

    return Number.isNaN(date.getTime()) ? null : date.toLocaleDateString();
}

function versionLabel(version: number, count: number): string {
    return count > 1 ? `Version ${version} of ${count}` : `Version ${version}`;
}

export default function ArtifactShow({
    team,
    artifact,
    selectedVersion,
    versions,
    frameUrl,
    viewerUrl,
    openUrl,
    downloadUrl,
}: Props) {
    const name = artifactName(artifact.title, artifact.id);
    const shownVersion = selectedVersion ?? artifact.version;
    const updated = formatDate(artifact.updatedAt);
    const meta = [
        versionLabel(shownVersion, artifact.versionCount),
        artifact.ownerName,
        updated ? `Updated ${updated}` : null,
    ].filter((part): part is string => part !== null);

    const selectVersion = (version: number) => {
        router.get(
            ArtifactViewerController.show.url(
                { artifactId: artifact.id },
                { query: { version } },
            ),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={name} />

            <div className="flex min-h-[60vh] flex-col">
                <header className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-b border-border pb-3">
                    <div className="min-w-0">
                        <h1
                            className="truncate text-xl font-normal md:text-2xl"
                            title={name}
                        >
                            {name}
                        </h1>
                        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
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
                        {artifact.description && (
                            <p className="mt-1 max-w-2xl text-sm break-words text-muted-foreground">
                                {artifact.description}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
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

                        <ArtifactShareControl
                            artifactId={artifact.id}
                            sharing={artifact.sharing}
                            editAccess={artifact.editAccess}
                            publicSharingAllowed={team.publicSharingAllowed}
                            canChangeSharing={artifact.canChangeSharing}
                            viewerUrl={viewerUrl}
                        />

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

                        <Button asChild variant="outline" size="sm">
                            <a
                                href={downloadUrl}
                                data-testid="download-artifact"
                            >
                                Download
                            </a>
                        </Button>
                    </div>
                </header>

                <div className="mt-4 min-h-0 flex-1">
                    <iframe
                        src={frameUrl}
                        title={name}
                        sandbox="allow-scripts allow-same-origin allow-popups allow-forms"
                        referrerPolicy="no-referrer"
                        data-testid="artifact-frame"
                        className="h-[calc(100dvh-16rem)] min-h-[420px] w-full rounded-lg border border-border bg-paper"
                    />
                </div>
            </div>
        </>
    );
}

ArtifactShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
