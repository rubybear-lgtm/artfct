import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import teamRoutes from '@/routes/teams';

interface AuditRow {
    id: number;
    type: string;
    typeLabel: string;
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
    types: { value: string; label: string }[];
    members: { id: string; name: string }[];
    total: number;
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
    members,
    total,
    filters,
    canExport,
}: Props) {
    const [form, setForm] = useState({
        type: filters.type ?? '',
        actor: filters.actor ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const hasFilters = Object.values(filters).some((value) => value !== null);

    const apply = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            teamRoutes.audit.index.url({ team: team.slug }),
            Object.fromEntries(
                Object.entries(form).filter(([, v]) => v !== ''),
            ),
            { preserveState: true },
        );
    };

    const clearFilters = () => {
        setForm({ type: '', actor: '', from: '', to: '' });
        router.get(teamRoutes.audit.index.url({ team: team.slug }));
    };

    return (
        <>
            <Head title="Audit log" />
            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="mb-1 text-2xl font-semibold">Audit log</h1>
                    <p className="text-sm text-muted-foreground">
                        Every member, role, token, retention and export action
                        for {team.name}. Entries cannot be edited or deleted.
                    </p>
                </div>
                <div className="flex flex-col items-start gap-1">
                    {canExport ? (
                        <>
                            <Button asChild variant="outline">
                                <a
                                    href={teamRoutes.audit.export.url({
                                        team: team.slug,
                                    })}
                                >
                                    Download full log
                                </a>
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                Includes every event, not just the filtered
                                ones.
                            </p>
                        </>
                    ) : (
                        <span className="text-sm text-muted-foreground">
                            Export to your security tool is an Enterprise
                            feature.{' '}
                            <Link
                                className="underline"
                                href={teamRoutes.billing.show.url({
                                    team: team.slug,
                                })}
                            >
                                See plans
                            </Link>
                        </span>
                    )}
                </div>
            </div>

            <form
                onSubmit={apply}
                className="mb-4 flex flex-wrap items-end gap-3 text-sm"
            >
                <label className="flex min-w-0 flex-col gap-1">
                    Event
                    <select
                        className="rounded-md border border-border bg-background px-2 py-2 max-md:min-h-[44px] max-md:text-base"
                        value={form.type}
                        onChange={(e) =>
                            setForm({ ...form, type: e.target.value })
                        }
                    >
                        <option value="">All events</option>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex min-w-0 flex-col gap-1">
                    Person
                    <select
                        className="rounded-md border border-border bg-background px-2 py-2 max-md:min-h-[44px] max-md:text-base"
                        value={form.actor}
                        onChange={(e) =>
                            setForm({ ...form, actor: e.target.value })
                        }
                    >
                        <option value="">Anyone</option>
                        {members.map((member) => (
                            <option key={member.id} value={member.id}>
                                {member.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex min-w-0 flex-col gap-1">
                    From
                    <Input
                        type="date"
                        value={form.from}
                        onChange={(e) =>
                            setForm({ ...form, from: e.target.value })
                        }
                    />
                </label>
                <label className="flex min-w-0 flex-col gap-1">
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
            </form>

            <p className="mb-2 text-sm text-muted-foreground">
                {total} {total === 1 ? 'event' : 'events'}
            </p>

            <Card>
                <CardContent className="pt-4">
                    {events.data.length === 0 ? (
                        hasFilters ? (
                            <EmptyState title="No events match these filters.">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={clearFilters}
                                >
                                    Clear filters
                                </Button>
                            </EmptyState>
                        ) : (
                            <EmptyState title="No audit events yet." />
                        )
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <TableHead>When</TableHead>
                                    <TableHead>Event</TableHead>
                                    <TableHead>Person</TableHead>
                                    <TableHead>Target</TableHead>
                                    <TableHead>Outcome</TableHead>
                                </tr>
                            </thead>
                            <tbody>
                                {events.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="text-xs whitespace-nowrap tabular-nums">
                                            {new Date(row.at).toLocaleString()}
                                        </TableCell>
                                        <TableCell>
                                            <Badge>{row.typeLabel}</Badge>
                                        </TableCell>
                                        <TableCell
                                            title={row.actor}
                                            className="min-w-0 break-all"
                                        >
                                            {row.actorName}
                                        </TableCell>
                                        <TableCell className="text-xs break-all tabular-nums">
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
