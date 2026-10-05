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
import teamRoutes from '@/routes/teams';
import type { SharedTeam } from '@/types/shared';

export default function TeamsIndex({ teams }: { teams: SharedTeam[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        slug: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(teamRoutes.store.url(), { onSuccess: () => reset() });
    };

    return (
        <>
            <Head title="Your teams" />
            <h1 className="mb-6 min-w-0 text-2xl font-semibold break-words">
                Your teams
            </h1>

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
                                    className="flex min-w-0 flex-wrap items-center gap-2 py-3 md:gap-3"
                                >
                                    <Link
                                        className="min-w-0 font-medium break-words hover:underline"
                                        href={teamRoutes.edit.url({
                                            team: team.slug,
                                        })}
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
                                                    teamRoutes.switch.url({
                                                        team: team.slug,
                                                    }),
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
                        <CardTitle>New team</CardTitle>
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
                                <Label htmlFor="team-name">Name</Label>
                                <Input
                                    id="team-name"
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
                                <Label htmlFor="team-slug">
                                    Slug (optional)
                                </Label>
                                <Input
                                    id="team-slug"
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
                                Create team
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

TeamsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
