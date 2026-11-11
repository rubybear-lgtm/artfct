import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { formatDateTime } from '@/components/ops/format';
import { Section } from '@/components/ops/section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OpsLayout from '@/layouts/ops-layout';
import { index, update } from '@/routes/ops/features';

type ReleaseState = 'off' | 'selected' | 'released';
type Props = {
    environment: string;
    feature: { key: string; name: string; description: string };
    state: ReleaseState;
    team_slugs: string[];
    history: {
        id: number;
        staff: string;
        reason: string;
        created_at: string;
    }[];
    status: string | null;
};

const states: { value: ReleaseState; label: string; description: string }[] = [
    {
        value: 'off',
        label: 'Off',
        description: 'Unavailable to everyone. Your team selection is kept.',
    },
    {
        value: 'selected',
        label: 'Selected teams',
        description: 'Available only to the teams listed below.',
    },
    {
        value: 'released',
        label: 'Released',
        description: 'Available to all eligible teams, including new teams.',
    },
];

export default function FeatureRelease({
    environment,
    feature,
    state,
    team_slugs,
    history,
    status,
}: Props) {
    const [teamText, setTeamText] = useState(team_slugs.join('\n'));
    const form = useForm({ state, team_slugs, reason: '', confirm: false });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            team_slugs: teamText.split(/\s+/).filter(Boolean),
        }));
        form.submit(update(feature.key), {
            preserveScroll: true,
            onSuccess: () => form.reset('reason', 'confirm'),
        });
    }

    return (
        <>
            <Head title={`${feature.name} release`} />
            <Link
                href={index()}
                className="mb-5 inline-flex min-h-11 items-center text-sm text-muted-foreground underline underline-offset-4"
            >
                All feature releases
            </Link>
            <h1 className="mb-2 break-words">{feature.name}</h1>
            <p className="max-w-prose text-sm text-muted-foreground">
                {feature.description}
            </p>
            <p className="mt-3 mb-8 text-sm">
                Editing <strong>{environment}</strong>. Other environments are
                unaffected.
            </p>
            {status && (
                <p role="status" className="mb-6 text-sm text-success">
                    {status}
                </p>
            )}

            <form onSubmit={submit} className="max-w-2xl">
                <Section
                    title="Availability"
                    description="Existing plan and role permissions still apply."
                >
                    <fieldset className="space-y-4">
                        <legend className="sr-only">Release state</legend>
                        {states.map((option) => (
                            <label
                                key={option.value}
                                className="flex min-h-11 cursor-pointer items-start gap-3"
                            >
                                <input
                                    type="radio"
                                    name="state"
                                    value={option.value}
                                    checked={form.data.state === option.value}
                                    onChange={() =>
                                        form.setData((data) => ({
                                            ...data,
                                            state: option.value,
                                            confirm: false,
                                        }))
                                    }
                                    className="mt-1 size-4 accent-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                />
                                <span>
                                    <span className="block text-sm font-medium">
                                        {option.label}
                                    </span>
                                    <span className="mt-1 block text-sm text-muted-foreground">
                                        {option.description}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    {form.errors.state && (
                        <p
                            role="alert"
                            className="mt-2 text-sm text-destructive"
                        >
                            {form.errors.state}
                        </p>
                    )}
                </Section>

                <Section
                    title="Selected teams"
                    description="Enter team slugs, one per line. This list is retained while the feature is off or released."
                >
                    <Label htmlFor="team-slugs">Team slugs</Label>
                    <textarea
                        id="team-slugs"
                        value={teamText}
                        onChange={(event) => {
                            setTeamText(event.target.value);
                            form.setData('confirm', false);
                        }}
                        rows={4}
                        spellCheck={false}
                        aria-describedby="team-slugs-help"
                        aria-invalid={Boolean(form.errors.team_slugs)}
                        className="border-input mt-2 block w-full rounded-md border bg-background p-3 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    />
                    <p
                        id="team-slugs-help"
                        className="mt-2 text-xs text-muted-foreground"
                    >
                        Find slugs in the Tenants list. Maximum 200 teams.
                    </p>
                    {Object.entries(form.errors)
                        .filter(([key]) => key.startsWith('team_slugs'))
                        .map(([key, message]) => (
                            <p
                                key={key}
                                role="alert"
                                className="mt-2 text-sm text-destructive"
                            >
                                {message}
                            </p>
                        ))}
                </Section>

                <Section
                    title="Save release"
                    description="Review the availability and team selection before saving. Every change is recorded."
                >
                    <Label htmlFor="release-reason">Reason for change</Label>
                    <Input
                        id="release-reason"
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        required
                        minLength={5}
                        maxLength={500}
                        className="mt-2"
                        aria-invalid={Boolean(form.errors.reason)}
                    />
                    {form.errors.reason && (
                        <p
                            role="alert"
                            className="mt-2 text-sm text-destructive"
                        >
                            {form.errors.reason}
                        </p>
                    )}
                    <label className="mt-4 flex min-h-11 cursor-pointer items-center gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.confirm}
                            onChange={(event) =>
                                form.setData('confirm', event.target.checked)
                            }
                            className="size-4 accent-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        />
                        I confirm this release change in {environment}.
                    </label>
                    {form.errors.confirm && (
                        <p
                            role="alert"
                            className="mt-2 text-sm text-destructive"
                        >
                            {form.errors.confirm}
                        </p>
                    )}
                    <Button
                        type="submit"
                        disabled={form.processing || !form.data.confirm}
                        className="mt-4"
                    >
                        {form.processing ? 'Saving…' : 'Save release'}
                    </Button>
                </Section>
            </form>

            <Section
                title="Recent changes"
                description="The latest 30 changes in this environment."
            >
                {history.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No release changes yet.
                    </p>
                ) : (
                    <ol className="divide-y divide-border">
                        {history.map((entry) => (
                            <li key={entry.id} className="py-4 first:pt-0">
                                <p className="text-sm break-words">
                                    {entry.reason}
                                </p>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {entry.staff} ·{' '}
                                    <time dateTime={entry.created_at}>
                                        {formatDateTime(entry.created_at)}
                                    </time>
                                </p>
                            </li>
                        ))}
                    </ol>
                )}
            </Section>
        </>
    );
}

FeatureRelease.layout = (page: React.ReactNode) => (
    <OpsLayout>{page}</OpsLayout>
);
