import { Head, Link, router, useForm } from '@inertiajs/react';
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
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { SharedTeam } from '@/types/shared';

export default function TeamsIndex({ teams }: { teams: SharedTeam[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        slug: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/settings/teams', { onSuccess: () => reset() });
    };

    return (
        <>
            <Head title="Your orgs" />
            <h1 className="mb-6 text-2xl font-semibold">Your orgs</h1>

            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Teams</CardTitle>
                        <CardDescription>
                            Switch to a team or open its settings.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-y divide-border">
                            {teams.map((team) => (
                                <li
                                    key={team.id}
                                    className="flex items-center gap-3 py-3"
                                >
                                    <Link
                                        className="font-medium hover:underline"
                                        href={`/settings/teams/${team.slug}`}
                                    >
                                        {team.name}
                                    </Link>
                                    {team.isPersonal && (
                                        <Badge variant="outline">
                                            personal
                                        </Badge>
                                    )}
                                    {team.isCurrent && (
                                        <Badge variant="success">current</Badge>
                                    )}
                                    <span className="ml-auto text-sm text-muted-foreground">
                                        {team.roleLabel}
                                    </span>
                                    {!team.isCurrent && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/settings/teams/${team.slug}/switch`,
                                                )
                                            }
                                        >
                                            Switch
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>New org</CardTitle>
                        <CardDescription>
                            You become its owner and admin.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="flex flex-wrap items-end gap-3"
                        >
                            <div className="flex min-w-56 flex-1 flex-col gap-1.5">
                                <Label htmlFor="org-name">Name</Label>
                                <Input
                                    id="org-name"
                                    name="name"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                />
                                {errors.name && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.name}
                                    </p>
                                )}
                            </div>
                            <div className="flex min-w-56 flex-1 flex-col gap-1.5">
                                <Label htmlFor="org-slug">
                                    Slug (optional)
                                </Label>
                                <Input
                                    id="org-slug"
                                    name="slug"
                                    value={data.slug}
                                    onChange={(e) =>
                                        setData('slug', e.target.value)
                                    }
                                />
                                {errors.slug && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.slug}
                                    </p>
                                )}
                            </div>
                            <Button type="submit" disabled={processing}>
                                Create org
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

TeamsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
