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
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
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

interface Props {
    team: { slug: string; name: string };
    canEdit: boolean;
    canPin: boolean;
    canOpenArtifacts: boolean;
    collections: CollectionRow[];
}

function CollectionCard({
    team,
    collection,
    canEdit,
    canPin,
    canOpenArtifacts,
}: {
    team: string;
    collection: CollectionRow;
    canEdit: boolean;
    canPin: boolean;
    canOpenArtifacts: boolean;
}) {
    const [artifactId, setArtifactId] = useState('');
    const add = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            CollectionController.addArtifact.url({
                team,
                collection: collection.id,
            }),
            { artifact_id: artifactId },
            { onSuccess: () => setArtifactId('') },
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    {collection.name}
                    {collection.canonical && (
                        <Badge variant="success">canonical</Badge>
                    )}
                </CardTitle>
                {collection.description && (
                    <CardDescription>{collection.description}</CardDescription>
                )}
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {collection.artifactIds.length === 0 ? (
                    <EmptyState title="No artifacts yet." />
                ) : (
                    <ul className="text-sm">
                        {collection.artifactIds.map((id) => (
                            <li key={id} className="flex items-center gap-2">
                                {canOpenArtifacts && collection.openUrls[id] ? (
                                    <a
                                        className="tabular-nums underline"
                                        href={collection.openUrls[id]}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {id}
                                    </a>
                                ) : (
                                    <span className="tabular-nums">{id}</span>
                                )}
                                {canEdit && (
                                    <Button
                                        type="button"
                                        variant="link"
                                        size="sm"
                                        className="ml-auto underline"
                                        onClick={() =>
                                            router.delete(
                                                CollectionController.removeArtifact.url(
                                                    {
                                                        team,
                                                        collection:
                                                            collection.id,
                                                        artifactId: id,
                                                    },
                                                ),
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {canEdit && (
                    <form onSubmit={add} className="flex gap-2">
                        <Input
                            aria-label={`Artifact id for ${collection.name}`}
                            placeholder="Artifact id"
                            value={artifactId}
                            onChange={(e) => setArtifactId(e.target.value)}
                        />
                        <Button type="submit" variant="outline">
                            Add
                        </Button>
                    </form>
                )}
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
    );
}

export default function Collections({
    team,
    canEdit,
    canPin,
    canOpenArtifacts,
    collections,
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
                    />
                ))}
            </div>
        </>
    );
}

Collections.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
