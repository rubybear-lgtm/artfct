import { Head } from '@inertiajs/react';

import { formatDateTime, formatNumber } from '@/components/ops/format';
import { FactList, Section } from '@/components/ops/section';
import type { HealthProps, ReadinessState } from '@/components/ops/types';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import OpsLayout from '@/layouts/ops-layout';

const readinessLabels: Record<ReadinessState, string> = {
    ready: 'Ready',
    incomplete: 'Incomplete',
    'not configured': 'Not configured',
};

function ReadinessBadge({ state }: { state: ReadinessState }) {
    const variant =
        state === 'ready'
            ? 'success'
            : state === 'incomplete'
              ? 'warning'
              : 'default';

    return <Badge variant={variant}>{readinessLabels[state]}</Badge>;
}

function Stat({
    label,
    value,
    tone = 'default',
}: {
    label: string;
    value: number;
    tone?: 'default' | 'attention';
}) {
    return (
        <div className="border-b border-border py-3">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={`mt-1 font-serif text-3xl tracking-tight tabular-nums ${
                    tone === 'attention' && value > 0 ? 'text-destructive' : ''
                }`}
            >
                {formatNumber(value)}
            </dd>
        </div>
    );
}

export default function Health({
    queue,
    indexing,
    org_token_revocations,
    stripe,
    worker_events,
    readiness,
    staff_organization,
}: HealthProps) {
    return (
        <>
            <Head title="Health" />
            <div className="mb-6">
                <h1 className="mb-1">Health</h1>
                <p className="text-sm text-muted-foreground">
                    Queues, background work and integration readiness.
                </p>
            </div>

            <Section title="Background work">
                <dl className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                    <Stat
                        label="Failed jobs"
                        value={queue.failed_jobs}
                        tone="attention"
                    />
                    <Stat label="Pending jobs" value={queue.pending_jobs} />
                    <Stat
                        label="Indexing failures"
                        value={indexing.failures}
                        tone="attention"
                    />
                    <Stat
                        label="Token revocations waiting"
                        value={org_token_revocations.pending}
                        tone="attention"
                    />
                </dl>
            </Section>

            <Section title="Last events received">
                <FactList
                    items={[
                        {
                            label: 'Stripe',
                            value: formatDateTime(stripe.last_received_at),
                        },
                        {
                            label: 'Worker',
                            value: formatDateTime(
                                worker_events.last_received_at,
                            ),
                        },
                    ]}
                />
            </Section>

            <Section title="Readiness">
                {readiness.length === 0 ? (
                    <EmptyState title="No readiness checks reported." />
                ) : (
                    <div className="border-y border-border">
                        <Table className="min-w-[28rem]">
                            <thead>
                                <tr>
                                    <TableHead>Check</TableHead>
                                    <TableHead>State</TableHead>
                                    <TableHead>Missing</TableHead>
                                </tr>
                            </thead>
                            <tbody>
                                {readiness.map((check) => (
                                    <TableRow key={check.name}>
                                        <TableCell className="font-medium">
                                            {check.name}
                                        </TableCell>
                                        <TableCell>
                                            <ReadinessBadge
                                                state={check.state}
                                            />
                                        </TableCell>
                                        <TableCell className="text-xs break-words text-muted-foreground">
                                            {check.missing.length > 0
                                                ? check.missing.join(', ')
                                                : '-'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </Section>

            <Section title="Staff organization">
                <FactList
                    items={[
                        {
                            label: 'State',
                            value: staff_organization.configured
                                ? 'Configured'
                                : 'Not configured',
                        },
                        {
                            label: 'Organization id',
                            value: staff_organization.id ? (
                                <span className="break-all">
                                    {staff_organization.id}
                                </span>
                            ) : (
                                <span className="text-muted-foreground">
                                    None
                                </span>
                            ),
                        },
                    ]}
                />
            </Section>
        </>
    );
}

Health.layout = (page: React.ReactNode) => <OpsLayout>{page}</OpsLayout>;
