import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { Alert } from '@/components/ui/alert';
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
import accountRoutes from '@/routes/account';
import teamRoutes from '@/routes/teams';
import type { SharedProps } from '@/types/shared';

interface Props {
    teams: { slug: string; name: string; role: string | null }[];
    identities: { provider: string; email: string }[];
    blockingTeams: string[];
}

export default function Account({ teams, identities, blockingTeams }: Props) {
    const [confirmation, setConfirmation] = useState('');
    const { auth } = usePage<SharedProps>().props;
    const form = useForm({ name: auth.user?.name ?? '' });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch(accountRoutes.update.url());
    };

    const deleteAccount = () =>
        router.delete(accountRoutes.destroy.url(), { data: { confirmation } });

    return (
        <>
            <Head title="Account" />
            <h1 className="mb-6 text-2xl font-semibold">Account</h1>

            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Profile</CardTitle>
                        <CardDescription>{auth.user?.email}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={save}
                            className="flex max-w-sm flex-col gap-3"
                        >
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            {form.errors.name && (
                                <p className="text-sm text-destructive">
                                    {form.errors.name}
                                </p>
                            )}
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Linked identities</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {identities.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No linked sign-in identities.
                            </p>
                        ) : (
                            <ul className="text-sm">
                                {identities.map((identity) => (
                                    <li
                                        key={`${identity.provider}:${identity.email}`}
                                    >
                                        {identity.provider} · {identity.email}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Teams</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-y divide-border text-sm">
                            {teams.map((team) => (
                                <li
                                    key={team.slug}
                                    className="flex items-center gap-3 py-2"
                                >
                                    <span>{team.name}</span>
                                    <span className="text-muted-foreground">
                                        {team.role}
                                    </span>
                                    <Button
                                        className="ml-auto"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.delete(
                                                teamRoutes.leave.url({
                                                    team: team.slug,
                                                }),
                                            )
                                        }
                                    >
                                        Leave
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Delete account</CardTitle>
                        <CardDescription>
                            Revokes your API tokens and removes you from every
                            team.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        {blockingTeams.length > 0 && (
                            <Alert variant="warning">
                                Transfer ownership first: you still own{' '}
                                {blockingTeams.join(', ')}.
                            </Alert>
                        )}
                        <div className="flex max-w-sm flex-col gap-2">
                            <Label htmlFor="confirmation">
                                Type DELETE to confirm
                            </Label>
                            <Input
                                id="confirmation"
                                value={confirmation}
                                onChange={(event) =>
                                    setConfirmation(event.target.value)
                                }
                            />
                            <Button
                                variant="destructive"
                                disabled={
                                    blockingTeams.length > 0 ||
                                    confirmation !== 'DELETE'
                                }
                                onClick={deleteAccount}
                            >
                                Delete my account
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Account.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
