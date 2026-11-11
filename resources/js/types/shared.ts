export type SharedTeam = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role: string | null;
    roleLabel: string | null;
    isCurrent: boolean | null;
};

export type PlanTier = 'free' | 'pro' | 'team_trialing' | 'team' | 'business';

export type EntitlementNavItem = {
    key: string;
    visible: boolean;
    locked: boolean;
    label: string;
    reason: string | null;
};

export type Entitlements = {
    plan: 'free' | 'pro' | 'team' | 'enterprise';
    planTier: PlanTier;
    role: 'admin' | 'member' | 'viewer' | null;
    isBot: boolean;
    isAdmin: boolean;
    capabilities: Record<string, boolean>;
    nav: EntitlementNavItem[];
} | null;

export type CurrentTeam = {
    id?: number;
    slug: string;
    name: string;
    plan: 'free' | 'pro' | 'team' | 'enterprise';
    planTier?: PlanTier;
    paymentStatus: 'active' | 'past_due';
    isPersonal?: boolean;
    isOwner: boolean;
    role: 'admin' | 'member' | 'viewer' | null;
    isAdmin: boolean;
    onTrial?: boolean;
    trialDaysLeft?: number | null;
    trialEndsAt?: string | null;
} | null;

export type AnalyticsShared = {
    enabled: boolean;
    apiKey: string | null;
    host: string | null;
    environment: string | null;
};

export type QuotaDimension = {
    used: number;
    limit: number;
    percent: number;
    warning: boolean;
    exceeded: boolean;
};

export type Quota = {
    warning: boolean;
    exceeded: boolean;
    storage: QuotaDimension;
    artifacts: QuotaDimension;
} | null;

export type SharedProps = {
    name: string;
    auth: { user: { id: number; name: string; email: string } | null };
    teams: SharedTeam[];
    currentTeam: CurrentTeam;
    entitlements?: Entitlements;
    quota: Quota;
    analytics?: AnalyticsShared;
};
