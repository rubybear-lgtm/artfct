import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';

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
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface PlanLimits {
    storage_bytes: number;
    artifacts_per_month: number;
    bundle_size_ceiling_bytes: number;
}

interface Props {
    team: {
        slug: string;
        name: string;
        plan: 'free' | 'team' | 'enterprise';
        paymentStatus: 'active' | 'past_due';
        hasSubscription: boolean;
        seatsBilled: number | null;
        activeSeats: number;
        cancelAtPeriodEnd: boolean;
        renewsAt: string | null;
    };
    invoices: {
        number: string | null;
        amount: number;
        currency: string;
        status: string | null;
        date: number;
        url: string | null;
    }[];
    canManage: boolean;
    isOwner: boolean;
    stripeConfigured: boolean;
    usage: {
        storagePercent: number;
        artifactsPercent: number;
        storageWarning: boolean;
        artifactsWarning: boolean;
        storageExceeded: boolean;
        artifactsExceeded: boolean;
    } | null;
    limits: { free: PlanLimits; team: PlanLimits };
    checkout: string | null;
}

const size = (bytes: number) =>
    bytes >= 1024 ** 3
        ? `${(bytes / 1024 ** 3).toFixed(0)} GB`
        : `${(bytes / 1024 ** 2).toFixed(0)} MB`;

function Meter({
    label,
    percent,
    warning,
    exceeded,
}: {
    label: string;
    percent: number;
    warning: boolean;
    exceeded: boolean;
}) {
    const color = exceeded
        ? 'bg-destructive'
        : warning
          ? 'bg-warning'
          : 'bg-primary';

    return (
        <div>
            <div className="mb-1 flex justify-between text-sm">
                <span>{label}</span>
                <span className="text-muted-foreground">{percent}%</span>
            </div>
            <div
                className="h-2 rounded-full bg-muted"
                role="progressbar"
                aria-label={label}
                aria-valuenow={percent}
            >
                <div
                    className={`h-2 rounded-full ${color}`}
                    style={{ width: `${Math.min(100, percent)}%` }}
                />
            </div>
        </div>
    );
}

export default function Billing({
    team,
    canManage,
    isOwner,
    stripeConfigured,
    usage,
    limits,
    invoices,
    checkout,
}: Props) {
    const base = `/settings/teams/${team.slug}/billing`;
    const paid = team.plan !== 'free';
    const awaitingWebhook = checkout === 'success' && !paid;

    // The redirect back from Stripe never grants access; poll until the webhook lands.
    useEffect(() => {
        if (!awaitingWebhook) {
            return;
        }

        let attempts = 0;
        const timer = window.setInterval(() => {
            attempts += 1;

            if (attempts > 15) {
                window.clearInterval(timer);

                return;
            }

            router.reload({ only: ['team', 'invoices'] });
        }, 2000);

        return () => window.clearInterval(timer);
    }, [awaitingWebhook]);

    return (
        <>
            <Head title="Billing" />
            <h1 className="mb-6 text-2xl font-semibold">Billing</h1>

            <div className="flex flex-col gap-6">
                {awaitingWebhook && (
                    <Alert>
                        Thanks. Your payment is being confirmed; this page
                        updates when Stripe reports it.
                    </Alert>
                )}
                {checkout === 'success' && paid && (
                    <Alert>You are on the Team plan. Thanks!</Alert>
                )}
                {team.cancelAtPeriodEnd && (
                    <Alert variant="warning">
                        Your subscription ends
                        {team.renewsAt
                            ? ` on ${new Date(team.renewsAt).toLocaleDateString()}`
                            : ' at the end of the billing period'}
                        . Resume it to keep the Team plan.
                    </Alert>
                )}
                {checkout === 'cancelled' && (
                    <Alert>Checkout was cancelled. Nothing was charged.</Alert>
                )}
                {team.paymentStatus === 'past_due' && (
                    <Alert variant="warning">
                        Your last payment failed. New artifacts are blocked
                        until it is fixed; existing ones keep serving.
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            Current plan{' '}
                            <Badge variant={paid ? 'success' : 'outline'}>
                                {team.plan}
                            </Badge>
                        </CardTitle>
                        <CardDescription>
                            {paid
                                ? `${team.activeSeats} active seats${team.seatsBilled !== null ? `, ${team.seatsBilled} billed` : ''}. Seats sync daily.${team.renewsAt && !team.cancelAtPeriodEnd ? ` Renews ${new Date(team.renewsAt).toLocaleDateString()}.` : ''}`
                                : 'Free plan. Upgrade when you need more room for your team.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2">
                        {!paid && canManage && (
                            <Button
                                disabled={!stripeConfigured}
                                onClick={() => router.post(`${base}/checkout`)}
                            >
                                Upgrade to Team
                            </Button>
                        )}
                        {!paid && !stripeConfigured && (
                            <p className="text-sm text-muted-foreground">
                                Payments are not enabled on this environment
                                yet.
                            </p>
                        )}
                        {team.hasSubscription && canManage && (
                            <Button
                                variant="outline"
                                onClick={() => router.post(`${base}/portal`)}
                            >
                                Payment method &amp; invoices
                            </Button>
                        )}
                        {paid &&
                            team.hasSubscription &&
                            canManage &&
                            isOwner &&
                            (team.cancelAtPeriodEnd ? (
                                <Button
                                    onClick={() =>
                                        router.post(`${base}/resume`)
                                    }
                                >
                                    Resume subscription
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(`${base}/cancel`)
                                    }
                                >
                                    Cancel subscription
                                </Button>
                            ))}
                        {!canManage && (
                            <p className="text-sm text-muted-foreground">
                                Only admins can change the plan.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {invoices.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Invoices</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <thead>
                                    <tr>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Number</TableHead>
                                        <TableHead>Amount</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead />
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoices.map((invoice) => (
                                        <TableRow
                                            key={invoice.number ?? invoice.date}
                                        >
                                            <TableCell>
                                                {new Date(
                                                    invoice.date * 1000,
                                                ).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                {invoice.number}
                                            </TableCell>
                                            <TableCell>
                                                {(invoice.amount / 100).toFixed(
                                                    2,
                                                )}{' '}
                                                {invoice.currency.toUpperCase()}
                                            </TableCell>
                                            <TableCell>
                                                {invoice.status}
                                            </TableCell>
                                            <TableCell>
                                                {invoice.url && (
                                                    <a
                                                        className="underline"
                                                        href={invoice.url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        View
                                                    </a>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </tbody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {!usage && (
                    <Alert>
                        Usage is unavailable right now. Your artifacts are
                        unaffected; try again shortly.
                    </Alert>
                )}
                {usage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Usage this month</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <Meter
                                label="Storage"
                                percent={usage.storagePercent}
                                warning={usage.storageWarning}
                                exceeded={usage.storageExceeded}
                            />
                            <Meter
                                label="Artifacts"
                                percent={usage.artifactsPercent}
                                warning={usage.artifactsWarning}
                                exceeded={usage.artifactsExceeded}
                            />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>What each plan includes</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        {(['free', 'team'] as const).map((name) => (
                            <div
                                key={name}
                                className="rounded-md border border-border p-4 text-sm"
                            >
                                <p className="mb-2 font-semibold capitalize">
                                    {name}
                                </p>
                                <ul className="flex flex-col gap-1 text-muted-foreground">
                                    <li>
                                        {size(limits[name].storage_bytes)}{' '}
                                        storage
                                    </li>
                                    <li>
                                        {limits[name].artifacts_per_month}{' '}
                                        artifacts per month
                                    </li>
                                    <li>
                                        {size(
                                            limits[name]
                                                .bundle_size_ceiling_bytes,
                                        )}{' '}
                                        per artifact
                                    </li>
                                </ul>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Billing.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
