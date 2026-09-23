import type { RefObject } from 'react';
import { Button } from '@/components/ui/button';
import type { CachedLink } from '@/pages/welcome/welcome-cached-links';
import { timeUntil } from '@/pages/welcome/welcome-cached-links';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
const DEFAULT_TTL_MINUTES = 5 * 24 * 60;
const MAX_TTL_MINUTES = 365 * 24 * 60;

const S = {
    base3: 'var(--sol-base3)',
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base00: 'var(--sol-base00)',
    base0: 'var(--sol-base0)',
    red: 'var(--sol-red)',
    green: 'var(--sol-green)',
    blue: 'var(--sol-blue)',
    cyan: 'var(--sol-cyan)',
} as const;

interface WelcomeManageModalProps {
    managingLink: CachedLink;
    modalRef: RefObject<HTMLDivElement | null>;
    newTtlMinutes: number;
    setNewTtlMinutes: (minutes: number) => void;
    isUpdatingTtl: boolean;
    isDeletingLink: boolean;
    modalError: string | null;
    modalSuccess: boolean;
    onClose: () => void;
    onUpdateTtl: () => void | Promise<void>;
    onDeleteLink: () => void | Promise<void>;
}

export function WelcomeManageModal({
    managingLink,
    modalRef,
    newTtlMinutes,
    setNewTtlMinutes,
    isUpdatingTtl,
    isDeletingLink,
    modalError,
    modalSuccess,
    onClose,
    onUpdateTtl,
    onDeleteLink,
}: WelcomeManageModalProps) {
    return (
        <div
            style={{
                position: 'fixed',
                inset: 0,
                backgroundColor: 'rgba(0, 0, 0, 0.65)',
                backdropFilter: 'blur(4px)',
                zIndex: 1000,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: '1.5rem',
                boxSizing: 'border-box',
            }}
            onClick={onClose}
        >
            <div
                className="modal-content"
                ref={modalRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="manage-deployment-title"
                tabIndex={-1}
                style={{
                    backgroundColor: S.base3,
                    border: `1px solid ${S.base1}`,
                    width: '100%',
                    maxWidth: '460px',
                    padding: '1.8rem',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: '1.5rem',
                    position: 'relative',
                    boxSizing: 'border-box',
                }}
                onClick={(e) => e.stopPropagation()}
            >
                <div
                    style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        borderBottom: `1px solid ${S.base2}`,
                        paddingBottom: '0.75rem',
                    }}
                >
                    <h3
                        id="manage-deployment-title"
                        style={{
                            margin: 0,
                            fontFamily: MONO,
                            fontSize: '15px',
                            color: S.cyan,
                            fontWeight: 'bold',
                        }}
                    >
                        manage deployment
                    </h3>
                    <Button
                        onClick={onClose}
                        style={{
                            background: 'none',
                            border: 'none',
                            color: S.base1,
                            cursor: 'pointer',
                            fontSize: '18px',
                            fontFamily: MONO,
                        }}
                    >
                        ✕
                    </Button>
                </div>

                <div
                    style={{
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '0.5rem',
                        fontFamily: MONO,
                        fontSize: '12px',
                    }}
                >
                    <div style={{ display: 'flex' }}>
                        <span
                            style={{
                                color: S.base1,
                                width: '90px',
                                flexShrink: 0,
                            }}
                        >
                            file:
                        </span>
                        <span
                            style={{
                                color: S.base00,
                                fontWeight: 'bold',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                            }}
                        >
                            {managingLink.filename}
                        </span>
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            overflow: 'hidden',
                        }}
                    >
                        <span
                            style={{
                                color: S.base1,
                                width: '90px',
                                flexShrink: 0,
                            }}
                        >
                            url:
                        </span>
                        <a
                            href={managingLink.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            style={{
                                color: S.blue,
                                textDecoration: 'none',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                            }}
                        >
                            {managingLink.url}
                        </a>
                    </div>
                    <div style={{ display: 'flex' }}>
                        <span
                            style={{
                                color: S.base1,
                                width: '90px',
                                flexShrink: 0,
                            }}
                        >
                            status:
                        </span>
                        <span
                            style={{
                                color:
                                    timeUntil(managingLink.expiresAt) ===
                                    'expired'
                                        ? S.red
                                        : S.green,
                                fontWeight: 'bold',
                            }}
                        >
                            {timeUntil(managingLink.expiresAt) === 'expired'
                                ? 'expired'
                                : `expires in ${timeUntil(managingLink.expiresAt)}`}
                        </span>
                    </div>
                </div>

                <div
                    style={{
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '0.75rem',
                    }}
                >
                    <label
                        style={{
                            fontFamily: MONO,
                            fontSize: '12px',
                            color: S.base00,
                            display: 'flex',
                            flexDirection: 'column',
                            gap: '0.5rem',
                        }}
                    >
                        <span>adjust duration (minutes from now)</span>
                        <div
                            style={{
                                display: 'flex',
                                gap: '0.5rem',
                            }}
                        >
                            <input
                                type="number"
                                min={1}
                                max={MAX_TTL_MINUTES}
                                value={newTtlMinutes}
                                onChange={(e) =>
                                    setNewTtlMinutes(
                                        parseInt(e.target.value) ||
                                            DEFAULT_TTL_MINUTES,
                                    )
                                }
                                style={{
                                    flex: 1,
                                    padding: '0.5rem',
                                    backgroundColor: S.base2,
                                    border: `1px solid ${S.base1}`,
                                    color: S.base0,
                                    fontFamily: MONO,
                                    fontSize: '13px',
                                    outline: 'none',
                                    boxSizing: 'border-box',
                                }}
                            />
                            <select
                                value={
                                    [
                                        15,
                                        60,
                                        360,
                                        1440,
                                        DEFAULT_TTL_MINUTES,
                                        10080,
                                        43200,
                                        MAX_TTL_MINUTES,
                                    ].includes(newTtlMinutes)
                                        ? newTtlMinutes
                                        : ''
                                }
                                onChange={(e) =>
                                    e.target.value &&
                                    setNewTtlMinutes(parseInt(e.target.value))
                                }
                                style={{
                                    padding: '0.5rem',
                                    backgroundColor: S.base2,
                                    border: `1px solid ${S.base1}`,
                                    color: S.base0,
                                    fontFamily: MONO,
                                    fontSize: '13px',
                                    outline: 'none',
                                    boxSizing: 'border-box',
                                }}
                            >
                                <option value="" disabled>
                                    presets
                                </option>
                                <option value={15}>15 min</option>
                                <option value={60}>1 hour</option>
                                <option value={360}>6 hours</option>
                                <option value={1440}>24 hours</option>
                                <option value={DEFAULT_TTL_MINUTES}>
                                    5 days
                                </option>
                                <option value={10080}>7 days</option>
                                <option value={43200}>30 days</option>
                                <option value={MAX_TTL_MINUTES}>
                                    365 days
                                </option>
                            </select>
                        </div>
                    </label>

                    <Button
                        onClick={onUpdateTtl}
                        disabled={isUpdatingTtl || isDeletingLink}
                        style={{
                            fontFamily: MONO,
                            fontSize: '12px',
                            padding: '0.5rem 1rem',
                            backgroundColor: S.blue,
                            color: S.base3,
                            border: 'none',
                            cursor:
                                isUpdatingTtl || isDeletingLink
                                    ? 'not-allowed'
                                    : 'pointer',
                            transition: 'opacity 0.15s ease',
                            alignSelf: 'flex-start',
                        }}
                    >
                        {isUpdatingTtl ? 'updating...' : 'update duration'}
                    </Button>
                </div>

                {modalError && (
                    <div
                        style={{
                            fontFamily: MONO,
                            fontSize: '12px',
                            color: S.red,
                        }}
                    >
                        ✗ {modalError}
                    </div>
                )}
                {modalSuccess && (
                    <div
                        style={{
                            fontFamily: MONO,
                            fontSize: '12px',
                            color: S.green,
                        }}
                    >
                        ✓ duration updated successfully!
                    </div>
                )}

                <div
                    style={{
                        borderTop: `1px solid ${S.base2}`,
                        paddingTop: '1.2rem',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '0.6rem',
                    }}
                >
                    <div
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                            color: S.base1,
                        }}
                    >
                        danger zone: permanently delete deployment from server
                    </div>
                    <Button
                        onClick={onDeleteLink}
                        disabled={isUpdatingTtl || isDeletingLink}
                        style={{
                            fontFamily: MONO,
                            fontSize: '12px',
                            padding: '0.5rem 1rem',
                            backgroundColor: S.red,
                            color: S.base3,
                            border: 'none',
                            cursor:
                                isUpdatingTtl || isDeletingLink
                                    ? 'not-allowed'
                                    : 'pointer',
                            transition: 'opacity 0.15s ease',
                            alignSelf: 'flex-start',
                        }}
                    >
                        {isDeletingLink ? 'deleting...' : 'delete deployment'}
                    </Button>
                </div>
            </div>
        </div>
    );
}
