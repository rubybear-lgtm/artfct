import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    Cable,
    Check,
    CheckCircle2,
    Clock3,
    Copy,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
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
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { docs } from '@/routes';
import teamRoutes from '@/routes/teams';

interface Connection {
    id: string;
    name: string;
    clientName: string;
    clientVersion: string | null;
    transport: string;
    scopes: string[];
    createdAt: string | null;
    lastUsedAt: string | null;
    expiresAt: string | null;
    revokedAt: string | null;
    createdBy: string | null;
    canRevoke: boolean;
}

interface Activity {
    tool: string;
    clientName: string | null;
    outcome: string;
    latencyMs: number;
    createdAt: string | null;
    requestId: string | null;
}

interface UsageByTool {
    tool: string;
    calls: number;
    successfulCalls: number;
    failedCalls: number;
    averageLatencyMs: number;
}

interface Usage {
    periodDays: number;
    since: string;
    calls: number;
    successfulCalls: number;
    failedCalls: number;
    successRate: number;
    averageLatencyMs: number;
    byTool: UsageByTool[];
}

interface Props {
    team: { slug: string; name: string };
    mcpEndpoint: string;
    oauthMetadataUrl: string;
    connections: Connection[];
    activity: Activity[];
    usage: Usage;
    scopeOptions: string[];
    defaultScopes: string[];
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

function statusFor(connection: Connection): 'active' | 'expired' | 'revoked' {
    if (connection.revokedAt) {
        return 'revoked';
    }

    if (
        connection.expiresAt &&
        new Date(connection.expiresAt).getTime() <= Date.now()
    ) {
        return 'expired';
    }

    return 'active';
}

const statusVariant = {
    active: 'success',
    expired: 'warning',
    revoked: 'destructive',
} as const;

export default function McpConnections({
    team,
    mcpEndpoint,
    oauthMetadataUrl,
    connections,
    activity,
    usage,
    scopeOptions,
    defaultScopes,
}: Props) {
    const [copied, setCopied] = useState<string | null>(null);
    const [pendingRevokeId, setPendingRevokeId] = useState<string | null>(null);
    const [pendingReauthorizeId, setPendingReauthorizeId] = useState<
        string | null
    >(null);
    const cliCommand = `artfct login --oauth --organization ${team.slug}`;
    const createForm = useForm({
        client_name: 'custom MCP client',
        scopes: defaultScopes,
    });

    const copy = (value: string, key: string) => {
        if (!navigator.clipboard) {
            return;
        }

        void navigator.clipboard
            .writeText(value)
            .then(() => {
                setCopied(key);
                window.setTimeout(() => setCopied(null), 1500);
            })
            .catch(() => undefined);
    };

    const revoke = (connection: Connection) => {
        setPendingRevokeId(null);
        router.delete(
            teamRoutes.mcpConnections.destroy.url({
                team: team.slug,
                connection: connection.id,
            }),
        );
    };

    const reauthorize = (connection: Connection) => {
        setPendingReauthorizeId(null);
        router.post(
            teamRoutes.mcpConnections.reauthorize.url({
                team: team.slug,
                connection: connection.id,
            }),
        );
    };

    const submitCreate = (event: FormEvent) => {
        event.preventDefault();
        createForm.post(
            teamRoutes.mcpConnections.store.url({ team: team.slug }),
            {
                preserveScroll: true,
                onSuccess: () => createForm.reset(),
            },
        );
    };

    const toggleScope = (scope: string, checked: boolean) => {
        createForm.setData(
            'scopes',
            checked
                ? [...createForm.data.scopes, scope]
                : createForm.data.scopes.filter((item) => item !== scope),
        );
    };

    return (
        <>
            <Head title="MCP connections" />
            <h1 className="mb-1 text-2xl font-semibold">MCP connections</h1>
            <p className="mb-6 text-sm text-muted-foreground">
                Review the agents connected to {team.name}. Credentials are
                never displayed here.
            </p>

            <div className="flex flex-col gap-6">
                <Alert>
                    <ShieldCheck className="size-4" />
                    Revoke a connection when an agent is lost, decommissioned,
                    or no longer needs access. The connection metadata remains
                    available for audit purposes.
                </Alert>

                <Card>
                    <CardHeader>
                        <CardTitle>Connect an agent</CardTitle>
                        <CardDescription>
                            Choose local CLI mode for coding agents on this
                            machine, or use the hosted endpoint for clients that
                            support Streamable HTTP and OAuth.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        <div className="rounded-lg border p-4">
                            <p className="font-medium">Local CLI</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Install once, add artfct to your agent, then
                                complete browser sign-in for this workspace.
                            </p>
                            <code className="mt-3 block rounded-md bg-muted p-3 font-mono text-xs leading-6">
                                curl -fsSL https://artfct.dev/install.sh | sh
                                <br />
                                artfct setup
                                <br />
                                {cliCommand}
                            </code>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-3"
                                onClick={() => copy(cliCommand, 'cli')}
                            >
                                {copied === 'cli' ? (
                                    <Check className="size-3.5" />
                                ) : (
                                    <Copy className="size-3.5" />
                                )}
                                {copied === 'cli'
                                    ? 'Copied'
                                    : 'Copy sign-in command'}
                            </Button>
                        </div>
                        <div className="rounded-lg border p-4">
                            <p className="font-medium">Hosted MCP</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Add this URL to an MCP client that supports
                                OAuth discovery. No token needs to be copied.
                            </p>
                            <code className="mt-3 block rounded-md bg-muted p-3 font-mono text-xs break-all">
                                {mcpEndpoint}
                            </code>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-3"
                                onClick={() => copy(mcpEndpoint, 'endpoint')}
                            >
                                {copied === 'endpoint' ? (
                                    <Check className="size-3.5" />
                                ) : (
                                    <Copy className="size-3.5" />
                                )}
                                {copied === 'endpoint'
                                    ? 'Copied'
                                    : 'Copy endpoint'}
                            </Button>
                            <p className="mt-3 text-xs text-muted-foreground">
                                OAuth discovery:{' '}
                                <a
                                    href={oauthMetadataUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="break-all underline underline-offset-2"
                                >
                                    {oauthMetadataUrl}
                                </a>
                            </p>
                        </div>
                        <div className="rounded-lg border border-dashed p-4 md:col-span-2">
                            <p className="font-medium">
                                Least-privilege access
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                OAuth clients request scopes during sign-in.
                                Start with only the capabilities the agent
                                needs; access can be revoked below.
                            </p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {[
                                    'artifacts:read',
                                    'artifacts:deploy',
                                    'collections:read',
                                    'usage:read',
                                ].map((scope) => (
                                    <Badge key={scope} variant="outline">
                                        {scope}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-3 md:col-span-2">
                            <Button asChild>
                                <Link href={`${docs.url()}#cli`}>
                                    Open setup guide
                                </Link>
                            </Button>
                            <span className="text-xs text-muted-foreground">
                                Access is scoped to this workspace and can be
                                revoked below.
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-start justify-between gap-4 pt-5">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Tool calls
                                </p>
                                <p className="mt-1 text-2xl font-semibold">
                                    {usage.calls.toLocaleString()}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Last {usage.periodDays} days
                                </p>
                            </div>
                            <Activity className="size-5 text-muted-foreground" />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-start justify-between gap-4 pt-5">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Success rate
                                </p>
                                <p className="mt-1 text-2xl font-semibold">
                                    {usage.successRate}%
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {usage.successfulCalls.toLocaleString()}{' '}
                                    successful
                                </p>
                            </div>
                            <CheckCircle2 className="size-5 text-muted-foreground" />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-start justify-between gap-4 pt-5">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Errors
                                </p>
                                <p className="mt-1 text-2xl font-semibold">
                                    {usage.failedCalls.toLocaleString()}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Across all tools
                                </p>
                            </div>
                            <ShieldCheck className="size-5 text-muted-foreground" />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-start justify-between gap-4 pt-5">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Average latency
                                </p>
                                <p className="mt-1 text-2xl font-semibold">
                                    {usage.averageLatencyMs} ms
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Completed tool calls
                                </p>
                            </div>
                            <Clock3 className="size-5 text-muted-foreground" />
                        </CardContent>
                    </Card>
                </div>

                {usage.byTool.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Usage by tool</CardTitle>
                            <CardDescription>
                                A rolling view of how connected agents use
                                artfct.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <Table>
                                    <thead>
                                        <tr>
                                            <TableHead>Tool</TableHead>
                                            <TableHead>Calls</TableHead>
                                            <TableHead>Success rate</TableHead>
                                            <TableHead>
                                                Average latency
                                            </TableHead>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {usage.byTool.map((tool) => (
                                            <TableRow key={tool.tool}>
                                                <TableCell className="font-mono text-xs">
                                                    {tool.tool}
                                                </TableCell>
                                                <TableCell>
                                                    {tool.calls.toLocaleString()}
                                                </TableCell>
                                                <TableCell>
                                                    {tool.calls === 0
                                                        ? '0%'
                                                        : `${((tool.successfulCalls / tool.calls) * 100).toFixed(1)}%`}
                                                </TableCell>
                                                <TableCell>
                                                    {tool.averageLatencyMs} ms
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </tbody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Start a connection</CardTitle>
                        <CardDescription>
                            Register a client against {team.name}. Scopes
                            default to read-only and can never exceed your team
                            role.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submitCreate}
                            className="flex flex-col gap-4"
                        >
                            <div className="flex max-w-sm flex-col gap-1.5">
                                <Label htmlFor="client-name">Client</Label>
                                <Input
                                    id="client-name"
                                    name="client_name"
                                    value={createForm.data.client_name}
                                    onChange={(event) =>
                                        createForm.setData(
                                            'client_name',
                                            event.target.value,
                                        )
                                    }
                                />
                                {createForm.errors.client_name && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {createForm.errors.client_name}
                                    </p>
                                )}
                            </div>
                            <fieldset className="flex flex-col gap-2">
                                <legend className="text-sm font-medium">
                                    Scopes
                                </legend>
                                <div className="flex flex-wrap gap-x-6 gap-y-2">
                                    {scopeOptions.map((scope) => (
                                        <label
                                            key={scope}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                name="scopes"
                                                value={scope}
                                                checked={createForm.data.scopes.includes(
                                                    scope,
                                                )}
                                                onChange={(event) =>
                                                    toggleScope(
                                                        scope,
                                                        event.target.checked,
                                                    )
                                                }
                                                className="size-4 accent-primary"
                                            />
                                            <span className="font-mono text-xs">
                                                {scope}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                {createForm.errors.scopes && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {createForm.errors.scopes}
                                    </p>
                                )}
                            </fieldset>
                            <div>
                                <Button
                                    type="submit"
                                    disabled={createForm.processing}
                                >
                                    {createForm.processing
                                        ? 'Starting...'
                                        : 'Start connection'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Cable className="size-5" />
                            Connected agents
                        </CardTitle>
                        <CardDescription>
                            {connections.length === 0
                                ? 'No MCP clients have connected yet.'
                                : `${connections.length} connection${connections.length === 1 ? '' : 's'} registered`}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {connections.length === 0 ? (
                            <EmptyState
                                title="Nothing connected"
                                description="Use the connection guide above to add a local or hosted MCP client."
                            >
                                <Button
                                    asChild
                                    variant="outline"
                                    className="mt-4"
                                >
                                    <Link
                                        href={teamRoutes.tokens.index.url({
                                            team: team.slug,
                                        })}
                                    >
                                        Manage API tokens
                                    </Link>
                                </Button>
                            </EmptyState>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <thead>
                                        <tr>
                                            <TableHead>Client</TableHead>
                                            <TableHead>Transport</TableHead>
                                            <TableHead>Scopes</TableHead>
                                            <TableHead>Last used</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {connections.map((connection) => {
                                            const status =
                                                statusFor(connection);

                                            return (
                                                <TableRow key={connection.id}>
                                                    <TableCell>
                                                        <div className="font-medium">
                                                            {connection.name}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {
                                                                connection.clientName
                                                            }
                                                            {connection.clientVersion
                                                                ? ` ${connection.clientVersion}`
                                                                : ''}
                                                            {connection.createdBy
                                                                ? ` · ${connection.createdBy}`
                                                                : ''}
                                                        </div>
                                                        {status !==
                                                            'active' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="mt-2"
                                                                onClick={() =>
                                                                    copy(
                                                                        cliCommand,
                                                                        `reconnect-${connection.id}`,
                                                                    )
                                                                }
                                                                aria-label={`Copy reconnect command for ${connection.name}`}
                                                            >
                                                                {copied ===
                                                                `reconnect-${connection.id}` ? (
                                                                    <>
                                                                        <Check className="size-3.5" />
                                                                        Copied
                                                                    </>
                                                                ) : (
                                                                    'Reconnect'
                                                                )}
                                                            </Button>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs">
                                                        {connection.transport}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex max-w-64 flex-wrap gap-1">
                                                            {connection.scopes.map(
                                                                (scope) => (
                                                                    <Badge
                                                                        key={
                                                                            scope
                                                                        }
                                                                        variant="outline"
                                                                    >
                                                                        {scope}
                                                                    </Badge>
                                                                ),
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-sm whitespace-nowrap">
                                                        {formatDate(
                                                            connection.lastUsedAt,
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge
                                                                variant={
                                                                    statusVariant[
                                                                        status
                                                                    ]
                                                                }
                                                            >
                                                                {status}
                                                            </Badge>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            {status ===
                                                                'active' &&
                                                                connection.canRevoke && (
                                                                    <>
                                                                        {pendingReauthorizeId ===
                                                                        connection.id ? (
                                                                            <>
                                                                                <Button
                                                                                    variant="ghost"
                                                                                    size="sm"
                                                                                    onClick={() =>
                                                                                        setPendingReauthorizeId(
                                                                                            null,
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    Cancel
                                                                                </Button>
                                                                                <Button
                                                                                    variant="outline"
                                                                                    size="sm"
                                                                                    onClick={() =>
                                                                                        reauthorize(
                                                                                            connection,
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    Confirm
                                                                                    reauthorize?
                                                                                </Button>
                                                                            </>
                                                                        ) : (
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    setPendingReauthorizeId(
                                                                                        connection.id,
                                                                                    )
                                                                                }
                                                                            >
                                                                                Reauthorize
                                                                            </Button>
                                                                        )}
                                                                        {pendingRevokeId ===
                                                                        connection.id ? (
                                                                            <>
                                                                                <Button
                                                                                    variant="ghost"
                                                                                    size="sm"
                                                                                    onClick={() =>
                                                                                        setPendingRevokeId(
                                                                                            null,
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    Cancel
                                                                                </Button>
                                                                                <Button
                                                                                    variant="destructive"
                                                                                    size="sm"
                                                                                    onClick={() =>
                                                                                        revoke(
                                                                                            connection,
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    Confirm
                                                                                    revoke?
                                                                                </Button>
                                                                            </>
                                                                        ) : (
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    setPendingRevokeId(
                                                                                        connection.id,
                                                                                    )
                                                                                }
                                                                            >
                                                                                Revoke
                                                                            </Button>
                                                                        )}
                                                                    </>
                                                                )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </tbody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent activity</CardTitle>
                        <CardDescription>
                            Tool calls are recorded without storing prompts,
                            HTML, or credentials.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {activity.length === 0 ? (
                            <EmptyState title="No MCP tool calls yet." />
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <thead>
                                        <tr>
                                            <TableHead>Tool</TableHead>
                                            <TableHead>Client</TableHead>
                                            <TableHead>Result</TableHead>
                                            <TableHead>Latency</TableHead>
                                            <TableHead>When</TableHead>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {activity.map((entry, index) => (
                                            <TableRow
                                                key={`${entry.requestId ?? entry.tool}-${index}`}
                                            >
                                                <TableCell className="font-mono text-xs">
                                                    {entry.tool}
                                                </TableCell>
                                                <TableCell>
                                                    {entry.clientName ??
                                                        'Unknown client'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            entry.outcome ===
                                                            'success'
                                                                ? 'success'
                                                                : 'destructive'
                                                        }
                                                    >
                                                        {entry.outcome}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {entry.latencyMs} ms
                                                </TableCell>
                                                <TableCell className="text-sm whitespace-nowrap">
                                                    {formatDate(
                                                        entry.createdAt,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </tbody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

McpConnections.layout = (page: React.ReactNode) => (
    <AppLayout>{page}</AppLayout>
);
