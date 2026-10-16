export type TenantState = 'active' | 'deleted' | 'provisioning_failed';

export interface TenantRow {
    slug: string;
    name: string;
    plan: string;
    payment_status: string;
    seats: number;
    region: string | null;
    release_version: string | null;
    schema_version: number | string | null;
    created_at: string;
    state: TenantState;
}

export interface TenantListFilters {
    q: string | null;
    plan: string | null;
    payment_status: string | null;
    region: string | null;
    provisioning_failed: boolean;
    past_due: boolean;
    cancelling: boolean;
    deleted: boolean;
}

export interface TenantListOptions {
    plans: string[];
    payment_statuses: string[];
    regions: string[];
}

export interface TenantDetail {
    slug: string;
    name: string;
    plan: string;
    payment_status: string;
    owner: string | null;
    stripe_customer_id: string | null;
    stripe_subscription_id: string | null;
    stripe_customer_url: string | null;
    stripe_subscription_url: string | null;
    seats_billed: number;
    retention_days: number | null;
    region: string | null;
    release_version: string | null;
    schema_version: number | null;
    provisioned_at: string | null;
    provisioning_failed_step: string | null;
    cancel_at_period_end: boolean;
    current_period_end: string | null;
    deleted_at: string | null;
    created_at: string | null;
}

export interface TenantMembers {
    total: number;
    admins: { name: string; email: string }[];
    pending_invitations: number;
}

export interface ConnectionUsage {
    active: number;
    last_used_at: string | null;
}

export interface TenantConnections {
    mcp: ConnectionUsage;
    org_tokens: ConnectionUsage;
}

export interface TenantSso {
    state: 'connected' | 'none' | 'unavailable';
    connections: { type: string; name: string }[];
}

export interface TenantSsoError {
    error: string;
}

export interface TenantUsage {
    storage_bytes: number;
    artifacts_this_period: number;
    period_start: string | null;
    period_end: string | null;
    limits: { storage_bytes: number; artifacts_per_month: number } | null;
}

export interface TenantUsageError {
    error: string;
}

export interface TenantEvent {
    id: number | string;
    type: string;
    type_label: string;
    actor_label: string;
    target: string | null;
    outcome: string;
    at: string;
}

export interface TenantActions {
    reindex: { enabled: boolean };
    limits: { enabled: boolean };
    billing: {
        enabled: boolean;
        plans: string[];
        payment_statuses: string[];
    };
    erase: { enabled: boolean };
}

export interface ErasurePlan {
    refused: boolean;
    artifact_count: number;
    held: boolean;
    generated_at: string;
}

export type ReadinessState = 'ready' | 'not configured' | 'incomplete';

export interface ReadinessCheck {
    name: string;
    state: ReadinessState;
    missing: string[];
}

export interface HealthProps {
    queue: { failed_jobs: number; pending_jobs: number };
    indexing: { failures: number };
    org_token_revocations: { pending: number };
    stripe: { last_received_at: string | null };
    worker_events: { last_received_at: string | null };
    readiness: ReadinessCheck[];
    staff_organization: { configured: boolean; id: string | null };
}
