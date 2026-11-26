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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface Team {
    id: string;
    name: string;
    slug: string;
}

interface BotOption {
    id: number;
    name: string;
}

interface RequestedScope {
    value: string;
    label: string;
    risk: ScopeRisk;
}

type ScopeRisk = 'read' | 'write' | 'destructive';

type IdentityMode = 'human' | 'create' | 'link';

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
    team: Team | null;
    requiresTeamChoice: boolean;
    teams: Team[];
    canInstallAsBot: boolean;
    bots: BotOption[];
    connectedBots: BotOption[];
    lastUsedBotId: number | null;
    preselectedBotMode: IdentityMode;
    preselectedBotId: number | null;
}

const riskLabels: Record<Exclude<ScopeRisk, 'read'>, string> = {
    write: 'Can modify',
    destructive: 'Destructive',
};

function initialBotId(
    preselectedBotId: number | null,
    connectedBots: BotOption[],
    bots: BotOption[],
): string {
    if (preselectedBotId !== null) {
        return String(preselectedBotId);
    }

    if (connectedBots[0] !== undefined) {
        return String(connectedBots[0].id);
    }

    if (bots[0] !== undefined) {
        return String(bots[0].id);
    }

    return '';
}

/**
 * Remount when the authorization request identity changes. Inertia reuses this
 * page component across /oauth/authorize visits that only differ by query
 * string; local state such as the selected team would otherwise carry over
 * from the abandoned request (RUB-510).
 */
export default function Authorize(props: Props) {
    return <AuthorizeForm key={props.consentToken} {...props} />;
}

/**
 * Consent must use a normal HTML form POST, not Inertia's XHR.
 *
 * Approve responds with a cross-origin redirect to the AI tool. Inertia turns
 * that into a 409 + X-Inertia-Location visit that writes sessionStorage before
 * navigating; in some embedded browsers (Cursor's OAuth window) that write
 * throws and the visit silently does nothing, so Allow access never leaves
 * this page (RUB-510). A native form gets a real 302 the browser follows.
 */
function AuthorizeForm({
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
    requiresTeamChoice,
    teams,
    canInstallAsBot,
    bots,
    connectedBots,
    lastUsedBotId,
    preselectedBotMode,
    preselectedBotId,
}: Props) {
    const redirectHost = (() => {
        try {
            return new URL(redirectUri).host || redirectUri;
        } catch {
            return redirectUri;
        }
    })();

    const [selectedTeam, setSelectedTeam] = useState(
        requiresTeamChoice ? '' : (team?.slug ?? ''),
    );
    // A2: server sends preselectedBotMode/Id (link + last-used when any
    // connected bots exist). Never auto-approve — user still clicks Allow.
    const [identityMode, setIdentityMode] =
        useState<IdentityMode>(preselectedBotMode);
    const [botName, setBotName] = useState('');
    const [botId, setBotId] = useState(() =>
        initialBotId(preselectedBotId ?? lastUsedBotId, connectedBots, bots),
    );
    const selectedTeamName =
        teams.find((candidate) => candidate.slug === selectedTeam)?.name ??
        team?.name;

    const pageErrors = usePage().props.errors ?? {};
    const errorEntries = Object.entries(pageErrors).flatMap(
        ([field, message]) =>
            typeof message === 'string' ? ([[field, message]] as const) : [],
    );

    const connectingAsBot =
        canInstallAsBot &&
        (identityMode === 'create' || identityMode === 'link');

    const trimmedBotName = botName.trim();
    const createReusesExisting =
        identityMode === 'create' &&
        trimmedBotName !== '' &&
        bots.some((bot) => bot.name === trimmedBotName);

    return (
        <>
            <Head title="Connect an AI tool" />
            <Card>
                <CardHeader>
                    <CardTitle>
                        {selectedTeam === ''
                            ? `${clientName} wants to connect`
                            : `${clientName} wants to connect to ${selectedTeamName}`}
                    </CardTitle>
                    <CardDescription>
                        {selectedTeam === ''
                            ? "It will be able to do the things listed below with the workspace's shared work. Choose a workspace below, and only continue if you started this from your AI tool."
                            : "It will be able to do the things listed below with your team's shared work. Only continue if you started this from your AI tool."}
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
                            <div className="font-medium">Workspace</div>
                            <div className="mt-1 break-words text-muted-foreground">
                                {selectedTeam === ''
                                    ? 'Choose below'
                                    : selectedTeamName}
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
                                <Label htmlFor="team">Workspace</Label>
                                <select
                                    id="team"
                                    name="team"
                                    className="h-10 rounded-md border bg-background px-3 text-sm"
                                    value={selectedTeam}
                                    onChange={(event) =>
                                        setSelectedTeam(event.target.value)
                                    }
                                    required
                                >
                                    <option value="">
                                        Select a workspace…
                                    </option>
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

                        {canInstallAsBot && (
                            <fieldset
                                className="flex flex-col gap-3 rounded-md border p-3"
                                data-testid="bot-install-options"
                            >
                                <legend className="px-1 text-sm font-medium">
                                    Connect as
                                </legend>

                                {connectedBots.length > 0 && (
                                    <div
                                        className="rounded-md border border-dashed p-3 text-sm"
                                        data-testid="connected-bots-list"
                                    >
                                        <div className="font-medium">
                                            Already connected through this app
                                        </div>
                                        <ul className="mt-2 list-disc space-y-1 pl-5 text-muted-foreground">
                                            {connectedBots.map((bot) => (
                                                <li key={bot.id}>{bot.name}</li>
                                            ))}
                                        </ul>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {connectedBots.length === 1
                                                ? 'Reconnecting reuses that bot unless you create a new bot teammate below.'
                                                : 'Choose which bot identity this connection should use. Nothing is approved until you allow access.'}
                                        </p>
                                    </div>
                                )}

                                <label className="flex items-start gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="bot_mode"
                                        value="human"
                                        className="mt-1"
                                        checked={identityMode === 'human'}
                                        onChange={() =>
                                            setIdentityMode('human')
                                        }
                                        data-testid="bot-mode-human"
                                    />
                                    <span>
                                        <span className="font-medium">
                                            Yourself
                                        </span>
                                        <span className="mt-0.5 block text-muted-foreground">
                                            Actions are attributed to {userName}
                                            .
                                        </span>
                                    </span>
                                </label>
                                <div className="flex flex-col gap-2">
                                    <label className="flex items-start gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="bot_mode"
                                            value="create"
                                            className="mt-1"
                                            checked={identityMode === 'create'}
                                            onChange={() =>
                                                setIdentityMode('create')
                                            }
                                            data-testid="bot-mode-create"
                                        />
                                        <span>
                                            <span className="font-medium">
                                                A new bot teammate
                                            </span>
                                            <span className="mt-0.5 block text-muted-foreground">
                                                Free seat, no email. A new name
                                                creates a new identity on this
                                                app; an existing bot name reuses
                                                that bot.
                                            </span>
                                        </span>
                                    </label>
                                    {identityMode === 'create' && (
                                        <div className="flex flex-col gap-1.5 pl-6">
                                            <Label htmlFor="bot_name">
                                                Bot name
                                            </Label>
                                            <Input
                                                id="bot_name"
                                                name="bot_name"
                                                placeholder="Grok Bot"
                                                value={botName}
                                                onChange={(event) =>
                                                    setBotName(
                                                        event.target.value,
                                                    )
                                                }
                                                required
                                                maxLength={80}
                                                data-testid="bot-name-input"
                                            />
                                            <p
                                                className="text-xs text-muted-foreground"
                                                data-testid="create-identity-hint"
                                            >
                                                {createReusesExisting
                                                    ? `This reuses the existing bot named ${trimmedBotName}.`
                                                    : 'This will create a new bot identity for this app.'}
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {bots.length > 0 && (
                                    <div className="flex flex-col gap-2">
                                        <label className="flex items-start gap-2 text-sm">
                                            <input
                                                type="radio"
                                                name="bot_mode"
                                                value="link"
                                                className="mt-1"
                                                checked={
                                                    identityMode === 'link'
                                                }
                                                onChange={() =>
                                                    setIdentityMode('link')
                                                }
                                                data-testid="bot-mode-link"
                                            />
                                            <span>
                                                <span className="font-medium">
                                                    An existing bot teammate
                                                </span>
                                                <span className="mt-0.5 block text-muted-foreground">
                                                    Link this app to a bot
                                                    already on the team without
                                                    replacing other bots on the
                                                    same app.
                                                </span>
                                            </span>
                                        </label>
                                        {identityMode === 'link' && (
                                            <div className="flex flex-col gap-1.5 pl-6">
                                                <Label htmlFor="bot_id">
                                                    Bot
                                                </Label>
                                                <select
                                                    id="bot_id"
                                                    name="bot_id"
                                                    className="h-10 rounded-md border bg-background px-3 text-sm"
                                                    value={botId}
                                                    onChange={(event) =>
                                                        setBotId(
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                    data-testid="bot-link-select"
                                                >
                                                    {bots.map((bot) => (
                                                        <option
                                                            key={bot.id}
                                                            value={bot.id}
                                                        >
                                                            {bot.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </fieldset>
                        )}

                        {!canInstallAsBot && (
                            <input
                                type="hidden"
                                name="bot_mode"
                                value="human"
                            />
                        )}

                        <p className="text-xs text-muted-foreground">
                            {connectingAsBot
                                ? 'The connection will act as that bot, not as you. You can disconnect it any time in AI tool connections.'
                                : 'You can disconnect it any time in AI tool connections.'}
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
                                formNoValidate
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
