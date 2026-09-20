import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';

interface CollectionRow {
    id: number;
    name: string;
    description: string | null;
    canonical: boolean;
    artifactIds: string[];
}

interface Props {
    team: { slug: string; name: string };
    canEdit: boolean;
    canPin: boolean;
    collections: CollectionRow[];
}

function CollectionCard({
    base,
    collection,
    canEdit,
    canPin,
}: {
    base: string;
    collection: CollectionRow;
    canEdit: boolean;
    canPin: boolean;
}) {
    const [artifactId, setArtifactId] = useState('');
    const url = `${base}/${collection.id}`;

    const add = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            `${url}/artifacts`,
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
                    <p className="text-sm text-muted-foreground">
                        No artifacts yet.
                    </p>
                ) : (
                    <ul className="text-sm">
                        {collection.artifactIds.map((id) => (
                            <li key={id} className="flex items-center gap-2">
                                <span className="font-mono">{id}</span>
                                {canEdit && (
                                    <button
                                        className="ml-auto underline"
                                        onClick={() =>
                                            router.delete(
                                                `${url}/artifacts/${id}`,
                                            )
                                        }
                                    >
                                        Remove
                                    </button>
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
                                    ? router.delete(`${url}/pin`)
                                    : router.post(`${url}/pin`)
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
    collections,
}: Props) {
    const base = `/settings/teams/${team.slug}/collections`;
    const form = useForm({ name: '', description: '' });

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post(base, { onSuccess: () => form.reset() });
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
                    <p className="text-sm text-muted-foreground">
                        No collections yet.
                    </p>
                )}
                {collections.map((collection) => (
                    <CollectionCard
                        key={collection.id}
                        base={base}
                        collection={collection}
                        canEdit={canEdit}
                        canPin={canPin}
                    />
                ))}
            </div>
        </>
    );
}

Collections.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
