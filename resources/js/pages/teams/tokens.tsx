import { Head, router, usePage } from '@inertiajs/react';
import { Bot, Copy } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';

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
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import teamRoutes from '@/routes/teams';

interface Token {
    id: number;
    name: string;
    kind: 'api' | 'agent';
    role: string;
    scopes: string[];
    lastFour: string;
    expiresAt: string;
    lastUsedAt: string | null;
    revokedAt: string | null;
    status: 'active' | 'expired' | 'revoked';
}

interface CreatedToken {
    token: string;
    kind: 'api' | 'agent';
    mcpEndpoint: string;
    authorizationHeader: string;
}

interface BotOption {
    id: number;
    name: string;
    avatar: string | null;
}

interface Props {
    team: { slug: string; name: string };
    canCreate: boolean;
    mcpEndpoint: string;
    roles: { value: string; label: string }[];
    scopeOptions: string[];
    scopeLabels: Record<string, string>;
    defaultScopes: string[];
    bots: BotOption[];
    tokens: Token[];
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function formatDate(value: string | null): string {
    if (!value) {
        return 'Never';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

const statusVariant = {
    active: 'success',
    expired: 'warning',
    revoked: 'destructive',
} as const;

export default function Tokens({
    team,
    canCreate,
    mcpEndpoint,
    roles,
    scopeOptions,
    scopeLabels,
    defaultScopes,
    bots,
    tokens,
}: Props) {
    const [kind, setKind] = useState<'agent' | 'api'>(
        bots.length > 0 ? 'agent' : 'api',
    );
    const [name, setName] = useState('');
    const [botId, setBotId] = useState<number | ''>(bots[0]?.id ?? '');
    const [role, setRole] = useState(
        roles[roles.length - 1]?.value ?? 'member',
    );
    const [scopes, setScopes] = useState<string[]>(defaultScopes);
    const [ttlDays, setTtlDays] = useState('');
    const [busy, setBusy] = useState(false);
    const [created, setCreated] = useState<CreatedToken | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [revoking, setRevoking] = useState<Token | null>(null);
    const [revokingBusy, setRevokingBusy] = useState(false);
    usePage();

    const toggleScope = (scope: string, checked: boolean) => {
        setScopes((current) =>
            checked
                ? [...current, scope]
                : current.filter((item) => item !== scope),
        );
    };

    const revoke = () => {
        if (revoking === null) {
            return;
        }

        router.delete(
            teamRoutes.tokens.destroy.url({
                team: team.slug,
                token: revoking.id,
            }),
            {
                onStart: () => setRevokingBusy(true),
                onFinish: () => {
                    setRevokingBusy(false);
                    setRevoking(null);
                },
            },
        );
    };

    const create = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);

        const body: Record<string, unknown> = {
            name,
            kind,
        };

        if (kind === 'agent') {
            body.scopes = scopes;
            body.bot_id = botId;
        } else {
            body.role = role;
        }

        if (ttlDays.trim() !== '') {
            const days = Number(ttlDays);
            if (Number.isFinite(days) && days > 0) {
                body.ttl_seconds = Math.min(
                    Math.round(days * 86400),
                    31536000,
                );
            }
        }

        const response = await fetch(
            teamRoutes.tokens.store.url({ team: team.slug }),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(body),
            },
        );
        setBusy(false);

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            const message =
                payload.message ??
                payload.errors?.scopes?.[0] ??
                payload.errors?.name?.[0] ??
                'Could not create the token.';
            setError(message);

            return;
        }

        const payload = await response.json();
        setCreated({
            token: payload.token,
            kind: payload.kind,
            mcpEndpoint: payload.mcp_endpoint ?? mcpEndpoint,
            authorizationHeader:
                payload.authorization_header ?? `Bearer ${payload.token}`,
        });
        setName('');
        setTtlDays('');
        setScopes(defaultScopes);
        router.reload({ only: ['tokens'] });
    };

    return (
        <>
            <Head title="Agent & API tokens" />
            <PageHeader
                eyebrow={team.name}
                title="Agent & API tokens"
                description="Mint a token for an AI assistant that speaks Bearer headers, or for scripts that call the Artfct API."
            />

            <div className="flex flex-col gap-6">
                {canCreate && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Create a token</CardTitle>
                            <CardDescription>
                                Agent tokens belong to a bot teammate and
                                connect to{' '}
                                <code className="text-xs">{mcpEndpoint}</code>{' '}
                                with{' '}
                                <code className="text-xs">
                                    Authorization: Bearer …
                                </code>
                                . Add a bot on the Members page first. API
                                tokens stay for your own REST scripts.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={create}
                                className="flex flex-col gap-4"
                            >
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant={
                                            kind === 'agent'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        disabled={bots.length === 0}
                                        onClick={() => setKind('agent')}
                                    >
                                        <Bot className="size-4" /> Agent (MCP)
                                    </Button>
                                    <Button
                                        type="button"
                                        variant={
                                            kind === 'api'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        onClick={() => setKind('api')}
                                    >
                                        API / CI
                                    </Button>
                                </div>
                                {kind === 'agent' && bots.length === 0 && (
                                    <Alert>
                                        Add a bot on the Members page before
                                        minting an agent token.
                                    </Alert>
                                )}

                                <div className="flex flex-wrap items-end gap-3">
                                    <div className="flex min-w-56 flex-1 flex-col gap-1.5">
                                        <Label htmlFor="token-name">
                                            Name
                                        </Label>
                                        <Input
                                            id="token-name"
                                            placeholder={
                                                kind === 'agent'
                                                    ? 'Hoku MCP'
                                                    : 'ci-deploy'
                                            }
                                            value={name}
                                            onChange={(e) =>
                                                setName(e.target.value)
                                            }
                                            required
                                        />
                                    </div>
                                    {kind === 'agent' && bots.length > 0 && (
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="token-bot">
                                                Bot
                                            </Label>
                                            <select
                                                id="token-bot"
                                                className="h-9 rounded-md border border-border bg-transparent px-2 text-sm max-md:min-h-[44px] max-md:text-base"
                                                value={botId}
                                                onChange={(e) =>
                                                    setBotId(
                                                        Number(e.target.value),
                                                    )
                                                }
                                                required
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
                                    {kind === 'api' && (
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="token-role">
                                                Role
                                            </Label>
                                            <select
                                                id="token-role"
                                                className="h-9 rounded-md border border-border bg-transparent px-2 text-sm max-md:min-h-[44px] max-md:text-base"
                                                value={role}
                                                onChange={(e) =>
                                                    setRole(e.target.value)
                                                }
                                            >
                                                {roles.map((item) => (
                                                    <option
                                                        key={item.value}
                                                        value={item.value}
                                                    >
                                                        {item.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    )}
                                    <div className="flex w-32 flex-col gap-1.5">
                                        <Label htmlFor="token-ttl">
                                            Expires (days)
                                        </Label>
                                        <Input
                                            id="token-ttl"
                                            type="number"
                                            min={1}
                                            max={365}
                                            placeholder="365"
                                            value={ttlDays}
                                            onChange={(e) =>
                                                setTtlDays(e.target.value)
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            busy ||
                                            name === '' ||
                                            (kind === 'agent' &&
                                                (scopes.length === 0 ||
                                                    botId === ''))
                                        }
                                    >
                                        Create token
                                    </Button>
                                </div>

                                {kind === 'agent' && (
                                    <fieldset className="flex flex-col gap-2">
                                        <legend className="text-sm font-medium">
                                            Permissions
                                        </legend>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {scopeOptions.map((scope) => (
                                                <label
                                                    key={scope}
                                                    className="flex items-start gap-2 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        className="mt-1"
                                                        checked={scopes.includes(
                                                            scope,
                                                        )}
                                                        onChange={(e) =>
                                                            toggleScope(
                                                                scope,
                                                                e.target
                                                                    .checked,
                                                            )
                                                        }
                                                    />
                                                    <span>
                                                        <span className="font-medium">
                                                            {scope}
                                                        </span>
                                                        <span className="block text-muted-foreground">
                                                            {scopeLabels[
                                                                scope
                                                            ] ?? scope}
                                                        </span>
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>
                                )}
                            </form>
                            {error && (
                                <Alert className="mt-3" variant="destructive">
                                    {error}
                                </Alert>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Tokens</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {tokens.length === 0 ? (
                            <EmptyState title="No tokens yet." />
                        ) : (
                            <Table>
                                <thead>
                                    <tr>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Kind</TableHead>
                                        <TableHead>Ends in</TableHead>
                                        <TableHead>Last used</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead />
                                    </tr>
                                </thead>
                                <tbody>
                                    {tokens.map((token) => (
                                        <TableRow key={token.id}>
                                            <TableCell className="min-w-0 break-words">
                                                <div className="flex items-center gap-1.5">
                                                    {token.kind === 'agent' && (
                                                        <Bot className="size-3.5 shrink-0 text-muted-foreground" />
                                                    )}
                                                    {token.name}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {token.kind === 'agent'
                                                    ? 'Agent'
                                                    : token.role}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                …{token.lastFour}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {formatDate(token.lastUsedAt)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        statusVariant[
                                                            token.status
                                                        ]
                                                    }
                                                >
                                                    {token.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {token.status === 'active' &&
                                                    canCreate && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setRevoking(
                                                                    token,
                                                                )
                                                            }
                                                        >
                                                            Revoke
                                                        </Button>
                                                    )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog
                open={created !== null}
                onOpenChange={(open) => !open && setCreated(null)}
            >
                <DialogContent>
                    <DialogTitle>Copy your token now</DialogTitle>
                    <DialogDescription>
                        It will not be shown again.
                        {created?.kind === 'agent'
                            ? ' Add it as an Authorization header on your MCP client.'
                            : ''}
                    </DialogDescription>
                    <code className="mt-4 block max-h-32 overflow-auto rounded-md bg-muted p-3 text-xs break-all">
                        {created?.token}
                    </code>
                    {created?.kind === 'agent' && (
                        <div className="mt-3 space-y-2 text-sm">
                            <p className="text-muted-foreground">
                                MCP URL
                            </p>
                            <code className="block overflow-auto rounded-md bg-muted p-2 text-xs break-all">
                                {created.mcpEndpoint}
                            </code>
                            <p className="text-muted-foreground">Header</p>
                            <code className="block overflow-auto rounded-md bg-muted p-2 text-xs break-all">
                                Authorization: {created.authorizationHeader}
                            </code>
                        </div>
                    )}
                    <div className="mt-4 flex justify-end gap-2">
                        <Button
                            variant="outline"
                            onClick={async () => {
                                await navigator.clipboard.writeText(
                                    created?.token ?? '',
                                );
                                toast.success('Token copied.');
                            }}
                        >
                            <Copy className="size-4" /> Copy
                        </Button>
                        <DialogClose asChild>
                            <Button>Done</Button>
                        </DialogClose>
                    </div>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                title="Revoke this token?"
                description="Anything using this token stops working within a minute. This cannot be undone."
                confirmLabel="Revoke token"
                onConfirm={revoke}
                processing={revokingBusy}
            />
        </>
    );
}

Tokens.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
