import { Head, router, useForm } from '@inertiajs/react';
import { Fragment, useEffect, useState } from 'react';
import type { FormEvent } from 'react';

import CollectionController from '@/actions/App/Http/Controllers/Teams/CollectionController';
import { ArtifactPreview } from '@/components/artifact-preview';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import teamRoutes from '@/routes/teams';

interface CollectionRow {
    id: number;
    name: string;
    description: string | null;
    canonical: boolean;
    artifactIds: string[];
    /** Artifact id => the app's open route for it. */
    openUrls: Record<string, string>;
    /** Artifact id => the team's session-auth preview route for it. */
    previewUrls: Record<string, string>;
}

/** One artifact the picker can offer, named the way a person reads it. */
interface ArtifactOption {
    id: string;
    title: string;
}

interface Props {
    team: { slug: string; name: string };
    canEdit: boolean;
    canPin: boolean;
    canOpenArtifacts: boolean;
    collections: CollectionRow[];
    artifactOptions: ArtifactOption[];
}

/** The name shown to a curator: the artifact's title, or a short id. */
function artifactName(options: ArtifactOption[], id: string): string {
    return options.find((option) => option.id === id)?.title ?? id.slice(0, 8);
}

/** The preview URL for one artifact, tolerating a backend that predates it. */
function previewUrlFor(
    collection: CollectionRow,
    artifactId: string,
): string | undefined {
    return collection.previewUrls?.[artifactId];
}

/** The first artifact with an actual preview, for the front of the stack. */
function frontPreview(
    collection: CollectionRow,
): { id: string; url: string } | null {
    for (const id of collection.artifactIds) {
        const url = previewUrlFor(collection, id);

        if (url) {
            return { id, url };
        }
    }

    return null;
}

function artifactCountLabel(count: number): string {
    if (count === 0) {
        return 'No artifacts yet';
    }

    if (count === 1) {
        return '1 artifact';
    }

    return `${count} artifacts`;
}

/** Responsive columns matching the grid below: 1 phone, 2 tablet, 3 desktop. */
function useColumnCount(): number {
    const [columns, setColumns] = useState(() => {
        if (typeof window === 'undefined') {
            return 1;
        }

        if (window.innerWidth >= 1024) {
            return 3;
        }

        if (window.innerWidth >= 768) {
            return 2;
        }

        return 1;
    });

    useEffect(() => {
        const update = () => {
            if (window.innerWidth >= 1024) {
                setColumns(3);
            } else if (window.innerWidth >= 768) {
                setColumns(2);
            } else {
                setColumns(1);
            }
        };

        update();

        window.addEventListener('resize', update);

        return () => window.removeEventListener('resize', update);
    }, []);

    return columns;
}

function chunk<T>(items: T[], size: number): T[][] {
    if (size <= 1) {
        return items.map((item) => [item]);
    }

    const rows: T[][] = [];

    for (let i = 0; i < items.length; i += size) {
        rows.push(items.slice(i, i + size));
    }

    return rows;
}

function StackChevron({ open }: { open: boolean }) {
    return (
        <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            aria-hidden="true"
            className={open ? 'rotate-180' : undefined}
        >
            <path d="m6 9 6 6 6-6" />
        </svg>
    );
}

function CollectionCard({
    team,
    collection,
    expanded,
    onToggle,
    canEdit,
    canPin,
    artifactOptions,
}: {
    team: string;
    collection: CollectionRow;
    expanded: boolean;
    onToggle: () => void;
    canEdit: boolean;
    canPin: boolean;
    artifactOptions: ArtifactOption[];
}) {
    const [artifactId, setArtifactId] = useState('');
    const [renaming, setRenaming] = useState(false);
    const renameForm = useForm({ name: collection.name });
    const panelId = `collection-panel-${collection.id}`;
    const front = frontPreview(collection);
    const sheets = Math.min(collection.artifactIds.length, 3);

    const add = (event: FormEvent) => {
        event.preventDefault();

        if (artifactId === '') {
            return;
        }

        router.post(
            CollectionController.addArtifact.url({
                team,
                collection: collection.id,
            }),
            { artifact_id: artifactId },
            { preserveScroll: true, onSuccess: () => setArtifactId('') },
        );
    };

    const availableArtifacts = artifactOptions.filter(
        (option) => !collection.artifactIds.includes(option.id),
    );

    return (
        <>
            <Card
                data-testid={`collection-card-${collection.id}`}
                className="flex min-w-0 flex-col"
            >
                <CardHeader>
                    <CardTitle className="flex min-w-0 items-center gap-2">
                        <span className="min-w-0 flex-1 break-words">
                            {collection.name}
                        </span>
                        {collection.canonical && (
                            <Badge variant="success">canonical</Badge>
                        )}
                        {canEdit && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="ml-auto min-h-11 shrink-0"
                                onClick={() => {
                                    renameForm.setData('name', collection.name);
                                    renameForm.clearErrors();
                                    setRenaming(true);
                                }}
                            >
                                Rename
                            </Button>
                        )}
                    </CardTitle>
                    {collection.description && (
                        <CardDescription className="break-words">
                            {collection.description}
                        </CardDescription>
                    )}
                </CardHeader>
                <CardContent className="flex flex-1 flex-col gap-3">
                    <button
                        type="button"
                        aria-expanded={expanded}
                        aria-controls={panelId}
                        aria-label={`${expanded ? 'Hide' : 'Show'} artifacts in ${collection.name}, ${artifactCountLabel(collection.artifactIds.length)}`}
                        data-testid={`collection-toggle-${collection.id}`}
                        onClick={onToggle}
                        className="min-h-11 w-full rounded-lg border border-border bg-muted/40 p-3 text-left transition-colors hover:bg-muted motion-reduce:transition-none"
                    >
                        <span
                            aria-hidden="true"
                            data-testid={`collection-stack-${collection.id}`}
                            className="relative block h-36"
                        >
                            {collection.artifactIds.length === 0 ? (
                                <>
                                    <span className="absolute inset-x-6 top-0 bottom-3 rounded-lg border border-border bg-muted/60" />
                                    <span className="absolute inset-x-3 top-1.5 bottom-1.5 rounded-lg border border-dashed border-border bg-background" />
                                    <span className="absolute inset-x-0 top-3 bottom-0 rounded-lg border border-dashed border-border bg-background" />
                                </>
                            ) : (
                                <>
                                    {sheets >= 3 && (
                                        <span className="absolute inset-x-6 top-0 bottom-3 rotate-[2deg] rounded-lg border border-border bg-muted" />
                                    )}
                                    {sheets >= 2 && (
                                        <span className="absolute inset-x-3 top-1.5 bottom-1.5 -rotate-[1deg] rounded-lg border border-border bg-muted" />
                                    )}
                                    <span className="absolute inset-x-0 top-3 bottom-0 overflow-hidden rounded-lg border border-border bg-background">
                                        {front ? (
                                            <ArtifactPreview
                                                previewUrl={front.url}
                                                title={`Preview of ${artifactName(artifactOptions, front.id)}`}
                                                decorative
                                            />
                                        ) : (
                                            <span className="flex h-full flex-col justify-center gap-1.5 p-3">
                                                <span className="h-3 w-2/3 rounded bg-muted" />
                                                <span className="h-2 w-full rounded bg-muted/70" />
                                                <span className="h-2 w-5/6 rounded bg-muted/70" />
                                            </span>
                                        )}
                                    </span>
                                </>
                            )}
                        </span>
                        <span className="mt-2 flex min-h-11 items-center justify-between gap-2 text-sm font-medium">
                            <span>
                                {expanded ? 'Hide artifacts' : 'Show artifacts'}
                                <span className="font-normal text-muted-foreground">
                                    {' '}
                                    ·{' '}
                                    {artifactCountLabel(
                                        collection.artifactIds.length,
                                    )}
                                </span>
                            </span>
                            <StackChevron open={expanded} />
                        </span>
                    </button>
                    {canEdit &&
                        (availableArtifacts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No artifacts are available to add.
                            </p>
                        ) : (
                            <form
                                onSubmit={add}
                                className="flex items-end gap-2"
                            >
                                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <Label
                                        htmlFor={`add-artifact-${collection.id}`}
                                    >
                                        Add an artifact
                                    </Label>
                                    <select
                                        id={`add-artifact-${collection.id}`}
                                        className="h-11 w-full rounded-lg border border-border bg-transparent px-3 text-base"
                                        value={artifactId}
                                        onChange={(e) =>
                                            setArtifactId(e.target.value)
                                        }
                                    >
                                        <option value="">
                                            Choose an artifact…
                                        </option>
                                        {availableArtifacts.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.title}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={artifactId === ''}
                                >
                                    Add
                                </Button>
                            </form>
                        ))}
                    {canPin && (
                        <div>
                            <Button
                                variant="outline"
                                size="sm"
                                className="min-h-11"
                                onClick={() =>
                                    collection.canonical
                                        ? router.delete(
                                              CollectionController.unpin.url({
                                                  team,
                                                  collection: collection.id,
                                              }),
                                              { preserveScroll: true },
                                          )
                                        : router.post(
                                              CollectionController.pin.url({
                                                  team,
                                                  collection: collection.id,
                                              }),
                                              {},
                                              { preserveScroll: true },
                                          )
                                }
                            >
                                {collection.canonical
                                    ? 'Unpin canonical'
                                    : 'Pin as canonical'}
                            </Button>
                        </div>
                    )}
                </CardContent>
            </Card>

            <Dialog open={renaming} onOpenChange={setRenaming}>
                <DialogContent>
                    <DialogTitle>Rename collection</DialogTitle>
                    <DialogDescription>
                        Its artifacts stay the same, only the name changes.
                    </DialogDescription>
                    <form
                        className="mt-4 flex flex-col gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            renameForm.patch(
                                CollectionController.update.url({
                                    team,
                                    collection: collection.id,
                                }),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setRenaming(false),
                                },
                            );
                        }}
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={`rename-${collection.id}`}>
                                Collection name
                            </Label>
                            <Input
                                id={`rename-${collection.id}`}
                                className="h-11 text-base"
                                value={renameForm.data.name}
                                onChange={(e) =>
                                    renameForm.setData('name', e.target.value)
                                }
                            />
                        </div>
                        {renameForm.errors.name && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {renameForm.errors.name}
                            </p>
                        )}
                        <div className="flex justify-end gap-2">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={renameForm.processing}
                            >
                                Save name
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

function CollectionPanel({
    team,
    collection,
    canEdit,
    canOpenArtifacts,
    artifactOptions,
}: {
    team: string;
    collection: CollectionRow;
    canEdit: boolean;
    canOpenArtifacts: boolean;
    artifactOptions: ArtifactOption[];
}) {
    const [removing, setRemoving] = useState<string | null>(null);

    return (
        <>
            <section
                id={`collection-panel-${collection.id}`}
                aria-label={`${collection.name} artifacts`}
                data-testid={`collection-panel-${collection.id}`}
                className="min-w-0 rounded-[10px] border border-border bg-paper p-4 md:p-5"
            >
                <p className="mb-3 min-w-0 text-sm font-semibold break-words">
                    {collection.name}
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        · {artifactCountLabel(collection.artifactIds.length)}
                    </span>
                </p>
                {collection.artifactIds.length === 0 ? (
                    <EmptyState title="No artifacts yet." />
                ) : (
                    <ul className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {collection.artifactIds.map((id) => {
                            const name = artifactName(artifactOptions, id);
                            const openUrl = collection.openUrls[id];
                            const previewUrl = previewUrlFor(collection, id);

                            return (
                                <li
                                    key={id}
                                    data-testid={`collection-artifact-${collection.id}-${id}`}
                                    className="flex min-w-0 flex-col overflow-hidden rounded-lg border border-border bg-background"
                                >
                                    {previewUrl ? (
                                        <ArtifactPreview
                                            previewUrl={previewUrl}
                                            title={`Preview of ${name}`}
                                            className="border-b border-border"
                                        />
                                    ) : (
                                        <div
                                            aria-hidden="true"
                                            className="flex aspect-[8/5] w-full items-center justify-center border-b border-dashed border-border bg-muted/40 px-4"
                                        >
                                            <span className="text-center font-mono text-xs break-all text-muted-foreground">
                                                {id.slice(0, 8)}
                                            </span>
                                        </div>
                                    )}
                                    <div className="flex min-w-0 flex-1 flex-col gap-1 p-3">
                                        {canOpenArtifacts && openUrl ? (
                                            <a
                                                className="min-w-0 flex-1 break-words tabular-nums underline"
                                                href={openUrl}
                                                target="_blank"
                                                rel="noreferrer"
                                                data-testid={`collection-open-title-${collection.id}-${id}`}
                                            >
                                                {name}
                                            </a>
                                        ) : (
                                            <span className="min-w-0 flex-1 break-words tabular-nums">
                                                {name}
                                            </span>
                                        )}
                                        {(canOpenArtifacts && openUrl) ||
                                        canEdit ? (
                                            <div className="flex items-center gap-2">
                                                {canOpenArtifacts &&
                                                    openUrl && (
                                                        <a
                                                            href={openUrl}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            data-testid={`collection-open-${collection.id}-${id}`}
                                                            className="inline-flex min-h-11 items-center font-medium text-primary hover:underline"
                                                        >
                                                            Open
                                                        </a>
                                                    )}
                                                {canEdit && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="ml-auto min-h-11 shrink-0"
                                                        aria-label={`Remove ${name} from ${collection.name}`}
                                                        data-testid={`collection-remove-${collection.id}-${id}`}
                                                        onClick={() =>
                                                            setRemoving(id)
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            </div>
                                        ) : null}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove from ${collection.name}?`}
                description="The artifact stays in your team; it is only taken out of this collection."
                confirmLabel="Remove from collection"
                destructive={false}
                onConfirm={() => {
                    if (removing) {
                        router.delete(
                            CollectionController.removeArtifact.url({
                                team,
                                collection: collection.id,
                                artifactId: removing,
                            }),
                            { preserveScroll: true },
                        );
                    }

                    setRemoving(null);
                }}
            />
        </>
    );
}

export default function Collections({
    team,
    canEdit,
    canPin,
    canOpenArtifacts,
    collections,
    artifactOptions,
}: Props) {
    const form = useForm({ name: '', description: '' });
    // Expansion lives here, keyed by collection id, so POST/PATCH/DELETE
    // visits — which swap props but keep this component mounted — never
    // collapse what the reader opened, even across reorderings.
    const [expandedIds, setExpandedIds] = useState<ReadonlySet<number>>(
        () => new Set<number>(),
    );
    const columns = useColumnCount();
    const rows = chunk(collections, columns);

    const toggleCollection = (id: number) => {
        setExpandedIds((previous) => {
            const next = new Set(previous);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post(teamRoutes.collections.store.url({ team: team.slug }), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <>
            <Head title="Collections" />
            <h1 className="mb-1 text-2xl font-semibold">Collections</h1>
            <p className="mb-6 text-sm text-muted-foreground">
                Named sets of artifacts. A canonical collection ranks higher in
                search.
            </p>

            {canEdit && (
                <form onSubmit={create} className="mb-6 flex flex-wrap gap-2">
                    <Input
                        aria-label="Collection name"
                        className="h-11 w-64 max-w-full text-base"
                        placeholder="New collection name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <Button
                        type="submit"
                        className="min-h-11"
                        disabled={form.processing}
                    >
                        Create
                    </Button>
                    {form.errors.name && (
                        <p className="w-full text-sm text-destructive">
                            {form.errors.name}
                        </p>
                    )}
                </form>
            )}

            <div className="flex flex-col gap-4" data-testid="collection-rows">
                {collections.length === 0 && (
                    <EmptyState title="No collections yet." />
                )}
                {rows.map((row, rowIndex) => (
                    <Fragment key={`row-${rowIndex}`}>
                        <div
                            className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
                            data-testid={`collection-row-${rowIndex}`}
                        >
                            {row.map((collection) => (
                                <CollectionCard
                                    key={collection.id}
                                    team={team.slug}
                                    collection={collection}
                                    expanded={expandedIds.has(collection.id)}
                                    onToggle={() =>
                                        toggleCollection(collection.id)
                                    }
                                    canEdit={canEdit}
                                    canPin={canPin}
                                    artifactOptions={artifactOptions}
                                />
                            ))}
                        </div>
                        {row
                            .filter((collection) =>
                                expandedIds.has(collection.id),
                            )
                            .map((collection) => (
                                <CollectionPanel
                                    key={`panel-${collection.id}`}
                                    team={team.slug}
                                    collection={collection}
                                    canEdit={canEdit}
                                    canOpenArtifacts={canOpenArtifacts}
                                    artifactOptions={artifactOptions}
                                />
                            ))}
                    </Fragment>
                ))}
            </div>
        </>
    );
}

Collections.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
