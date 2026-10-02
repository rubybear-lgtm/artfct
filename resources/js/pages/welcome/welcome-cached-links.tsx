import { Button } from '@/components/ui/button';

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

function CachedLinkRow({
    link,
    onManage,
}: {
    link: CachedLink;
    onManage: (link: CachedLink) => void;
}) {
    const time = timeUntil(link.expiresAt);
    const isExpired = time === 'expired';

    return (
        <div
            className={`welcome-cached-link-row ${isExpired ? 'is-expired' : ''}`}
        >
            <div className="welcome-cached-link-details">
                <span className="welcome-cached-link-filename">
                    {link.filename}
                </span>
                <a
                    href={link.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="welcome-cached-link-url"
                >
                    {link.url}
                </a>
            </div>
            <div className="welcome-cached-link-actions">
                <span className="welcome-cached-link-status">
                    {isExpired ? 'expired' : `expires in ${time}`}
                </span>
                <Button
                    onClick={() => onManage(link)}
                    className="manage-btn welcome-cached-link-manage"
                >
                    manage
                </Button>
            </div>
        </div>
    );
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
        <div className="welcome-cached-links">
            <h3 className="welcome-cached-links-heading">
                <span>recent deployments</span>
                <Button
                    onClick={onClear}
                    className="welcome-cached-links-clear"
                >
                    clear history
                </Button>
            </h3>
            <div className="welcome-cached-links-list">
                {links.map((link) => (
                    <CachedLinkRow
                        key={link.id}
                        link={link}
                        onManage={onManage}
                    />
                ))}
            </div>
        </div>
    );
}
