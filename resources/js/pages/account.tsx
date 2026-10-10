import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTheme } from '@/lib/theme';
import type { Theme } from '@/lib/theme';
import accountRoutes from '@/routes/account';
import teamRoutes from '@/routes/teams';
import type { SharedProps } from '@/types/shared';

interface Props {
    teams: {
        slug: string;
        name: string;
        role: string | null;
        canLeave: boolean;
        leaveBlockedReason: string | null;
    }[];
    identities: { provider: string; email: string }[];
    blockingTeams: string[];
}

const THEME_OPTIONS: [Theme, string][] = [
    ['system', 'System'],
    ['light', 'Light'],
    ['dark', 'Dark'],
];

export default function Account({ teams, identities, blockingTeams }: Props) {
    const [theme, , setTheme] = useTheme();
    const themeButtons = useRef<(HTMLButtonElement | null)[]>([]);
    const [confirmation, setConfirmation] = useState('');
    const [leavingTeam, setLeavingTeam] = useState<{
        slug: string;
        name: string;
    } | null>(null);
    const { auth, errors } = usePage<SharedProps>().props;
    const form = useForm({ name: auth.user?.name ?? '' });

    // 'name' is shown next to its field; anything else (a refused leave, a
    // refused transfer) has no field on this page, so show it at the top.
    const pageErrors = Object.entries(errors).filter(([key]) => key !== 'name');

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch(accountRoutes.update.url());
    };

    const deleteAccount = () =>
        router.delete(accountRoutes.destroy.url(), { data: { confirmation } });

    const moveThemeSelection = (
        event: KeyboardEvent<HTMLButtonElement>,
        index: number,
    ): void => {
        const last = THEME_OPTIONS.length - 1;
        const target = {
            ArrowRight: index === last ? 0 : index + 1,
            ArrowDown: index === last ? 0 : index + 1,
            ArrowLeft: index === 0 ? last : index - 1,
            ArrowUp: index === 0 ? last : index - 1,
            Home: 0,
            End: last,
        }[event.key];

        if (target === undefined) {
            return;
        }

        event.preventDefault();
        setTheme(THEME_OPTIONS[target][0]);
        themeButtons.current[target]?.focus();
    };

    return (
        <>
            <Head title="Account" />
            <h1 className="mb-6 min-w-0 text-2xl font-semibold break-words">
                Account
            </h1>

            {pageErrors.length > 0 && (
                <Alert variant="destructive" className="mb-6">
                    <ul className="flex flex-col gap-1">
                        {pageErrors.map(([key, message]) => (
                            <li key={key}>{message}</li>
                        ))}
                    </ul>
                </Alert>
            )}

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
                        <CardTitle>Appearance</CardTitle>
                        <CardDescription>
                            System follows your device setting.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div
                            role="radiogroup"
                            aria-label="Theme"
                            className="inline-flex rounded-md border border-border p-0.5"
                        >
                            {THEME_OPTIONS.map(([value, label], index) => (
                                <button
                                    key={value}
                                    ref={(element) => {
                                        themeButtons.current[index] = element;
                                    }}
                                    type="button"
                                    role="radio"
                                    aria-checked={theme === value}
                                    tabIndex={theme === value ? 0 : -1}
                                    onClick={() => setTheme(value)}
                                    onKeyDown={(event) =>
                                        moveThemeSelection(event, index)
                                    }
                                    className={`rounded px-3 py-1.5 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring max-md:min-h-11 max-md:px-4 ${
                                        theme === value
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
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
                                        className="break-all"
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
                                    className="flex min-w-0 flex-wrap items-center gap-2 py-2"
                                >
                                    <span className="min-w-0 break-words">
                                        {team.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {team.role}
                                    </span>
                                    {team.canLeave ? (
                                        <Button
                                            className="ml-auto"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setLeavingTeam(team)}
                                        >
                                            Leave
                                        </Button>
                                    ) : (
                                        <span className="ml-auto min-w-0 break-words text-muted-foreground">
                                            {team.leaveBlockedReason}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Delete account</CardTitle>
                        <CardDescription>
                            Deletes the teams you own and everything in them,
                            revokes your tokens, and removes you from every
                            other team. This cannot be undone.
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

            <ConfirmDialog
                open={leavingTeam !== null}
                onOpenChange={(open) => !open && setLeavingTeam(null)}
                title={`Leave ${leavingTeam?.name}?`}
                description={`You lose access to everything shared in ${leavingTeam?.name}. An admin will need to invite you again.`}
                confirmLabel="Leave team"
                onConfirm={() => {
                    if (leavingTeam) {
                        router.delete(
                            teamRoutes.leave.url({ team: leavingTeam.slug }),
                        );
                    }

                    setLeavingTeam(null);
                }}
            />
        </>
    );
}

Account.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
