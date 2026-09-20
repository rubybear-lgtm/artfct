import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface AuditRow {
    id: number;
    type: string;
    actor: string;
    actorName: string;
    target: string;
    outcome: string;
    ip: string;
    at: string;
}

interface Props {
    team: { slug: string; name: string };
    events: {
        data: AuditRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    types: string[];
    filters: {
        type: string | null;
        actor: string | null;
        from: string | null;
        to: string | null;
    };
    canExport: boolean;
}

export default function Audit({
    team,
    events,
    types,
    filters,
    canExport,
}: Props) {
    const base = `/settings/teams/${team.slug}/audit`;
    const [form, setForm] = useState({
        type: filters.type ?? '',
        actor: filters.actor ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const apply = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            base,
            Object.fromEntries(
                Object.entries(form).filter(([, v]) => v !== ''),
            ),
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title="Audit log" />
            <h1 className="mb-1 text-2xl font-semibold">Audit log</h1>
            <p className="mb-6 text-sm text-muted-foreground">
                Every member, role, token, retention and export action for{' '}
                {team.name}. Entries cannot be edited or deleted.
            </p>

            <form
                onSubmit={apply}
                className="mb-4 flex flex-wrap items-end gap-3 text-sm"
            >
                <label className="flex flex-col gap-1">
                    Event
                    <select
                        className="rounded-md border border-border bg-background px-2 py-2"
                        value={form.type}
                        onChange={(e) =>
                            setForm({ ...form, type: e.target.value })
                        }
                    >
                        <option value="">All events</option>
                        {types.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1">
                    Actor id
                    <Input
                        value={form.actor}
                        onChange={(e) =>
                            setForm({ ...form, actor: e.target.value })
                        }
                    />
                </label>
                <label className="flex flex-col gap-1">
                    From
                    <Input
                        type="date"
                        value={form.from}
                        onChange={(e) =>
                            setForm({ ...form, from: e.target.value })
                        }
                    />
                </label>
                <label className="flex flex-col gap-1">
                    To
                    <Input
                        type="date"
                        value={form.to}
                        onChange={(e) =>
                            setForm({ ...form, to: e.target.value })
                        }
                    />
                </label>
                <Button type="submit">Filter</Button>
                {canExport ? (
                    <Button asChild variant="outline">
                        <a href={`${base}/export`}>Export JSON Lines</a>
                    </Button>
                ) : (
                    <span className="text-muted-foreground">
                        SIEM export is an Enterprise feature.{' '}
                        <Link
                            className="underline"
                            href={`/settings/teams/${team.slug}/billing`}
                        >
                            See plans
                        </Link>
                    </span>
                )}
            </form>

            <Card>
                <CardContent className="pt-4">
                    {events.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No audit events yet.
                        </p>
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <TableHead>When</TableHead>
                                    <TableHead>Event</TableHead>
                                    <TableHead>Actor</TableHead>
                                    <TableHead>Target</TableHead>
                                    <TableHead>Outcome</TableHead>
                                </tr>
                            </thead>
                            <tbody>
                                {events.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-mono text-xs whitespace-nowrap">
                                            {new Date(row.at).toLocaleString()}
                                        </TableCell>
                                        <TableCell>
                                            <Badge>{row.type}</Badge>
                                        </TableCell>
                                        <TableCell title={row.actor}>
                                            {row.actorName}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {row.target}
                                        </TableCell>
                                        <TableCell>{row.outcome}</TableCell>
                                    </TableRow>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            <div className="mt-4 flex gap-2">
                {events.prev_page_url && (
                    <Button asChild variant="outline" size="sm">
                        <Link href={events.prev_page_url}>Newer</Link>
                    </Button>
                )}
                {events.next_page_url && (
                    <Button asChild variant="outline" size="sm">
                        <Link href={events.next_page_url}>Older</Link>
                    </Button>
                )}
            </div>
        </>
    );
}

Audit.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
