import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';

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
    csrfToken: string;
    team: Team;
    teams: Team[];
}

const riskLabels: Record<Exclude<ScopeRisk, 'read'>, string> = {
    write: 'Can modify',
    destructive: 'Destructive',
};

/**
 * Consent must use a normal HTML form POST, not Inertia's XHR.
 *
 * Approve responds with a cross-origin redirect to the AI tool. Inertia turns
 * that into a 409 + X-Inertia-Location visit that writes sessionStorage before
 * navigating; in some embedded browsers (Cursor's OAuth window) that write
 * throws and the visit silently does nothing, so Allow access never leaves
 * this page (RUB-510). A native form gets a real 302 the browser follows.
 */
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
    csrfToken,
    team,
    teams,
}: Props) {
    const redirectHost = (() => {
        try {
            return new URL(redirectUri).host;
        } catch {
            return redirectUri;
        }
    })();

    const [selectedTeam, setSelectedTeam] = useState(team.slug);
    const selectedTeamName =
        teams.find((candidate) => candidate.slug === selectedTeam)?.name ??
        team.name;

    const pageErrors = usePage().props.errors ?? {};
    const errorEntries = Object.entries(pageErrors).flatMap(
        ([field, message]) =>
            typeof message === 'string' ? ([[field, message]] as const) : [],
    );

    return (
        <>
            <Head title="Connect an AI tool" />
            <Card>
                <CardHeader>
                    <CardTitle>
                        {clientName} wants to connect to {selectedTeamName}
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
                            <div className="font-medium">Returns to</div>
                            <div className="mt-1 break-all text-muted-foreground">
                                {redirectHost}
                            </div>
                        </div>
                        <div className="sm:col-span-2">
                            <div className="font-medium">Team</div>
                            <div className="mt-1 break-words text-muted-foreground">
                                {selectedTeamName}
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

                    <form
                        method="post"
                        action="/oauth/authorize"
                        className="flex flex-col gap-5"
                    >
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input
                            type="hidden"
                            name="client_id"
                            value={clientId}
                        />
                        <input
                            type="hidden"
                            name="redirect_uri"
                            value={redirectUri}
                        />
                        <input
                            type="hidden"
                            name="response_type"
                            value="code"
                        />
                        <input type="hidden" name="scope" value={scope} />
                        <input type="hidden" name="state" value={state ?? ''} />
                        <input
                            type="hidden"
                            name="code_challenge"
                            value={codeChallenge}
                        />
                        <input
                            type="hidden"
                            name="code_challenge_method"
                            value={codeChallengeMethod}
                        />
                        <input
                            type="hidden"
                            name="consent_token"
                            value={consentToken}
                        />
                        {teams.length <= 1 && (
                            <input
                                type="hidden"
                                name="team"
                                value={selectedTeam}
                            />
                        )}

                        {teams.length > 1 && (
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="team">Team</Label>
                                <select
                                    id="team"
                                    name="team"
                                    className="h-10 rounded-md border bg-background px-3 text-sm"
                                    value={selectedTeam}
                                    onChange={(event) =>
                                        setSelectedTeam(event.target.value)
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
                            You can disconnect it any time in AI tool
                            connections.
                        </p>

                        {errorEntries.length > 0 && (
                            <Alert
                                variant="destructive"
                                className="flex flex-col gap-1"
                            >
                                {errorEntries.map(([field, message]) => (
                                    <p key={field}>{message}</p>
                                ))}
                            </Alert>
                        )}

                        <div className="flex flex-col gap-3 sm:flex-row">
                            <Button
                                type="submit"
                                name="decision"
                                value="deny"
                                variant="outline"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                name="decision"
                                value="approve"
                            >
                                Allow access
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </>
    );
}

Authorize.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
