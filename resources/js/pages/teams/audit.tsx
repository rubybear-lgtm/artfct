import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface AuditRow {
    id: number;
    type: string;
    actor: string;
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
    selectedType: string | null;
}

export default function Audit({ team, events, types, selectedType }: Props) {
    const base = `/settings/teams/${team.slug}/audit`;

    return (
        <>
            <Head title="Audit log" />
            <h1 className="mb-1 text-2xl font-semibold">Audit log</h1>
            <p className="mb-6 text-sm text-muted-foreground">
                Every member, role, token, retention and export action for{' '}
                {team.name}. Entries cannot be edited or deleted.
            </p>

            <div className="mb-4 flex items-center gap-2 text-sm">
                <label htmlFor="type">Event</label>
                <select
                    id="type"
                    className="rounded-md border border-border bg-background px-2 py-1"
                    value={selectedType ?? ''}
                    onChange={(event) =>
                        router.get(
                            base,
                            event.target.value
                                ? { type: event.target.value }
                                : {},
                            { preserveState: true },
                        )
                    }
                >
                    <option value="">All events</option>
                    {types.map((type) => (
                        <option key={type} value={type}>
                            {type}
                        </option>
                    ))}
                </select>
            </div>

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
                                        <TableCell>{row.actor}</TableCell>
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
