import { Button } from '@/components/ui/button';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base0: 'var(--sol-base0)',
    red: 'var(--sol-red)',
    cyan: 'var(--sol-cyan)',
    blue: 'var(--sol-blue)',
    green: 'var(--sol-green)',
} as const;

export interface CachedLink {
    id: string;
    url: string;
    expiresAt: string;
    filename: string;
    deployedAt: string;
}

export function timeUntil(iso: string): string {
    const ms = new Date(iso).getTime() - Date.now();
    const min = Math.round(ms / 60_000);

    if (min <= 0) {
        return 'expired';
    }

    if (min < 60) {
        return `${min} min`;
    }

    const hours = Math.round(min / 60);

    if (hours < 24) {
        return `${hours}h`;
    }

    const days = Math.round(hours / 24);

    if (days < 30) {
        return `${days}d`;
    }

    const months = Math.round(days / 30);

    if (months < 12) {
        return `${months}mo`;
    }

    return `${Math.round(months / 12)}y`;
}

interface WelcomeCachedLinksProps {
    links: CachedLink[];
    onClear: () => void;
    onManage: (link: CachedLink) => void;
}

export function WelcomeCachedLinks({
    links,
    onClear,
    onManage,
}: WelcomeCachedLinksProps) {
    if (links.length === 0) {
        return null;
    }

    return (
        <div
            style={{
                width: '100%',
                display: 'flex',
                flexDirection: 'column',
                gap: '0.75rem',
                marginTop: '1rem',
                animation: 'fadeSlideUp 0.25s ease-out both',
            }}
        >
            <h3
                style={{
                    fontFamily: MONO,
                    fontSize: '13px',
                    color: S.base0,
                    margin: 0,
                    borderBottom: `1px solid ${S.base2}`,
                    paddingBottom: '0.5rem',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                }}
            >
                <span>recent deployments</span>
                <Button
                    onClick={onClear}
                    style={{
                        background: 'none',
                        border: 'none',
                        color: S.base1,
                        cursor: 'pointer',
                        fontSize: '11px',
                        textDecoration: 'underline',
                        fontFamily: 'inherit',
                    }}
                >
                    clear history
                </Button>
            </h3>
            <div
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: '0.5rem',
                }}
            >
                {links.map((link) => {
                    const time = timeUntil(link.expiresAt);
                    const isExpired = time === 'expired';

                    return (
                        <div
                            key={link.id}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                padding: '0.6rem 0.8rem',
                                backgroundColor: S.base2,
                                border: `1px solid ${isExpired ? S.red : S.base2}`,
                                fontFamily: MONO,
                                fontSize: '13px',
                                boxSizing: 'border-box',
                            }}
                        >
                            <div
                                style={{
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: '0.2rem',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                    whiteSpace: 'nowrap',
                                    flex: 1,
                                    marginRight: '1rem',
                                }}
                            >
                                <span
                                    style={{
                                        color: S.cyan,
                                        fontWeight: 'bold',
                                        overflow: 'hidden',
                                        textOverflow: 'ellipsis',
                                        whiteSpace: 'nowrap',
                                    }}
                                >
                                    {link.filename}
                                </span>
                                <a
                                    href={link.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    style={{
                                        color: isExpired ? S.base1 : S.blue,
                                        textDecoration: 'none',
                                        overflow: 'hidden',
                                        textOverflow: 'ellipsis',
                                        whiteSpace: 'nowrap',
                                    }}
                                >
                                    {link.url}
                                </a>
                            </div>
                            <div
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: '0.75rem',
                                    flexShrink: 0,
                                }}
                            >
                                <span
                                    style={{
                                        fontSize: '11px',
                                        color: isExpired ? S.red : S.green,
                                    }}
                                >
                                    {isExpired
                                        ? 'expired'
                                        : `expires in ${time}`}
                                </span>
                                <Button
                                    onClick={() => onManage(link)}
                                    className="manage-btn"
                                    style={{
                                        padding: '0.25rem 0.6rem',
                                        backgroundColor: 'transparent',
                                        color: S.base0,
                                        border: `1px solid ${S.base1}`,
                                        cursor: 'pointer',
                                        fontSize: '11px',
                                        fontFamily: MONO,
                                        letterSpacing: '0.04em',
                                    }}
                                >
                                    manage
                                </Button>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
