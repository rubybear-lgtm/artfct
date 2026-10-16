import { useForm } from '@inertiajs/react';
import { useId, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

import { formatDateTime, humanize } from '@/components/ops/format';
import type {
    ErasurePlan,
    TenantActions,
    TenantDetail,
} from '@/components/ops/types';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    erase as eraseTenant,
    limits as pushLimits,
    plan as changePlan,
    reindex as reindexTenant,
} from '@/routes/ops/tenants';
import { preview as previewErasure } from '@/routes/ops/tenants/erase';

const REASON_MIN = 5;
const REASON_MAX = 500;

const controlClass =
    'w-full rounded-lg border border-border bg-transparent px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50 max-md:min-h-[44px] max-md:text-base';

function isReasonValid(reason: string): boolean {
    return reason.trim().length >= REASON_MIN;
}

function FieldError({ id, message }: { id: string; message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p id={id} role="alert" className="mt-1 text-sm text-destructive">
            {message}
        </p>
    );
}

/** Guard failures arrive under `action`, which is not a form field. */
function guardError(errors: object): string | undefined {
    return (errors as Record<string, string | undefined>).action;
}

function FormGuardError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <Alert variant="destructive" role="alert">
            {message}
        </Alert>
    );
}

/** Joins the ids that exist into an `aria-describedby` value. */
function describedBy(...ids: (string | false | undefined | null)[]) {
    const present = ids.filter((id): id is string => Boolean(id));

    return present.length > 0 ? present.join(' ') : undefined;
}

function ReasonField({
    value,
    onChange,
    error,
    noticeId,
}: {
    value: string;
    onChange: (value: string) => void;
    error?: string;
    /** The id of the visible "reason is visible to admins" notice. */
    noticeId: string;
}) {
    const id = useId();
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;

    return (
        <div>
            <Label htmlFor={id}>Reason</Label>
            <textarea
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                rows={3}
                minLength={REASON_MIN}
                maxLength={REASON_MAX}
                required
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy(
                    hintId,
                    noticeId,
                    error && errorId,
                )}
                className={`${controlClass} mt-1.5 py-2`}
            />
            <div
                id={hintId}
                className="mt-1 flex flex-wrap justify-between gap-x-4 text-xs text-muted-foreground"
            >
                <span>
                    {REASON_MIN} to {REASON_MAX} characters.
                </span>
                <span className="tabular-nums">
                    {value.length} / {REASON_MAX}
                </span>
            </div>
            <FieldError id={errorId} message={error} />
        </div>
    );
}

const REASON_HINT = 'Enter a reason of at least 5 characters';

/**
 * The visible notice, the submit button and, while the button is disabled,
 * a visible hint saying what is missing. The caller gives the button
 * `aria-describedby={hint ? hintId : undefined}`.
 */
function SubmitRow({
    noticeId,
    hintId,
    hint,
    children,
}: {
    noticeId: string;
    hintId: string;
    hint: string | null;
    children: ReactNode;
}) {
    return (
        <div>
            <p id={noticeId} className="mb-3 text-sm text-muted-foreground">
                This reason is visible to the customer&rsquo;s admins.
            </p>
            {children}
            {hint && (
                <p id={hintId} className="mt-2 text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
        </div>
    );
}

function ActionBlock({
    title,
    description,
    enabled,
    unavailableReason,
    children,
}: {
    title: string;
    description: string;
    enabled: boolean;
    unavailableReason: string;
    children: ReactNode;
}) {
    return (
        <div className="border-b border-border py-6 first:pt-0 last:border-b-0 last:pb-0">
            <h3 className="font-serif text-lg tracking-tight">{title}</h3>
            <p className="mt-1 mb-4 text-sm text-muted-foreground">
                {description}
            </p>
            {!enabled && (
                <p className="mb-4 text-sm font-medium">{unavailableReason}</p>
            )}
            <fieldset disabled={!enabled} className="min-w-0 space-y-4">
                {children}
            </fieldset>
        </div>
    );
}

type ReindexScope = 'tenant' | 'artifact';

function ReindexForm({ slug }: { slug: string }) {
    const form = useForm<{
        scope: ReindexScope;
        artifact_id: string;
        reason: string;
    }>({ scope: 'tenant', artifact_id: '', reason: '' });
    const scopeId = useId();
    const scopeErrorId = `${scopeId}-error`;
    const artifactId = useId();
    const artifactErrorId = `${artifactId}-error`;
    const noticeId = useId();
    const hintId = useId();
    const isOneArtifact = form.data.scope === 'artifact';
    const missing = !isReasonValid(form.data.reason)
        ? REASON_HINT
        : isOneArtifact && form.data.artifact_id.trim() === ''
          ? 'Enter the artifact ID'
          : null;
    const canSubmit = !form.processing && missing === null;
    const hint = form.processing ? null : missing;

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!canSubmit) {
            return;
        }

        form.transform((data) =>
            data.scope === 'artifact'
                ? {
                      scope: data.scope,
                      artifact_id: data.artifact_id.trim(),
                      reason: data.reason,
                  }
                : { scope: data.scope, reason: data.reason },
        );
        form.post(reindexTenant.url(slug), {
            preserveScroll: true,
            onSuccess: () => form.reset('reason'),
        });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div>
                <Label htmlFor={scopeId}>Scope</Label>
                <select
                    id={scopeId}
                    value={form.data.scope}
                    onChange={(event) =>
                        form.setData(
                            'scope',
                            event.target.value as ReindexScope,
                        )
                    }
                    aria-invalid={form.errors.scope ? true : undefined}
                    aria-describedby={describedBy(
                        form.errors.scope && scopeErrorId,
                    )}
                    className={`${controlClass} mt-1.5 h-9`}
                >
                    <option value="tenant">Whole tenant</option>
                    <option value="artifact">One artifact</option>
                </select>
                <FieldError id={scopeErrorId} message={form.errors.scope} />
            </div>
            {isOneArtifact && (
                <div>
                    <Label htmlFor={artifactId}>Artifact ID</Label>
                    <Input
                        id={artifactId}
                        value={form.data.artifact_id}
                        onChange={(event) =>
                            form.setData('artifact_id', event.target.value)
                        }
                        autoComplete="off"
                        spellCheck={false}
                        aria-invalid={
                            form.errors.artifact_id ? true : undefined
                        }
                        aria-describedby={describedBy(
                            form.errors.artifact_id && artifactErrorId,
                        )}
                        className="mt-1.5"
                    />
                    <FieldError
                        id={artifactErrorId}
                        message={form.errors.artifact_id}
                    />
                </div>
            )}
            <ReasonField
                value={form.data.reason}
                onChange={(value) => form.setData('reason', value)}
                error={form.errors.reason}
                noticeId={noticeId}
            />
            <FormGuardError message={guardError(form.errors)} />
            <SubmitRow noticeId={noticeId} hintId={hintId} hint={hint}>
                <Button
                    type="submit"
                    disabled={!canSubmit}
                    aria-describedby={hint ? hintId : undefined}
                >
                    {form.processing ? 'Reindexing' : 'Reindex'}
                </Button>
            </SubmitRow>
        </form>
    );
}

function LimitsForm({ slug }: { slug: string }) {
    const form = useForm({ reason: '' });
    const noticeId = useId();
    const hintId = useId();
    const missing = isReasonValid(form.data.reason) ? null : REASON_HINT;
    const canSubmit = !form.processing && missing === null;
    const hint = form.processing ? null : missing;

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!canSubmit) {
            return;
        }

        form.post(pushLimits.url(slug), {
            preserveScroll: true,
            onSuccess: () => form.reset('reason'),
        });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <ReasonField
                value={form.data.reason}
                onChange={(value) => form.setData('reason', value)}
                error={form.errors.reason}
                noticeId={noticeId}
            />
            <FormGuardError message={guardError(form.errors)} />
            <SubmitRow noticeId={noticeId} hintId={hintId} hint={hint}>
                <Button
                    type="submit"
                    disabled={!canSubmit}
                    aria-describedby={hint ? hintId : undefined}
                >
                    {form.processing ? 'Pushing' : 'Push limits to the Worker'}
                </Button>
            </SubmitRow>
        </form>
    );
}

function withCurrent(options: string[], current: string): string[] {
    return options.includes(current) ? options : [current, ...options];
}

function PlanForm({
    slug,
    tenant,
    billing,
}: {
    slug: string;
    tenant: Pick<TenantDetail, 'plan' | 'payment_status'>;
    billing: TenantActions['billing'];
}) {
    const form = useForm({
        plan: tenant.plan,
        payment_status: tenant.payment_status,
        confirm: false,
        reason: '',
    });
    const planId = useId();
    const statusId = useId();
    const confirmId = useId();
    const confirmErrorId = `${confirmId}-error`;
    const planErrorId = `${planId}-error`;
    const statusErrorId = `${statusId}-error`;
    const noticeId = useId();
    const hintId = useId();

    const changes: { label: string; from: string; to: string }[] = [];

    if (form.data.plan !== tenant.plan) {
        changes.push({
            label: 'Plan',
            from: humanize(tenant.plan),
            to: humanize(form.data.plan),
        });
    }

    if (form.data.payment_status !== tenant.payment_status) {
        changes.push({
            label: 'Payment status',
            from: humanize(tenant.payment_status),
            to: humanize(form.data.payment_status),
        });
    }

    const hasChanges = changes.length > 0;
    const missing = !hasChanges
        ? 'Change the plan or payment status first'
        : !form.data.confirm
          ? 'Review the change summary and tick the box'
          : !isReasonValid(form.data.reason)
            ? REASON_HINT
            : null;
    const canSubmit = !form.processing && missing === null;
    const hint = form.processing ? null : missing;

    function choose(field: 'plan' | 'payment_status', value: string) {
        form.setData((previous) => ({
            ...previous,
            [field]: value,
            confirm: false,
        }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!canSubmit) {
            return;
        }

        form.post(changePlan.url(slug), {
            preserveScroll: true,
            onSuccess: () => form.reset('reason', 'confirm'),
        });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor={planId}>Plan</Label>
                    <select
                        id={planId}
                        value={form.data.plan}
                        onChange={(event) => choose('plan', event.target.value)}
                        aria-invalid={form.errors.plan ? true : undefined}
                        aria-describedby={describedBy(
                            form.errors.plan && planErrorId,
                        )}
                        className={`${controlClass} mt-1.5 h-9`}
                    >
                        {withCurrent(billing.plans, tenant.plan).map((plan) => (
                            <option key={plan} value={plan}>
                                {humanize(plan)}
                            </option>
                        ))}
                    </select>
                    <FieldError id={planErrorId} message={form.errors.plan} />
                </div>
                <div>
                    <Label htmlFor={statusId}>Payment status</Label>
                    <select
                        id={statusId}
                        value={form.data.payment_status}
                        onChange={(event) =>
                            choose('payment_status', event.target.value)
                        }
                        aria-invalid={
                            form.errors.payment_status ? true : undefined
                        }
                        aria-describedby={describedBy(
                            form.errors.payment_status && statusErrorId,
                        )}
                        className={`${controlClass} mt-1.5 h-9`}
                    >
                        {withCurrent(
                            billing.payment_statuses,
                            tenant.payment_status,
                        ).map((paymentStatus) => (
                            <option key={paymentStatus} value={paymentStatus}>
                                {humanize(paymentStatus)}
                            </option>
                        ))}
                    </select>
                    <FieldError
                        id={statusErrorId}
                        message={form.errors.payment_status}
                    />
                </div>
            </div>

            {hasChanges && (
                <div className="border-y border-border py-3">
                    <h4 className="mb-2 text-sm font-medium">Change summary</h4>
                    <ul className="space-y-1 text-sm">
                        {changes.map((change) => (
                            <li key={change.label}>
                                <span className="text-muted-foreground">
                                    {change.label}:
                                </span>{' '}
                                {change.from} &rarr; {change.to}
                            </li>
                        ))}
                    </ul>
                    <div className="mt-3">
                        <label
                            htmlFor={confirmId}
                            className="flex items-start gap-3 text-sm max-md:min-h-[44px] max-md:items-center"
                        >
                            <input
                                id={confirmId}
                                type="checkbox"
                                checked={form.data.confirm}
                                onChange={(event) =>
                                    form.setData(
                                        'confirm',
                                        event.target.checked,
                                    )
                                }
                                aria-invalid={
                                    form.errors.confirm ? true : undefined
                                }
                                aria-describedby={describedBy(
                                    form.errors.confirm && confirmErrorId,
                                )}
                                className="mt-0.5 size-5 shrink-0 accent-primary max-md:mt-0"
                            />
                            <span>I have reviewed this change</span>
                        </label>
                        <FieldError
                            id={confirmErrorId}
                            message={form.errors.confirm}
                        />
                    </div>
                </div>
            )}

            <ReasonField
                value={form.data.reason}
                onChange={(value) => form.setData('reason', value)}
                error={form.errors.reason}
                noticeId={noticeId}
            />
            <FormGuardError message={guardError(form.errors)} />
            <SubmitRow noticeId={noticeId} hintId={hintId} hint={hint}>
                <Button
                    type="submit"
                    disabled={!canSubmit}
                    aria-describedby={hint ? hintId : undefined}
                >
                    {form.processing ? 'Saving' : 'Change plan'}
                </Button>
            </SubmitRow>
        </form>
    );
}

function EraseForm({
    slug,
    erasurePlan,
}: {
    slug: string;
    erasurePlan: ErasurePlan | null;
}) {
    const previewForm = useForm({});
    const form = useForm({ confirm_slug: '', reason: '' });
    const [confirming, setConfirming] = useState(false);
    const slugId = useId();
    const slugErrorId = `${slugId}-error`;
    const noticeId = useId();
    const hintId = useId();

    const isBlocked =
        erasurePlan !== null && (erasurePlan.refused || erasurePlan.held);
    const missing = isBlocked
        ? 'This tenant cannot be erased'
        : form.data.confirm_slug !== slug
          ? 'Type the slug exactly'
          : !isReasonValid(form.data.reason)
            ? REASON_HINT
            : null;
    const canErase =
        erasurePlan !== null && !form.processing && missing === null;
    const hint = form.processing ? null : missing;

    // Without a current dry run only step 1 is shown, so a failure from the
    // erase request (for example "dry run is out of date") is reported there.
    const stepOneError =
        guardError(previewForm.errors) ??
        (erasurePlan === null
            ? (guardError(form.errors) ??
              form.errors.confirm_slug ??
              form.errors.reason)
            : undefined);

    function runPreview() {
        previewForm.post(previewErasure.url(slug), {
            preserveScroll: true,
            onSuccess: () => form.clearErrors(),
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (canErase) {
            setConfirming(true);
        }
    }

    function erase() {
        form.post(eraseTenant.url(slug), {
            preserveScroll: true,
            onFinish: () => setConfirming(false),
        });
    }

    return (
        <div className="space-y-6">
            <div className="space-y-3">
                <h4 className="text-sm font-medium">Step 1. Dry run</h4>
                <p className="text-sm text-muted-foreground">
                    Checks what erasing this tenant would remove. Nothing is
                    changed.
                </p>
                <FormGuardError message={stepOneError} />
                <Button
                    type="button"
                    variant="outline"
                    onClick={runPreview}
                    disabled={previewForm.processing}
                >
                    {previewForm.processing ? 'Running dry run' : 'Run dry run'}
                </Button>
            </div>

            {erasurePlan !== null && (
                <div
                    className={`border-y py-3 text-sm ${
                        isBlocked ? 'border-destructive/40' : 'border-border'
                    }`}
                >
                    <h4 className="mb-1 font-medium">Dry run result</h4>
                    {erasurePlan.refused && (
                        <p className="font-medium text-destructive">
                            Blocked: the dry run refused this erasure.
                        </p>
                    )}
                    {erasurePlan.held && (
                        <p className="font-medium text-destructive">
                            Blocked: this tenant is on hold and cannot be
                            erased.
                        </p>
                    )}
                    <p>
                        {erasurePlan.artifact_count.toLocaleString('en-US')}{' '}
                        {erasurePlan.artifact_count === 1
                            ? 'artifact'
                            : 'artifacts'}{' '}
                        would be erased.
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Checked {formatDateTime(erasurePlan.generated_at)}.
                    </p>
                </div>
            )}

            {erasurePlan !== null && (
                <form onSubmit={submit}>
                    <fieldset
                        disabled={isBlocked}
                        className="min-w-0 space-y-4"
                    >
                        <h4 className="text-sm font-medium">
                            Step 2. Erase the tenant
                        </h4>
                        <p className="text-sm text-muted-foreground">
                            This cannot be undone.
                        </p>
                        <div>
                            <Label htmlFor={slugId}>
                                Type the tenant slug to confirm:{' '}
                                <span className="font-mono break-all">
                                    {slug}
                                </span>
                            </Label>
                            <Input
                                id={slugId}
                                value={form.data.confirm_slug}
                                onChange={(event) =>
                                    form.setData(
                                        'confirm_slug',
                                        event.target.value,
                                    )
                                }
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                aria-invalid={
                                    form.errors.confirm_slug ? true : undefined
                                }
                                aria-describedby={describedBy(
                                    form.errors.confirm_slug && slugErrorId,
                                )}
                                className="mt-1.5"
                            />
                            <FieldError
                                id={slugErrorId}
                                message={form.errors.confirm_slug}
                            />
                        </div>
                        <ReasonField
                            value={form.data.reason}
                            onChange={(value) => form.setData('reason', value)}
                            error={form.errors.reason}
                            noticeId={noticeId}
                        />
                        <FormGuardError message={guardError(form.errors)} />
                        <SubmitRow
                            noticeId={noticeId}
                            hintId={hintId}
                            hint={hint}
                        >
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={!canErase}
                                aria-describedby={hint ? hintId : undefined}
                            >
                                Erase tenant
                            </Button>
                        </SubmitRow>
                    </fieldset>
                </form>
            )}

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title="Erase this tenant?"
                description={
                    <>
                        This permanently erases <strong>{slug}</strong> and all
                        of its artifacts. This cannot be undone.
                    </>
                }
                confirmLabel="Erase tenant"
                cancelLabel="Keep tenant"
                onConfirm={erase}
                processing={form.processing}
            />
        </div>
    );
}

function unavailableReasonFor(
    tenant: TenantDetail,
    action: 'reindex' | 'other',
): string {
    if (tenant.deleted_at !== null) {
        return 'Not available: this tenant has been deleted.';
    }

    if (action === 'reindex') {
        return 'Not available: indexing is turned off.';
    }

    return 'Not available for this tenant right now.';
}

export function ActionsPanel({
    tenant,
    actions,
    erasurePlan,
    status,
}: {
    tenant: TenantDetail;
    actions: TenantActions;
    erasurePlan: ErasurePlan | null;
    status: string | null;
}) {
    const unavailable = unavailableReasonFor(tenant, 'other');

    return (
        <div>
            <div role="status" aria-live="polite">
                {status && (
                    <p className="mb-6 border-l-2 border-primary pl-3 text-sm">
                        {status}
                    </p>
                )}
            </div>

            <ActionBlock
                title="Reindex search"
                description="Rebuild the search index for the whole tenant or for one artifact."
                enabled={actions.reindex.enabled}
                unavailableReason={unavailableReasonFor(tenant, 'reindex')}
            >
                <ReindexForm slug={tenant.slug} />
            </ActionBlock>

            <ActionBlock
                title="Limits"
                description="Send this tenant's current plan limits to the Worker."
                enabled={actions.limits.enabled}
                unavailableReason={unavailable}
            >
                <LimitsForm slug={tenant.slug} />
            </ActionBlock>

            <ActionBlock
                title="Plan and payment status"
                description="Change the plan or payment status on record for this tenant."
                enabled={actions.billing.enabled}
                unavailableReason={unavailable}
            >
                <PlanForm
                    slug={tenant.slug}
                    tenant={tenant}
                    billing={actions.billing}
                />
            </ActionBlock>

            <ActionBlock
                title="Erase tenant"
                description="Permanently erase this tenant and all of its artifacts. Run a dry run first."
                enabled={actions.erase.enabled}
                unavailableReason={unavailable}
            >
                <EraseForm slug={tenant.slug} erasurePlan={erasurePlan} />
            </ActionBlock>
        </div>
    );
}
