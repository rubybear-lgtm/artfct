import { Head, router, useForm } from '@inertiajs/react';
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
import AppLayout from '@/layouts/app-layout';

interface Props {
    team: { slug: string; name: string };
    retentionDays: number | null;
    defaultRetentionDays: number;
    isEnterprise: boolean;
    heldArtifacts: string[] | null;
    preview: {
        wouldDelete?: number;
        heldSurvivors?: number;
        error?: string;
    } | null;
}

export default function Governance({
    team,
    retentionDays,
    defaultRetentionDays,
    isEnterprise,
    heldArtifacts,
    preview,
}: Props) {
    const base = `/settings/teams/${team.slug}/governance`;
    const retention = useForm({ retention_days: retentionDays ?? '' });
    const hold = useForm({ artifact_id: '' });

    const saveRetention = (event: FormEvent) => {
        event.preventDefault();
        retention.patch(`/settings/teams/${team.slug}/retention`);
    };

    const placeHold = (event: FormEvent) => {
        event.preventDefault();
        hold.post(`${base}/holds`, { onSuccess: () => hold.reset() });
    };

    return (
        <>
            <Head title="Governance" />
            <h1 className="mb-1 text-2xl font-semibold">Governance</h1>
            <p className="mb-6 text-sm text-muted-foreground">
                How long {team.name} keeps artifacts, and which ones are
                protected from deletion.
            </p>

            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Retention</CardTitle>
                        <CardDescription>
                            Artifacts older than the retention period are
                            deleted for good by the scheduled retention job.
                            Artifacts under legal hold are always kept. The
                            default is {defaultRetentionDays} days.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <form
                            onSubmit={saveRetention}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <label className="flex flex-col gap-1 text-sm">
                                Retention (days)
                                <Input
                                    type="number"
                                    min={1}
                                    max={3650}
                                    placeholder={String(defaultRetentionDays)}
                                    disabled={!isEnterprise}
                                    value={retention.data.retention_days}
                                    onChange={(e) =>
                                        retention.setData(
                                            'retention_days',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <Button
                                type="submit"
                                disabled={!isEnterprise || retention.processing}
                            >
                                Save
                            </Button>
                            {!isEnterprise && (
                                <span className="text-sm text-muted-foreground">
                                    A custom retention period is an Enterprise
                                    feature.
                                </span>
                            )}
                            {retention.errors.retention_days && (
                                <p className="w-full text-sm text-destructive">
                                    {retention.errors.retention_days}
                                </p>
                            )}
                        </form>

                        <div className="flex flex-col gap-2">
                            <div>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(`${base}/preview`)
                                    }
                                >
                                    Preview what would be deleted
                                </Button>
                            </div>
                            {preview?.error && (
                                <Alert variant="warning">{preview.error}</Alert>
                            )}
                            {preview && !preview.error && (
                                <Alert>
                                    A retention run now would delete{' '}
                                    {preview.wouldDelete} artifact(s) and keep{' '}
                                    {preview.heldSurvivors} under legal hold.
                                    Nothing was deleted.
                                </Alert>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Legal hold</CardTitle>
                        <CardDescription>
                            A held artifact is never deleted by retention or
                            erasure until the hold is released.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        {heldArtifacts === null ? (
                            <p className="text-sm text-muted-foreground">
                                The list of held artifacts is unavailable right
                                now.
                            </p>
                        ) : heldArtifacts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No artifacts are under legal hold.
                            </p>
                        ) : (
                            <ul className="text-sm">
                                {heldArtifacts.map((id) => (
                                    <li
                                        key={id}
                                        className="flex items-center gap-2"
                                    >
                                        <span className="font-mono">{id}</span>
                                        <button
                                            className="ml-auto underline"
                                            onClick={() =>
                                                router.delete(
                                                    `${base}/holds/${id}`,
                                                )
                                            }
                                        >
                                            Release
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <form onSubmit={placeHold} className="flex gap-2">
                            <Input
                                aria-label="Artifact id to hold"
                                placeholder="Artifact id"
                                disabled={!isEnterprise}
                                value={hold.data.artifact_id}
                                onChange={(e) =>
                                    hold.setData('artifact_id', e.target.value)
                                }
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={!isEnterprise || hold.processing}
                            >
                                Place hold
                            </Button>
                        </form>
                        {!isEnterprise && (
                            <p className="text-sm text-muted-foreground">
                                Legal hold is an Enterprise feature.
                            </p>
                        )}
                        {hold.errors.artifact_id && (
                            <p className="text-sm text-destructive">
                                {hold.errors.artifact_id}
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Alert>
                    Permanent erasure of a person&apos;s data is run by an
                    operator, not from this page. Contact support to request it.
                </Alert>
            </div>
        </>
    );
}

Governance.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
