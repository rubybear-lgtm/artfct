export type FlowTierName = 'high' | 'medium' | 'low';

export type FlowTier = {
    name: FlowTierName;
    src: string;
    width: number;
    bytes: number;
};

export type FlowCut = {
    poster: string;
    tiers: FlowTier[];
};

export type FlowMedia = {
    wide?: FlowCut;
    phone?: FlowCut;
};

/** The parts of the Network Information API this page reads. */
export type FlowConnection = {
    saveData?: boolean;
    effectiveType?: string;
    downlink?: number;
};

const ORDER: FlowTierName[] = ['high', 'medium', 'low'];

/** Sorts a name so that a bigger number is a better tier. */
const rank = (name: FlowTierName): number => ORDER.length - ORDER.indexOf(name);

/**
 * The best tier the connection can carry. Browsers without the Network
 * Information API report nothing, which is read as a connection that can.
 */
function tierForConnection(connection?: FlowConnection | null): FlowTierName {
    if (!connection) {
        return 'high';
    }

    if (
        connection.saveData ||
        connection.effectiveType === 'slow-2g' ||
        connection.effectiveType === '2g'
    ) {
        return 'low';
    }

    if (connection.effectiveType === '3g') {
        return 'medium';
    }

    if (typeof connection.downlink === 'number' && connection.downlink > 0) {
        if (connection.downlink < 1.5) {
            return 'low';
        }

        if (connection.downlink < 4) {
            return 'medium';
        }
    }

    return 'high';
}

/**
 * The smallest tier that still fills the screen. A phone drawing the video
 * 390 css pixels wide has no use for the 2400 pixel render.
 */
function tierForScreen(tiers: FlowTier[], screenPixels: number): FlowTierName {
    const fitting = tiers
        .filter((tier) => tier.width >= screenPixels)
        .sort((a, b) => a.width - b.width)[0];

    const widest = [...tiers].sort((a, b) => b.width - a.width)[0];

    return (fitting ?? widest).name;
}

/**
 * Picks the tier to start with: the sharper of what the screen can show and
 * what the connection can carry, whichever is lower.
 */
export function pickTier(
    tiers: FlowTier[],
    screenPixels: number,
    connection?: FlowConnection | null,
): FlowTier | null {
    if (tiers.length === 0) {
        return null;
    }

    const wanted = [
        tierForScreen(tiers, screenPixels),
        tierForConnection(connection),
    ].sort((a, b) => rank(a) - rank(b))[0];

    return stepDownTo(tiers, wanted);
}

/** The best tier at or below `name` that this cut actually has. */
function stepDownTo(tiers: FlowTier[], name: FlowTierName): FlowTier {
    const available = tiers
        .filter((tier) => rank(tier.name) <= rank(name))
        .sort((a, b) => rank(b.name) - rank(a.name));

    return (
        available[0] ??
        [...tiers].sort((a, b) => rank(a.name) - rank(b.name))[0]
    );
}

/** The next tier down from `current`, or `null` when it is already the lowest. */
export function lowerTier(
    tiers: FlowTier[],
    current: FlowTierName,
): FlowTier | null {
    const below = tiers
        .filter((tier) => rank(tier.name) < rank(current))
        .sort((a, b) => rank(b.name) - rank(a.name));

    return below[0] ?? null;
}
