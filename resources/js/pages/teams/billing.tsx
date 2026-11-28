import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
import { PageHeader } from '@/components/ui/page-header';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    formatLocalDate,
    formatLocalNumber,
    useReader,
} from '@/lib/local-date';
import teams from '@/routes/teams';

interface PlanLimits {
    storage_bytes: number;
    artifacts_per_month: number;
    bundle_size_ceiling_bytes: number;
}

interface Props {
    team: {
        slug: string;
        name: string;
        plan: 'free' | 'pro' | 'team' | 'enterprise';
        paymentStatus: 'active' | 'past_due';
        hasSubscription: boolean;
        onTrial: boolean;
        trialDaysLeft: number | null;
        trialEndsAt: string | null;
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
        storageBytes: number;
        storageLimitBytes: number;
        artifactsThisPeriod: number;
        artifactsLimit: number;
        storagePercent: number;
        artifactsPercent: number;
        storageWarning: boolean;
        artifactsWarning: boolean;
        storageExceeded: boolean;
        artifactsExceeded: boolean;
    } | null;
    limits: {
        free: PlanLimits;
        pro: PlanLimits;
        team: PlanLimits;
        enterprise: PlanLimits;
    };
    prices: {
        proMonthly: string;
        proYearly: string;
        teamMonthly: string;
        teamYearly: string;
        yearlySavings: string;
        businessContact: string;
    };
    checkout: string | null;
}

const PLAN_DISPLAY_NAMES: Record<Props['team']['plan'], string> = {
    free: 'Free',
    pro: 'Pro',
    team: 'Team',
    enterprise: 'Enterprise',
};

function peopleCountLabel(count: number): string {
    return `${count} ${count === 1 ? 'person' : 'people'}`;
}

const size = (bytes: number) => {
    if (bytes >= 1024 ** 3) {
        return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
    }

    if (bytes >= 1024 ** 2) {
        return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    }

    if (bytes >= 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${bytes} B`;
};

function Meter({
    label,
    detail,
    percent,
    warning,
    exceeded,
}: {
    label: string;
    detail: string;
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
            <div className="mb-1 flex min-w-0 justify-between gap-2 text-sm">
                <span className="min-w-0 break-words">{label}</span>
                <span className="shrink-0 text-muted-foreground tabular-nums">
                    {detail} · {percent}%
                </span>
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
    prices,
    invoices,
    checkout,
}: Props) {
    const paid = team.plan !== 'free' && !team.onTrial;
    const awaitingWebhook = checkout === 'success' && !paid;
    const [pollExhausted, setPollExhausted] = useState(false);
    const [confirmingCancel, setConfirmingCancel] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [yearly, setYearly] = useState(false);
    const reader = useReader();
    const periodEnd =
        formatLocalDate(team.renewsAt, reader, {}) ??
        'the end of the current billing period';

    const startCheckout = (plan: 'pro' | 'team') => {
        router.post(teams.billing.checkout.url({ team: team.slug }), {
            plan,
            interval: yearly ? 'year' : 'month',
        });
    };

    const cancelSubscription = () => {
        router.post(
            teams.billing.cancel.url({ team: team.slug }),
            {},
            {
                onStart: () => setCancelling(true),
                onFinish: () => {
                    setCancelling(false);
                    setConfirmingCancel(false);
                },
            },
        );
    };

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
                setPollExhausted(true);

                return;
            }

            router.reload({ only: ['team', 'invoices'] });
        }, 2000);

        return () => window.clearInterval(timer);
    }, [awaitingWebhook]);

    return (
        <>
            <Head title="Billing" />
            <PageHeader
                eyebrow={`Admin · ${team.name}`}
                title="Billing"
                description="Your plan, what you are using, invoices and payment method."
            />

            <div className="flex flex-col gap-6">
                {awaitingWebhook && !pollExhausted && (
                    <Alert>
                        Thanks. Your payment is being confirmed; this page
                        updates when Stripe reports it.
                    </Alert>
                )}
                {awaitingWebhook && pollExhausted && (
                    <Alert variant="warning">
                        We haven&apos;t had confirmation from Stripe yet.
                        Refresh in a minute, or open Payment method &amp;
                        invoices to check.
                    </Alert>
                )}
                {checkout === 'success' && paid && (
                    <Alert>
                        You are on the {PLAN_DISPLAY_NAMES[team.plan]} plan.
                        Thanks!
                    </Alert>
                )}
                {team.onTrial && team.trialDaysLeft != null && (
                    <Alert
                        variant={
                            team.trialDaysLeft <= 7 ? 'warning' : 'default'
                        }
                        data-testid="billing-trial-banner"
                    >
                        Team trial: {team.trialDaysLeft} day
                        {team.trialDaysLeft === 1 ? '' : 's'} left. After that
                        you keep Free — no charge, nothing deleted.
                    </Alert>
                )}
                {team.cancelAtPeriodEnd && (
                    <Alert variant="warning">
                        Your subscription ends
                        {team.renewsAt
                            ? ` on ${formatLocalDate(team.renewsAt, reader, {})}`
                            : ' at the end of the billing period'}
                        . Resume it to keep your plan.
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
                        <CardTitle className="flex min-w-0 flex-wrap items-center gap-2 break-words">
                            Current plan{' '}
                            <Badge
                                variant={
                                    paid || team.onTrial ? 'success' : 'outline'
                                }
                            >
                                {team.onTrial
                                    ? 'Team trial'
                                    : PLAN_DISPLAY_NAMES[team.plan]}
                            </Badge>
                        </CardTitle>
                        <CardDescription>
                            {team.onTrial
                                ? `Full Team features while the trial lasts. ${peopleCountLabel(team.activeSeats)} on this workspace.`
                                : paid
                                  ? `${peopleCountLabel(team.activeSeats)} on this workspace.${team.renewsAt && !team.cancelAtPeriodEnd ? ` Renews ${formatLocalDate(team.renewsAt, reader, {})}.` : ''}`
                                  : 'Free plan. Upgrade to Pro or Team when you need more.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        {team.plan !== 'enterprise' && canManage && (
                            <div className="flex flex-col gap-3">
                                {(!paid ||
                                    team.onTrial ||
                                    (team.plan === 'pro' &&
                                        !team.hasSubscription)) && (
                                    <div
                                        className="flex flex-wrap gap-2"
                                        role="group"
                                        aria-label="Billing period"
                                    >
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={
                                                yearly ? 'outline' : 'default'
                                            }
                                            onClick={() => setYearly(false)}
                                        >
                                            Monthly
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={
                                                yearly ? 'default' : 'outline'
                                            }
                                            onClick={() => setYearly(true)}
                                        >
                                            Yearly · {prices.yearlySavings}
                                        </Button>
                                    </div>
                                )}
                                <div className="flex min-w-0 flex-wrap gap-2">
                                    {(!paid || team.onTrial) && (
                                        <>
                                            <Button
                                                disabled={!stripeConfigured}
                                                className="max-w-full whitespace-normal"
                                                onClick={() =>
                                                    startCheckout('pro')
                                                }
                                            >
                                                {yearly
                                                    ? prices.proYearly
                                                    : prices.proMonthly}{' '}
                                                · Pro
                                            </Button>
                                            <Button
                                                disabled={!stripeConfigured}
                                                className="max-w-full whitespace-normal"
                                                onClick={() =>
                                                    startCheckout('team')
                                                }
                                            >
                                                {yearly
                                                    ? prices.teamYearly
                                                    : prices.teamMonthly}{' '}
                                                · Team
                                            </Button>
                                        </>
                                    )}
                                    {team.plan === 'pro' &&
                                        !team.onTrial &&
                                        (team.hasSubscription ? (
                                            <Button
                                                disabled={!stripeConfigured}
                                                className="max-w-full whitespace-normal"
                                                onClick={() =>
                                                    router.post(
                                                        teams.billing.portal.url(
                                                            { team: team.slug },
                                                        ),
                                                    )
                                                }
                                            >
                                                Upgrade to Team
                                            </Button>
                                        ) : (
                                            <Button
                                                disabled={!stripeConfigured}
                                                className="max-w-full whitespace-normal"
                                                onClick={() =>
                                                    startCheckout('team')
                                                }
                                            >
                                                Upgrade to Team ·{' '}
                                                {yearly
                                                    ? prices.teamYearly
                                                    : prices.teamMonthly}
                                            </Button>
                                        ))}
                                </div>
                                <p className="text-sm break-words text-muted-foreground">
                                    {team.plan === 'team' && !team.onTrial ? (
                                        <>
                                            Need more than 10 people, SAML SSO,
                                            or audit export? Contact{' '}
                                            <a
                                                className="break-all underline"
                                                href={`mailto:${prices.businessContact}`}
                                            >
                                                {prices.businessContact}
                                            </a>{' '}
                                            about Enterprise.
                                        </>
                                    ) : (
                                        <>
                                            Pro is solo. Team covers up to 10
                                            people; AI bots stay free. Over 10
                                            or need SAML SSO? Contact{' '}
                                            <a
                                                className="break-all underline"
                                                href={`mailto:${prices.businessContact}`}
                                            >
                                                {prices.businessContact}
                                            </a>{' '}
                                            about Enterprise.
                                        </>
                                    )}
                                </p>
                            </div>
                        )}
                        {(!paid ||
                            team.onTrial ||
                            (team.plan === 'pro' && !team.hasSubscription)) &&
                            !stripeConfigured && (
                                <p className="text-sm text-muted-foreground">
                                    Payments are not enabled on this environment
                                    yet.
                                </p>
                            )}
                        {(team.hasSubscription || team.onTrial) &&
                            canManage && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                teams.billing.portal.url({
                                                    team: team.slug,
                                                }),
                                            )
                                        }
                                    >
                                        Payment method &amp; invoices
                                    </Button>
                                    {paid &&
                                        team.hasSubscription &&
                                        isOwner &&
                                        (team.cancelAtPeriodEnd ? (
                                            <Button
                                                onClick={() =>
                                                    router.post(
                                                        teams.billing.resume.url(
                                                            {
                                                                team: team.slug,
                                                            },
                                                        ),
                                                    )
                                                }
                                            >
                                                Resume subscription
                                            </Button>
                                        ) : (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setConfirmingCancel(true)
                                                }
                                            >
                                                Cancel subscription
                                            </Button>
                                        ))}
                                </div>
                            )}
                        {paid && !team.hasSubscription && !team.onTrial && (
                            <p className="text-sm text-muted-foreground">
                                Managed outside Stripe — no payment method or
                                self-serve invoices on file.
                            </p>
                        )}
                        {!paid && !team.onTrial && (
                            <p className="text-sm text-muted-foreground">
                                No payment method on file while on the Free
                                plan.
                            </p>
                        )}
                        {!canManage && (
                            <p className="text-sm text-muted-foreground">
                                Only admins can change the plan.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {(invoices.length > 0 ||
                    team.hasSubscription ||
                    team.onTrial) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Invoices</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {invoices.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No invoices yet. Invoices will appear here
                                    once billed.
                                </p>
                            ) : (
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
                                                key={
                                                    invoice.number ??
                                                    invoice.date
                                                }
                                            >
                                                <TableCell>
                                                    {formatLocalDate(
                                                        invoice.date * 1000,
                                                        reader,
                                                        {},
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {invoice.number}
                                                </TableCell>
                                                <TableCell>
                                                    {(
                                                        invoice.amount / 100
                                                    ).toFixed(2)}{' '}
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
                            )}
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
                                detail={`${size(usage.storageBytes)} / ${size(usage.storageLimitBytes)}`}
                                percent={usage.storagePercent}
                                warning={usage.storageWarning}
                                exceeded={usage.storageExceeded}
                            />
                            <Meter
                                label="Artifacts"
                                detail={`${formatLocalNumber(usage.artifactsThisPeriod, reader)} / ${formatLocalNumber(usage.artifactsLimit, reader)}`}
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
                    <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {(
                            [
                                {
                                    key: 'free',
                                    title: 'Free',
                                    price: '$0',
                                    highlights: [
                                        '1 person',
                                        'Standard artifact sharing',
                                    ],
                                },
                                {
                                    key: 'pro',
                                    title: 'Pro',
                                    price: yearly
                                        ? prices.proYearly
                                        : prices.proMonthly,
                                    highlights: [
                                        '1 person',
                                        'AI bot teammates & search by description',
                                        'Custom web address',
                                    ],
                                },
                                {
                                    key: 'team',
                                    title: 'Team',
                                    price: yearly
                                        ? prices.teamYearly
                                        : prices.teamMonthly,
                                    highlights: [
                                        'Up to 10 people (AI bots free)',
                                        'Everything in Pro',
                                    ],
                                },
                                {
                                    key: 'enterprise',
                                    title: 'Enterprise',
                                    price: 'Custom',
                                    highlights: [
                                        'Unlimited people',
                                        'SAML SSO & domain verification',
                                        'Custom retention, legal holds & SIEM audit export',
                                    ],
                                },
                            ] as const
                        ).map((tier) => {
                            const isCurrent =
                                team.plan === tier.key && !team.onTrial;

                            return (
                                <div
                                    key={tier.key}
                                    className={`flex flex-col rounded-md border p-4 text-sm ${
                                        isCurrent
                                            ? 'border-[var(--accent)] bg-tint/40'
                                            : 'border-border'
                                    }`}
                                >
                                    <div className="mb-1 flex items-center justify-between gap-2">
                                        <p className="font-semibold">
                                            {tier.title}
                                        </p>
                                        {isCurrent && (
                                            <Badge variant="success">
                                                Current
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="mb-3 text-xs font-medium text-muted-foreground">
                                        {tier.price}
                                    </p>
                                    <ul className="flex flex-1 flex-col gap-1 text-muted-foreground">
                                        {tier.highlights.map((item) => (
                                            <li key={item}>{item}</li>
                                        ))}
                                        <li>
                                            {size(
                                                limits[tier.key].storage_bytes,
                                            )}{' '}
                                            storage
                                        </li>
                                        <li>
                                            {formatLocalNumber(
                                                limits[tier.key]
                                                    .artifacts_per_month,
                                                reader,
                                            )}{' '}
                                            artifacts per month
                                        </li>
                                        <li>
                                            {size(
                                                limits[tier.key]
                                                    .bundle_size_ceiling_bytes,
                                            )}{' '}
                                            per artifact
                                        </li>
                                    </ul>
                                    {tier.key === 'enterprise' &&
                                        team.plan !== 'enterprise' && (
                                            <a
                                                className="mt-3 text-xs font-medium text-[var(--accent)] underline underline-offset-2"
                                                href={`mailto:${prices.businessContact}?subject=${encodeURIComponent(`Enterprise plan for ${team.name}`)}`}
                                            >
                                                Contact sales
                                            </a>
                                        )}
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>
            </div>

            <ConfirmDialog
                open={confirmingCancel}
                onOpenChange={setConfirmingCancel}
                title="Cancel this subscription?"
                description={`${team.name} keeps the ${PLAN_DISPLAY_NAMES[team.plan]} plan until ${periodEnd}. You can resume before then.`}
                confirmLabel="Cancel subscription"
                onConfirm={cancelSubscription}
                processing={cancelling}
            />
        </>
    );
}

Billing.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
