import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import CollectionController from '@/actions/App/Http/Controllers/Teams/CollectionController';
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

function CollectionCard({
    team,
    collection,
    canEdit,
    canPin,
    canOpenArtifacts,
    artifactOptions,
}: {
    team: string;
    collection: CollectionRow;
    canEdit: boolean;
    canPin: boolean;
    canOpenArtifacts: boolean;
    artifactOptions: ArtifactOption[];
}) {
    const [artifactId, setArtifactId] = useState('');
    const [renaming, setRenaming] = useState(false);
    const [removing, setRemoving] = useState<string | null>(null);
    const renameForm = useForm({ name: collection.name });

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
            { onSuccess: () => setArtifactId('') },
        );
    };

    const availableArtifacts = artifactOptions.filter(
        (option) => !collection.artifactIds.includes(option.id),
    );

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        {collection.name}
                        {collection.canonical && (
                            <Badge variant="success">canonical</Badge>
                        )}
                        {canEdit && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="ml-auto"
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
                        <CardDescription>
                            {collection.description}
                        </CardDescription>
                    )}
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    {collection.artifactIds.length === 0 ? (
                        <EmptyState title="No artifacts yet." />
                    ) : (
                        <ul className="text-sm">
                            {collection.artifactIds.map((id) => (
                                <li
                                    key={id}
                                    className="flex items-center gap-2"
                                >
                                    {canOpenArtifacts &&
                                    collection.openUrls[id] ? (
                                        <a
                                            className="tabular-nums underline"
                                            href={collection.openUrls[id]}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            {id}
                                        </a>
                                    ) : (
                                        <span className="tabular-nums">
                                            {id}
                                        </span>
                                    )}
                                    {canEdit && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="ml-auto"
                                            aria-label={`Remove ${artifactName(artifactOptions, id)} from ${collection.name}`}
                                            onClick={() => setRemoving(id)}
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
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
                                <div className="flex flex-1 flex-col gap-1.5">
                                    <Label
                                        htmlFor={`add-artifact-${collection.id}`}
                                    >
                                        Add an artifact
                                    </Label>
                                    <select
                                        id={`add-artifact-${collection.id}`}
                                        className="h-9 w-full rounded-lg border border-border bg-transparent px-3 text-sm"
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
                                onClick={() =>
                                    collection.canonical
                                        ? router.delete(
                                              CollectionController.unpin.url({
                                                  team,
                                                  collection: collection.id,
                                              }),
                                          )
                                        : router.post(
                                              CollectionController.pin.url({
                                                  team,
                                                  collection: collection.id,
                                              }),
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
                                { onSuccess: () => setRenaming(false) },
                            );
                        }}
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={`rename-${collection.id}`}>
                                Collection name
                            </Label>
                            <Input
                                id={`rename-${collection.id}`}
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
                                <Button type="button" variant="outline">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={renameForm.processing}
                            >
                                Save name
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

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

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post(teamRoutes.collections.store.url({ team: team.slug }), {
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
                        className="w-64"
                        placeholder="New collection name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <Button type="submit" disabled={form.processing}>
                        Create
                    </Button>
                    {form.errors.name && (
                        <p className="w-full text-sm text-destructive">
                            {form.errors.name}
                        </p>
                    )}
                </form>
            )}

            <div className="flex flex-col gap-4">
                {collections.length === 0 && (
                    <EmptyState title="No collections yet." />
                )}
                {collections.map((collection) => (
                    <CollectionCard
                        key={collection.id}
                        team={team.slug}
                        collection={collection}
                        canEdit={canEdit}
                        canPin={canPin}
                        canOpenArtifacts={canOpenArtifacts}
                        artifactOptions={artifactOptions}
                    />
                ))}
            </div>
        </>
    );
}

Collections.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
