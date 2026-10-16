import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

import { formatDate, humanize } from '@/components/ops/format';
import type {
    TenantListFilters,
    TenantListOptions,
    TenantRow,
    TenantState,
} from '@/components/ops/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import OpsLayout from '@/layouts/ops-layout';
import {
    index as tenantsIndex,
    show as tenantShow,
} from '@/routes/ops/tenants';

interface Props {
    tenants: {
        data: TenantRow[];
        next_cursor: string | null;
        prev_cursor: string | null;
    };
    filters: TenantListFilters;
    options: TenantListOptions;
    status: string | null;
}

type ToggleKey = 'provisioning_failed' | 'past_due' | 'cancelling' | 'deleted';

const toggles: { key: ToggleKey; label: string }[] = [
    { key: 'provisioning_failed', label: 'Provisioning failed' },
    { key: 'past_due', label: 'Past due' },
    { key: 'cancelling', label: 'Cancelling' },
    { key: 'deleted', label: 'Deleted' },
];

const selectClass =
    'h-9 min-w-0 rounded-lg border border-border bg-background px-2 text-sm max-md:min-h-[44px] max-md:text-base';

/** The query string the backend reads: empty and false filters are left out. */
function toQuery(filters: TenantListFilters): Record<string, string> {
    const query: Record<string, string> = {};

    if (filters.q) {
        query.q = filters.q;
    }

    if (filters.plan) {
        query.plan = filters.plan;
    }

    if (filters.payment_status) {
        query.payment_status = filters.payment_status;
    }

    if (filters.region) {
        query.region = filters.region;
    }

    toggles.forEach(({ key }) => {
        if (filters[key]) {
            query[key] = '1';
        }
    });

    return query;
}

function StateBadge({ state }: { state: TenantState }) {
    if (state === 'provisioning_failed') {
        return <Badge variant="destructive">Provisioning failed</Badge>;
    }

    if (state === 'deleted') {
        return <Badge variant="outline">Deleted</Badge>;
    }

    return <Badge variant="success">Active</Badge>;
}

function FilterField({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <label className="flex min-w-0 flex-col gap-1 text-sm">
            <span className="text-xs text-muted-foreground">{label}</span>
            {children}
        </label>
    );
}

export default function TenantsIndex({
    tenants,
    filters,
    options,
    status,
}: Props) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [announcement, setAnnouncement] = useState('');

    const announce = {
        onStart: () => setAnnouncement(''),
        onFinish: () => setAnnouncement('Tenant list updated'),
    };

    const hasFilters = Object.keys(toQuery(filters)).length > 0;

    const visit = (next: TenantListFilters) => {
        const typed = search.trim();

        router.get(
            tenantsIndex.url(),
            toQuery({ ...next, q: typed === '' ? null : typed }),
            {
                preserveState: true,
                preserveScroll: true,
                ...announce,
            },
        );
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        visit(filters);
    };

    const clearFilters = () => {
        setSearch('');
        router.get(
            tenantsIndex.url(),
            {},
            { preserveState: true, ...announce },
        );
    };

    const cursorUrl = (cursor: string) =>
        tenantsIndex.url({ query: { ...toQuery(filters), cursor } });

    return (
        <>
            <Head title="Tenants" />
            <p className="sr-only" role="status" aria-live="polite">
                {announcement}
            </p>
            <div role="status" aria-live="polite">
                {status && (
                    <p className="mb-6 border-l-2 border-primary pl-3 text-sm">
                        {status}
                    </p>
                )}
            </div>
            <div className="mb-6">
                <h1 className="mb-1">Tenants</h1>
                <p className="text-sm text-muted-foreground">
                    Every workspace on the platform, newest first.
                </p>
            </div>

            <form
                onSubmit={submitSearch}
                role="search"
                className="mb-4 flex flex-wrap items-end gap-3"
            >
                <FilterField label="Name, slug, billing email or Stripe customer">
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Name, slug, billing email or Stripe customer"
                        className="w-full sm:w-96"
                    />
                </FilterField>
                <Button type="submit">Search</Button>
            </form>

            <div className="mb-4 flex flex-wrap items-end gap-3">
                <FilterField label="Plan">
                    <select
                        className={selectClass}
                        value={filters.plan ?? ''}
                        onChange={(event) =>
                            visit({
                                ...filters,
                                plan: event.target.value || null,
                            })
                        }
                    >
                        <option value="">All plans</option>
                        {options.plans.map((plan) => (
                            <option key={plan} value={plan}>
                                {humanize(plan)}
                            </option>
                        ))}
                    </select>
                </FilterField>
                <FilterField label="Payment">
                    <select
                        className={selectClass}
                        value={filters.payment_status ?? ''}
                        onChange={(event) =>
                            visit({
                                ...filters,
                                payment_status: event.target.value || null,
                            })
                        }
                    >
                        <option value="">Any status</option>
                        {options.payment_statuses.map((status) => (
                            <option key={status} value={status}>
                                {humanize(status)}
                            </option>
                        ))}
                    </select>
                </FilterField>
                <FilterField label="Region">
                    <select
                        className={selectClass}
                        value={filters.region ?? ''}
                        onChange={(event) =>
                            visit({
                                ...filters,
                                region: event.target.value || null,
                            })
                        }
                    >
                        <option value="">All regions</option>
                        {options.regions.map((region) => (
                            <option key={region} value={region}>
                                {region}
                            </option>
                        ))}
                    </select>
                </FilterField>
            </div>

            <fieldset className="mb-6 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                <legend className="sr-only">Show only</legend>
                {toggles.map(({ key, label }) => (
                    <label
                        key={key}
                        className="inline-flex items-center gap-2 py-1 max-md:min-h-[44px]"
                    >
                        <input
                            type="checkbox"
                            className="size-4 accent-[var(--accent)]"
                            checked={filters[key]}
                            onChange={(event) =>
                                visit({
                                    ...filters,
                                    [key]: event.target.checked,
                                })
                            }
                        />
                        {label}
                    </label>
                ))}
            </fieldset>

            {tenants.data.length === 0 ? (
                hasFilters ? (
                    <EmptyState title="No tenants match these filters.">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={clearFilters}
                        >
                            Clear filters
                        </Button>
                    </EmptyState>
                ) : (
                    <EmptyState title="No tenants yet." />
                )
            ) : (
                <div className="border-y border-border">
                    <Table className="min-w-[48rem]">
                        <thead>
                            <tr>
                                <TableHead>Tenant</TableHead>
                                <TableHead>Plan</TableHead>
                                <TableHead>Payment</TableHead>
                                <TableHead className="text-right">
                                    Seats
                                </TableHead>
                                <TableHead>Region</TableHead>
                                <TableHead>Release</TableHead>
                                <TableHead>Schema</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead>State</TableHead>
                            </tr>
                        </thead>
                        <tbody>
                            {tenants.data.map((tenant) => (
                                <TableRow key={tenant.slug}>
                                    <TableCell className="min-w-0">
                                        <Link
                                            href={tenantShow.url({
                                                team: tenant.slug,
                                            })}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {tenant.name}
                                        </Link>
                                        <div className="text-xs break-all text-muted-foreground">
                                            {tenant.slug}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {humanize(tenant.plan)}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                tenant.payment_status ===
                                                'past_due'
                                                    ? 'warning'
                                                    : 'default'
                                            }
                                        >
                                            {humanize(tenant.payment_status)}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {tenant.seats}
                                    </TableCell>
                                    <TableCell>
                                        {tenant.region ?? '-'}
                                    </TableCell>
                                    <TableCell className="text-xs tabular-nums">
                                        {tenant.release_version ?? '-'}
                                    </TableCell>
                                    <TableCell className="text-xs tabular-nums">
                                        {tenant.schema_version ?? '-'}
                                    </TableCell>
                                    <TableCell className="text-xs whitespace-nowrap tabular-nums">
                                        {formatDate(tenant.created_at)}
                                    </TableCell>
                                    <TableCell>
                                        <StateBadge state={tenant.state} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </tbody>
                    </Table>
                </div>
            )}

            {(tenants.prev_cursor || tenants.next_cursor) && (
                <nav
                    aria-label="Pagination"
                    className="mt-4 flex items-center gap-2"
                >
                    {tenants.prev_cursor && (
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={cursorUrl(tenants.prev_cursor)}
                                preserveState
                                {...announce}
                            >
                                Previous
                            </Link>
                        </Button>
                    )}
                    {tenants.next_cursor && (
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={cursorUrl(tenants.next_cursor)}
                                preserveState
                                {...announce}
                            >
                                Next
                            </Link>
                        </Button>
                    )}
                </nav>
            )}
        </>
    );
}

TenantsIndex.layout = (page: React.ReactNode) => <OpsLayout>{page}</OpsLayout>;
