import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';

interface Result {
    id: string;
    title: string;
    description: string | null;
    url: string;
    snippet: string;
    agent: string | null;
    repoUrl: string | null;
    canonical: boolean;
}

interface Props {
    team: { slug: string; name: string };
    indexingEnabled: boolean;
    filters: { q: string; agent: string; repo: string; collection: string };
    collections: { name: string; canonical: boolean }[];
    results: Result[];
    searched: boolean;
    error: string | null;
}

export default function Search({
    team,
    indexingEnabled,
    filters,
    collections,
    results,
    searched,
    error,
}: Props) {
    const [form, setForm] = useState(filters);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            `/settings/teams/${team.slug}/search`,
            Object.fromEntries(
                Object.entries(form).filter(([, value]) => value !== ''),
            ),
        );
    };

    return (
        <>
            <Head title="Search" />
            <h1 className="mb-1 text-2xl font-semibold">Search</h1>
            <p className="mb-6 text-sm text-muted-foreground">
                Find artifacts across {team.name} by what they say, not just
                their title.
            </p>

            {!indexingEnabled && (
                <Alert>
                    Search indexing is turned off on this environment, so there
                    is nothing to search yet. Artifacts still work as links.
                </Alert>
            )}

            <form onSubmit={submit} className="mb-6 flex flex-wrap gap-3">
                <Input
                    aria-label="Search query"
                    className="min-w-64 flex-1"
                    placeholder="What are you looking for?"
                    value={form.q}
                    disabled={!indexingEnabled}
                    onChange={(e) => setForm({ ...form, q: e.target.value })}
                />
                <Input
                    aria-label="Agent"
                    className="w-40"
                    placeholder="Agent"
                    value={form.agent}
                    disabled={!indexingEnabled}
                    onChange={(e) =>
                        setForm({ ...form, agent: e.target.value })
                    }
                />
                <Input
                    aria-label="Repository"
                    className="w-56"
                    placeholder="Repository URL"
                    value={form.repo}
                    disabled={!indexingEnabled}
                    onChange={(e) => setForm({ ...form, repo: e.target.value })}
                />
                {collections.length > 0 && (
                    <select
                        aria-label="Collection"
                        className="rounded-md border border-border bg-background px-2"
                        value={form.collection}
                        disabled={!indexingEnabled}
                        onChange={(e) =>
                            setForm({ ...form, collection: e.target.value })
                        }
                    >
                        <option value="">All collections</option>
                        {collections.map((collection) => (
                            <option
                                key={collection.name}
                                value={collection.name}
                            >
                                {collection.name}
                                {collection.canonical ? ' (canonical)' : ''}
                            </option>
                        ))}
                    </select>
                )}
                <Button type="submit" disabled={!indexingEnabled}>
                    Search
                </Button>
            </form>

            {error && <Alert variant="warning">{error}</Alert>}

            {searched && !error && results.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No artifacts matched. Try fewer filters or different words.
                </p>
            )}

            <div className="flex flex-col gap-3">
                {results.map((result) => (
                    <Card key={result.id}>
                        <CardContent className="flex flex-col gap-1 pt-4">
                            <a
                                className="font-medium underline"
                                href={result.url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                {result.title || result.id.slice(0, 8)}
                            </a>
                            {result.description && (
                                <p className="text-sm text-muted-foreground">
                                    {result.description}
                                </p>
                            )}
                            <p className="text-sm">{result.snippet}</p>
                            <div className="flex gap-2">
                                {result.canonical && (
                                    <Badge variant="success">canonical</Badge>
                                )}
                                {result.agent && (
                                    <Badge variant="outline">
                                        {result.agent}
                                    </Badge>
                                )}
                                {result.repoUrl && (
                                    <Badge variant="outline">
                                        {result.repoUrl}
                                    </Badge>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </>
    );
}

Search.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
