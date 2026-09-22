export type SharedTeam = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role: string | null;
    roleLabel: string | null;
    isCurrent: boolean | null;
};

export type CurrentTeam = {
    slug: string;
    name: string;
    plan: 'free' | 'team' | 'enterprise';
    paymentStatus: 'active' | 'past_due';
    isOwner: boolean;
} | null;

export type SharedProps = {
    name: string;
    auth: { user: { id: number; name: string; email: string } | null };
    teams: SharedTeam[];
    currentTeam: CurrentTeam;
    quota: { warning: boolean; exceeded: boolean } | null;
};
