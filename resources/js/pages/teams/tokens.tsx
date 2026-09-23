import { Head, router, usePage } from '@inertiajs/react';
import { Copy } from 'lucide-react';
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
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import teamRoutes from '@/routes/teams';

interface Token {
    id: number;
    name: string;
    role: string;
    lastFour: string;
    expiresAt: string;
    revokedAt: string | null;
    status: 'active' | 'expired' | 'revoked';
}

interface Props {
    team: { slug: string; name: string };
    canCreate: boolean;
    roles: { value: string; label: string }[];
    tokens: Token[];
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const statusVariant = {
    active: 'success',
    expired: 'warning',
    revoked: 'destructive',
} as const;

export default function Tokens({ team, canCreate, roles, tokens }: Props) {
    const [name, setName] = useState('');
    const [role, setRole] = useState(
        roles[roles.length - 1]?.value ?? 'member',
    );
    const [busy, setBusy] = useState(false);
    const [created, setCreated] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    usePage();

    const create = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        const response = await fetch(
            teamRoutes.tokens.store.url({ team: team.slug }),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ name, role }),
            },
        );
        setBusy(false);

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            setError(body.message ?? 'Could not create the token.');

            return;
        }

        setCreated((await response.json()).token);
        setName('');
        router.reload({ only: ['tokens'] });
    };

    return (
        <>
            <Head title="API tokens" />
            <h1 className="mb-6 text-2xl font-semibold">API tokens</h1>

            <div className="flex flex-col gap-6">
                {canCreate && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Create a token</CardTitle>
                            <CardDescription>
                                Lets an AI tool share to and read from this
                                team. The value is shown once.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={create}
                                className="flex flex-wrap items-end gap-3"
                            >
                                <div className="flex min-w-56 flex-1 flex-col gap-1.5">
                                    <Label htmlFor="token-name">Name</Label>
                                    <Input
                                        id="token-name"
                                        placeholder="ci-deploy"
                                        value={name}
                                        onChange={(e) =>
                                            setName(e.target.value)
                                        }
                                        required
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="token-role">Role</Label>
                                    <select
                                        id="token-role"
                                        className="h-9 rounded-md border border-border bg-transparent px-2 text-sm"
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
                                <Button
                                    type="submit"
                                    disabled={busy || name === ''}
                                >
                                    Create token
                                </Button>
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
                                        <TableHead>Role</TableHead>
                                        <TableHead>Ends in</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead />
                                    </tr>
                                </thead>
                                <tbody>
                                    {tokens.map((token) => (
                                        <TableRow key={token.id}>
                                            <TableCell>{token.name}</TableCell>
                                            <TableCell>{token.role}</TableCell>
                                            <TableCell className="tabular-nums">
                                                …{token.lastFour}
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
                                                                router.delete(
                                                                    teamRoutes.tokens.destroy.url(
                                                                        {
                                                                            team: team.slug,
                                                                            token: token.id,
                                                                        },
                                                                    ),
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
                    </DialogDescription>
                    <code className="mt-4 block max-h-32 overflow-auto rounded-md bg-muted p-3 text-xs break-all">
                        {created}
                    </code>
                    <div className="mt-4 flex justify-end gap-2">
                        <Button
                            variant="outline"
                            onClick={async () => {
                                await navigator.clipboard.writeText(
                                    created ?? '',
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
        </>
    );
}

Tokens.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
