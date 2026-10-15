import { Deferred, Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import {
    formatBytes,
    formatDate,
    formatDateTime,
    formatNumber,
    humanize,
} from '@/components/ops/format';
import { FactList, MeterBar, Section } from '@/components/ops/section';
import type {
    TenantConnections,
    TenantDetail,
    TenantEvent,
    TenantMembers,
    TenantSso,
    TenantSsoError,
    TenantUsage,
    TenantUsageError,
} from '@/components/ops/types';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import OpsLayout from '@/layouts/ops-layout';
import { index as tenantsIndex } from '@/routes/ops/tenants';

interface Props {
    tenant: TenantDetail;
    members: TenantMembers;
    connections: TenantConnections;
    sso?: TenantSso | TenantSsoError;
    usage?: TenantUsage | TenantUsageError;
    events: TenantEvent[];
}

function isUsageError(
    usage: TenantUsage | TenantUsageError,
): usage is TenantUsageError {
    return 'error' in usage;
}

function ExternalId({ id, url }: { id: string | null; url: string | null }) {
    if (!id) {
        return <span className="text-muted-foreground">None</span>;
    }

    if (url && url.startsWith('https://')) {
        return (
            <a
                href={url}
                target="_blank"
                rel="noopener noreferrer"
                className="break-all underline underline-offset-4"
            >
                {id}
            </a>
        );
    }

    return <span className="break-all">{id}</span>;
}

function capitalize(text: string) {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function orNone(value: ReactNode | null) {
    return value ?? <span className="text-muted-foreground">None</span>;
}

function UsageSkeleton() {
    return (
        <div
            className="animate-pulse space-y-5"
            role="status"
            aria-label="Loading usage"
        >
            <div className="space-y-2">
                <div className="h-4 w-1/3 rounded bg-muted" />
                <div className="h-2 w-full rounded-full bg-muted" />
            </div>
            <div className="space-y-2">
                <div className="h-4 w-1/4 rounded bg-muted" />
                <div className="h-2 w-full rounded-full bg-muted" />
            </div>
        </div>
    );
}

function SsoSkeleton() {
    return (
        <div
            className="animate-pulse space-y-3"
            role="status"
            aria-label="Loading single sign-on"
        >
            <div className="h-4 w-1/2 rounded bg-muted" />
            <div className="h-4 w-1/3 rounded bg-muted" />
        </div>
    );
}

function SsoPanel({ sso }: { sso?: TenantSso | TenantSsoError }) {
    if (!sso) {
        return <SsoSkeleton />;
    }

    if ('error' in sso) {
        return (
            <Alert variant="warning" role="alert">
                {capitalize(sso.error)}
            </Alert>
        );
    }

    return (
        <>
            <FactList
                items={[{ label: 'Status', value: ssoLabels[sso.state] }]}
            />
            {sso.connections.length > 0 && (
                <ul className="border-t border-border text-sm">
                    {sso.connections.map((connection) => (
                        <li
                            key={`${connection.type}-${connection.name}`}
                            className="flex flex-wrap items-baseline justify-between gap-x-4 border-b border-border py-2"
                        >
                            <span className="min-w-0 break-words">
                                {connection.name}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {connection.type}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

function UsagePanel({ usage }: { usage?: TenantUsage | TenantUsageError }) {
    if (!usage) {
        return <UsageSkeleton />;
    }

    if (isUsageError(usage)) {
        return (
            <Alert variant="warning" role="alert">
                {capitalize(usage.error)}
            </Alert>
        );
    }

    const { limits } = usage;
    const period =
        usage.period_start && usage.period_end
            ? `${formatDate(usage.period_start)} to ${formatDate(usage.period_end)}`
            : null;

    return (
        <div className="space-y-6">
            <div>
                <div className="mb-2 flex flex-wrap items-baseline justify-between gap-x-4 text-sm">
                    <span className="font-medium">Storage</span>
                    <span className="text-muted-foreground tabular-nums">
                        {limits
                            ? `${formatBytes(usage.storage_bytes)} of ${formatBytes(limits.storage_bytes)}`
                            : `${formatBytes(usage.storage_bytes)}, limit unknown`}
                    </span>
                </div>
                {limits && (
                    <MeterBar
                        label="Storage used"
                        value={usage.storage_bytes}
                        max={limits.storage_bytes}
                    />
                )}
            </div>
            <div>
                <div className="mb-2 flex flex-wrap items-baseline justify-between gap-x-4 text-sm">
                    <span className="font-medium">
                        Artifacts this billing period
                    </span>
                    <span className="text-muted-foreground tabular-nums">
                        {limits
                            ? `${formatNumber(usage.artifacts_this_period)} of ${formatNumber(limits.artifacts_per_month)}`
                            : `${formatNumber(usage.artifacts_this_period)}, limit unknown`}
                    </span>
                </div>
                {limits && (
                    <MeterBar
                        label="Artifacts this period"
                        value={usage.artifacts_this_period}
                        max={limits.artifacts_per_month}
                    />
                )}
            </div>
            {period && (
                <p className="text-xs text-muted-foreground">
                    Billing period: {period}
                </p>
            )}
        </div>
    );
}

const ssoLabels: Record<TenantSso['state'], string> = {
    connected: 'Connected',
    none: 'Not set up',
    unavailable: 'Could not be checked',
};

function outcomeVariant(outcome: string) {
    const value = outcome.toLowerCase();

    if (value === 'success' || value === 'succeeded') {
        return 'success' as const;
    }

    if (value.includes('fail') || value.includes('denied')) {
        return 'destructive' as const;
    }

    return 'default' as const;
}

export default function TenantShow({
    tenant,
    members,
    connections,
    sso,
    usage,
    events,
}: Props) {
    const isDeleted = tenant.deleted_at !== null;

    return (
        <>
            <Head title={tenant.name} />
            <div className="mb-6">
                <Link
                    href={tenantsIndex.url()}
                    className="mb-3 inline-flex text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline max-md:min-h-11 max-md:items-center"
                >
                    All tenants
                </Link>
                <h1 className="mb-1 break-words">{tenant.name}</h1>
                <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <span className="break-all">{tenant.slug}</span>
                    <Badge>{humanize(tenant.plan)}</Badge>
                    <Badge
                        variant={
                            tenant.payment_status === 'past_due'
                                ? 'warning'
                                : 'default'
                        }
                    >
                        {humanize(tenant.payment_status)}
                    </Badge>
                    {isDeleted && <Badge variant="outline">Deleted</Badge>}
                    {tenant.provisioning_failed_step && (
                        <Badge variant="destructive">Provisioning failed</Badge>
                    )}
                </p>
            </div>

            {tenant.provisioning_failed_step && (
                <Alert variant="destructive" className="mb-6">
                    Provisioning stopped at the step{' '}
                    <strong>{tenant.provisioning_failed_step}</strong>.
                </Alert>
            )}

            <Section title="Core">
                <FactList
                    items={[
                        { label: 'Owner', value: orNone(tenant.owner) },
                        { label: 'Seats billed', value: tenant.seats_billed },
                        {
                            label: 'Retention',
                            value: orNone(
                                tenant.retention_days === null
                                    ? null
                                    : `${tenant.retention_days} days`,
                            ),
                        },
                        { label: 'Region', value: orNone(tenant.region) },
                        {
                            label: 'Release',
                            value: orNone(tenant.release_version),
                        },
                        {
                            label: 'Schema',
                            value: orNone(tenant.schema_version),
                        },
                        {
                            label: 'Stripe customer',
                            value: (
                                <ExternalId
                                    id={tenant.stripe_customer_id}
                                    url={tenant.stripe_customer_url}
                                />
                            ),
                        },
                        {
                            label: 'Stripe subscription',
                            value: (
                                <ExternalId
                                    id={tenant.stripe_subscription_id}
                                    url={tenant.stripe_subscription_url}
                                />
                            ),
                        },
                        {
                            label: 'Current period ends',
                            value: orNone(
                                tenant.current_period_end
                                    ? formatDate(tenant.current_period_end)
                                    : null,
                            ),
                        },
                        {
                            label: 'Cancels at period end',
                            value: tenant.cancel_at_period_end ? 'Yes' : 'No',
                        },
                        {
                            label: 'Created',
                            value: tenant.created_at
                                ? formatDateTime(tenant.created_at)
                                : 'Unknown',
                        },
                        {
                            label: 'Provisioned',
                            value: tenant.provisioned_at
                                ? formatDateTime(tenant.provisioned_at)
                                : 'Not yet',
                        },
                        ...(tenant.deleted_at
                            ? [
                                  {
                                      label: 'Deleted',
                                      value: formatDateTime(tenant.deleted_at),
                                  },
                              ]
                            : []),
                    ]}
                />
            </Section>

            <Section title="Members">
                <FactList
                    items={[
                        { label: 'Members', value: members.total },
                        {
                            label: 'Pending invitations',
                            value: members.pending_invitations,
                        },
                    ]}
                />
                <h3 className="mt-5 mb-1 text-sm font-medium">Admins</h3>
                {members.admins.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        This tenant has no admins.
                    </p>
                ) : (
                    <ul className="border-t border-border text-sm">
                        {members.admins.map((admin) => (
                            <li
                                key={admin.email}
                                className="flex flex-wrap items-baseline justify-between gap-x-4 border-b border-border py-2"
                            >
                                <span className="min-w-0 break-words">
                                    {admin.name}
                                </span>
                                <span className="min-w-0 text-xs break-all text-muted-foreground">
                                    {admin.email}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section title="Connections">
                <FactList
                    items={[
                        {
                            label: 'MCP connections',
                            value: connections.mcp.active,
                        },
                        {
                            label: 'MCP last used',
                            value: formatDateTime(connections.mcp.last_used_at),
                        },
                        {
                            label: 'Organization tokens',
                            value: connections.org_tokens.active,
                        },
                        {
                            label: 'Tokens last used',
                            value: formatDateTime(
                                connections.org_tokens.last_used_at,
                            ),
                        },
                    ]}
                />
            </Section>

            <Section title="Single sign-on">
                <Deferred data="sso" fallback={<SsoSkeleton />}>
                    <SsoPanel sso={sso} />
                </Deferred>
            </Section>

            <Section title="Usage">
                <Deferred data="usage" fallback={<UsageSkeleton />}>
                    <UsagePanel usage={usage} />
                </Deferred>
            </Section>

            <Section title="Recent events">
                {events.length === 0 ? (
                    <EmptyState title="No events recorded yet." />
                ) : (
                    <ul className="border-t border-border">
                        {events.map((event) => (
                            <li
                                key={event.id}
                                className="flex flex-col gap-1 border-b border-border py-3 text-sm sm:flex-row sm:items-baseline sm:gap-4"
                            >
                                <time
                                    dateTime={event.at}
                                    className="shrink-0 text-xs text-muted-foreground tabular-nums sm:w-44"
                                >
                                    {formatDateTime(event.at)}
                                </time>
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium break-words">
                                        {event.type_label}
                                    </p>
                                    <p className="text-xs break-words text-muted-foreground">
                                        {event.actor_label}
                                        {event.target !== null && (
                                            <>
                                                {' · '}
                                                <span className="break-all">
                                                    {event.target}
                                                </span>
                                            </>
                                        )}
                                    </p>
                                </div>
                                <Badge
                                    variant={outcomeVariant(event.outcome)}
                                    className="self-start"
                                >
                                    {humanize(event.outcome)}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>
        </>
    );
}

TenantShow.layout = (page: React.ReactNode) => <OpsLayout>{page}</OpsLayout>;
