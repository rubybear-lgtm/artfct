import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

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

interface Props {
    clientId: string;
    userName: string;
    redirectUri: string;
    scope: string;
    state: string | null;
    codeChallenge: string;
    codeChallengeMethod: string;
    consentToken: string;
    team: Team;
    teams: Team[];
}

const scopeLabels: Record<string, string> = {
    'artifacts:read': 'Read artifacts and search your workspace',
    'artifacts:deploy': 'Deploy artifacts to your workspace',
    'artifacts:delete': 'Delete artifacts from your workspace',
    'collections:read': 'View approved artifact collections',
    'collections:write': 'Create collections and add artifacts',
    'usage:read': 'View usage and quota totals',
};

export default function Authorize({
    clientId,
    userName,
    redirectUri,
    scope,
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
    const requestedScopes = scope.split(' ').filter(Boolean);

    const submit = (decision: 'approve' | 'deny') => {
        form.transform((data) => ({ ...data, decision }));
        form.post('/oauth/authorize');
    };

    return (
        <>
            <Head title="Authorize MCP connection" />
            <Card>
                <CardHeader>
                    <CardTitle>Connect {clientId}</CardTitle>
                    <CardDescription>
                        This MCP client is requesting access to artfct. Review
                        what it can do before continuing.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-5">
                    <div className="grid gap-3 rounded-md border p-3 text-sm sm:grid-cols-2">
                        <div>
                            <div className="font-medium">Client</div>
                            <div className="mt-1 text-muted-foreground">
                                {clientId}
                            </div>
                        </div>
                        <div>
                            <div className="font-medium">Signed in as</div>
                            <div className="mt-1 text-muted-foreground">
                                {userName}
                            </div>
                        </div>
                        <div className="sm:col-span-2">
                            <div className="font-medium">Workspace</div>
                            <div className="mt-1 text-muted-foreground">
                                {team.name}{' '}
                                <span className="font-mono text-xs">
                                    ({team.slug})
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-md border p-3 text-sm">
                        <div className="font-medium">Requested access</div>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-muted-foreground">
                            {requestedScopes.map((requestedScope) => (
                                <li key={requestedScope}>
                                    {scopeLabels[requestedScope] ??
                                        requestedScope}
                                </li>
                            ))}
                        </ul>
                    </div>

                    {teams.length > 1 && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="team">Workspace</Label>
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
                        You can revoke this connection later from workspace
                        settings. artfct never shows the resulting credential
                        again.
                    </p>

                    <form
                        className="flex gap-3"
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
                            Deny
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
