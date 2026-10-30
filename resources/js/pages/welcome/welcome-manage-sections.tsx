import { useState } from 'react';

import { Button } from '@/components/ui/button';
import type { CachedLink } from '@/pages/welcome/welcome-cached-links';
import { timeUntil } from '@/pages/welcome/welcome-cached-links';

const DEFAULT_TTL_MINUTES = 5 * 24 * 60;
const MAX_TTL_MINUTES = 365 * 24 * 60;

const TTL_PRESETS = [
    15,
    60,
    360,
    1440,
    DEFAULT_TTL_MINUTES,
    10080,
    43200,
    MAX_TTL_MINUTES,
] as const;

export function ManageDeploymentSummary({
    managingLink,
}: {
    managingLink: CachedLink;
}) {
    const expiration = timeUntil(managingLink.expiresAt);
    const statusClass =
        expiration === 'expired'
            ? 'welcome-manage-status is-expired'
            : 'welcome-manage-status is-active';

    return (
        <div className="welcome-manage-summary">
            <div className="welcome-manage-row">
                <span className="welcome-manage-label">file:</span>
                <span className="welcome-manage-value">
                    {managingLink.filename}
                </span>
            </div>
            <div className="welcome-manage-row welcome-manage-url-row">
                <span className="welcome-manage-label">url:</span>
                <a
                    href={managingLink.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="welcome-manage-url"
                >
                    {managingLink.url}
                </a>
            </div>
            <div className="welcome-manage-row">
                <span className="welcome-manage-label">status:</span>
                <span className={statusClass}>
                    {expiration === 'expired'
                        ? 'expired'
                        : `expires in ${expiration}`}
                </span>
            </div>
        </div>
    );
}

interface ManageDeploymentDurationProps {
    newTtlMinutes: number;
    setNewTtlMinutes: (minutes: number) => void;
    isUpdatingTtl: boolean;
    isDeletingLink: boolean;
    onUpdateTtl: () => void | Promise<void>;
}

export function ManageDeploymentDuration({
    newTtlMinutes,
    setNewTtlMinutes,
    isUpdatingTtl,
    isDeletingLink,
    onUpdateTtl,
}: ManageDeploymentDurationProps) {
    const [draft, setDraft] = useState(String(newTtlMinutes));
    const [syncedMinutes, setSyncedMinutes] = useState(newTtlMinutes);

    // Keep the clearable draft aligned when the parent opens another link.
    if (syncedMinutes !== newTtlMinutes) {
        setSyncedMinutes(newTtlMinutes);
        setDraft(String(newTtlMinutes));
    }

    const commitDraft = () => {
        if (draft === '') {
            setDraft(String(DEFAULT_TTL_MINUTES));
            setNewTtlMinutes(DEFAULT_TTL_MINUTES);

            return;
        }

        const parsed = Number.parseInt(draft, 10);

        if (Number.isNaN(parsed)) {
            setDraft(String(DEFAULT_TTL_MINUTES));
            setNewTtlMinutes(DEFAULT_TTL_MINUTES);

            return;
        }

        const clamped = Math.min(Math.max(parsed, 1), MAX_TTL_MINUTES);
        setDraft(String(clamped));
        setNewTtlMinutes(clamped);
    };

    return (
        <div className="welcome-manage-duration">
            <div className="welcome-manage-duration-label">
                <label htmlFor="welcome-ttl-minutes">
                    adjust duration (minutes from now)
                </label>
                <div className="welcome-manage-duration-controls">
                    <input
                        id="welcome-ttl-minutes"
                        type="number"
                        min={1}
                        max={MAX_TTL_MINUTES}
                        value={draft}
                        onChange={(e) => {
                            const raw = e.target.value;
                            setDraft(raw);

                            if (raw === '') {
                                return;
                            }

                            const parsed = Number.parseInt(raw, 10);

                            if (!Number.isNaN(parsed)) {
                                setNewTtlMinutes(
                                    Math.min(
                                        Math.max(parsed, 1),
                                        MAX_TTL_MINUTES,
                                    ),
                                );
                            }
                        }}
                        onBlur={commitDraft}
                        className="welcome-manage-duration-input"
                    />
                    <label htmlFor="welcome-ttl-preset" className="sr-only">
                        Duration preset
                    </label>
                    <select
                        id="welcome-ttl-preset"
                        value={
                            (TTL_PRESETS as readonly number[]).includes(
                                newTtlMinutes,
                            )
                                ? newTtlMinutes
                                : ''
                        }
                        onChange={(e) => {
                            if (!e.target.value) {
                                return;
                            }

                            const parsed = Number.parseInt(e.target.value, 10);
                            setDraft(String(parsed));
                            setNewTtlMinutes(parsed);
                        }}
                        className="welcome-manage-duration-select"
                    >
                        <option value="" disabled>
                            presets
                        </option>
                        <option value={15}>15 min</option>
                        <option value={60}>1 hour</option>
                        <option value={360}>6 hours</option>
                        <option value={1440}>24 hours</option>
                        <option value={DEFAULT_TTL_MINUTES}>5 days</option>
                        <option value={10080}>7 days</option>
                        <option value={43200}>30 days</option>
                        <option value={MAX_TTL_MINUTES}>365 days</option>
                    </select>
                </div>
            </div>

            <Button
                onClick={() => {
                    commitDraft();
                    void onUpdateTtl();
                }}
                disabled={isUpdatingTtl || isDeletingLink}
                className="welcome-manage-action welcome-manage-update"
            >
                {isUpdatingTtl ? 'updating...' : 'update duration'}
            </Button>
        </div>
    );
}

interface ManageDeploymentDangerProps {
    isUpdatingTtl: boolean;
    isDeletingLink: boolean;
    onDeleteLink: () => void | Promise<void>;
}

export function ManageDeploymentDanger({
    isUpdatingTtl,
    isDeletingLink,
    onDeleteLink,
}: ManageDeploymentDangerProps) {
    return (
        <div className="welcome-manage-danger">
            <div className="welcome-manage-danger-copy">
                danger zone: permanently delete deployment from server
            </div>
            <Button
                onClick={onDeleteLink}
                disabled={isUpdatingTtl || isDeletingLink}
                className="welcome-manage-action welcome-manage-delete"
            >
                {isDeletingLink ? 'deleting...' : 'delete deployment'}
            </Button>
        </div>
    );
}
