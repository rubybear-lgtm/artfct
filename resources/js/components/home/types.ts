export interface PendingInvitation {
    code: string;
    inviterName: string;
    team: { name: string; slug: string };
}

export interface SetupProgress {
    invitedTeammates: boolean;
    createdToken: boolean;
    connectedMcp: boolean;
    choseAPlan: boolean;
}

export interface HomeFilters {
    q: string;
    collection: string;
    since: string;
    scope: 'team' | 'mine';
}

export interface HomeSearchResult {
    id: string;
    title: string;
    description: string | null;
    openUrl: string;
    snippet: string;
    agent: string | null;
    canonical: boolean;
}

export interface HomeRecentArtifact {
    id: string;
    title: string;
    description: string | null;
    createdAt: string;
    authorName: string | null;
    agent: string | null;
    openUrl: string;
    revoked: boolean;
}

export interface HomeCollection {
    id: number;
    name: string;
    canonical: boolean;
    artifactCount: number;
}

export interface HomeFilterCollection {
    name: string;
    canonical: boolean;
}

export interface HomeData {
    team: { slug: string; name: string };
    indexingEnabled: boolean;
    canOpenArtifacts: boolean;
    filters: HomeFilters;
    searched: boolean;
    results: HomeSearchResult[];
    searchError: string | null;
    recent: HomeRecentArtifact[];
    recentError: string | null;
    collections: HomeCollection[];
    collectionCount: number;
    filterCollections: HomeFilterCollection[];
}

export interface DashboardProps {
    pendingInvitations: PendingInvitation[];
    setup: SetupProgress | null;
    home: HomeData | null;
}
