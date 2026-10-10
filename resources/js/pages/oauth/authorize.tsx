import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface Team {
    id: string;
    name: string;
    slug: string;
}

interface RequestedScope {
    value: string;
    label: string;
    risk: ScopeRisk;
}

type ScopeRisk = 'read' | 'write' | 'destructive';

interface Props {
    clientId: string;
    clientName: string;
    userName: string;
    redirectUri: string;
    scope: string;
    requestedScopes: RequestedScope[];
    state: string | null;
    codeChallenge: string;
    codeChallengeMethod: string;
    consentToken: string;
    team: Team;
    teams: Team[];
}

const riskLabels: Record<Exclude<ScopeRisk, 'read'>, string> = {
    write: 'Can modify',
    destructive: 'Destructive',
};

export default function Authorize({
    clientId,
    clientName,
    userName,
    redirectUri,
    scope,
    requestedScopes,
    state,
    codeChallenge,
    codeChallengeMethod,
    consentToken,
    team,
    teams,
}: Props) {
    const form = useForm({
        client_id: clientId,
        redirect_uri: redirectUri,
        response_type: 'code',
        scope,
        state: state ?? '',
        code_challenge: codeChallenge,
        code_challenge_method: codeChallengeMethod,
        consent_token: consentToken,
        decision: 'approve',
        team: team.slug,
    });

    const submit = (decision: 'approve' | 'deny') => {
        form.transform((data) => ({ ...data, decision }));
        form.post('/oauth/authorize');
    };

    return (
        <>
            <Head title="Connect an AI tool" />
            <Card>
                <CardHeader>
                    <CardTitle>
                        {clientName} wants to connect to {team.name}
                    </CardTitle>
                    <CardDescription>
                        It will be able to do the things listed below with your
                        team's shared work. Only continue if you started this
                        from your AI tool.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-5">
                    <div className="grid gap-3 rounded-md border p-3 text-sm sm:grid-cols-2">
                        <div>
                            <div className="font-medium">App</div>
                            <div className="mt-1 break-words text-muted-foreground">
                                {clientName}
                            </div>
                            {clientId !== clientName && (
                                <div className="mt-1 text-xs break-all text-muted-foreground">
                                    {clientId}
                                </div>
                            )}
                        </div>
                        <div className="min-w-0">
                            <div className="font-medium">Signed in as</div>
                            <div className="mt-1 break-words text-muted-foreground">
                                {userName}
                            </div>
                        </div>
                        <div className="sm:col-span-2">
                            <div className="font-medium">Team</div>
                            <div className="mt-1 break-words text-muted-foreground">
                                {team.name}
                            </div>
                        </div>
                    </div>

                    <div className="rounded-md border p-3 text-sm">
                        <div className="font-medium">What it can do</div>
                        <ul className="mt-2 space-y-2">
                            {requestedScopes.map((requestedScope) => {
                                const isDestructive =
                                    requestedScope.risk === 'destructive';
                                const riskLabel =
                                    requestedScope.risk === 'read'
                                        ? null
                                        : riskLabels[requestedScope.risk];

                                return (
                                    <li
                                        key={requestedScope.value}
                                        data-testid={`scope-risk-${requestedScope.risk}`}
                                        className={
                                            isDestructive
                                                ? 'rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2'
                                                : 'px-1'
                                        }
                                    >
                                        <div className="flex min-w-0 items-center justify-between gap-3">
                                            <span
                                                className={
                                                    isDestructive
                                                        ? 'min-w-0 font-medium break-words text-destructive'
                                                        : 'min-w-0 break-words text-muted-foreground'
                                                }
                                            >
                                                {requestedScope.label}
                                            </span>
                                            {riskLabel !== null && (
                                                <Badge
                                                    className="shrink-0"
                                                    variant={
                                                        isDestructive
                                                            ? 'destructive'
                                                            : 'warning'
                                                    }
                                                >
                                                    {riskLabel}
                                                </Badge>
                                            )}
                                        </div>
                                        {isDestructive && (
                                            <p className="mt-1 text-xs text-destructive">
                                                Can permanently delete
                                                artifacts.
                                            </p>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>

                    {teams.length > 1 && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="team">Team</Label>
                            <select
                                id="team"
                                className="h-10 rounded-md border bg-background px-3 text-sm"
                                value={form.data.team}
                                onChange={(event) =>
                                    form.setData('team', event.target.value)
                                }
                            >
                                {teams.map((candidate) => (
                                    <option
                                        key={candidate.slug}
                                        value={candidate.slug}
                                    >
                                        {candidate.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    <p className="text-xs text-muted-foreground">
                        You can disconnect it any time in AI tool connections.
                    </p>

                    {Object.keys(form.errors).length > 0 && (
                        <Alert
                            variant="destructive"
                            className="flex flex-col gap-1"
                        >
                            {Object.entries(form.errors).map(
                                ([field, message]) => (
                                    <p key={field}>{message}</p>
                                ),
                            )}
                        </Alert>
                    )}

                    <form
                        className="flex flex-col gap-3 sm:flex-row"
                        onSubmit={(event: FormEvent) => {
                            event.preventDefault();
                            submit('approve');
                        }}
                    >
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => submit('deny')}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Allow access
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </>
    );
}

Authorize.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
