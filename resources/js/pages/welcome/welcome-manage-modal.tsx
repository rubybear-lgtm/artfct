import type { RefObject } from 'react';
import { Button } from '@/components/ui/button';
import type { CachedLink } from '@/pages/welcome/welcome-cached-links';
import {
    ManageDeploymentDanger,
    ManageDeploymentDuration,
    ManageDeploymentSummary,
} from '@/pages/welcome/welcome-manage-sections';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
const S = {
    base3: 'var(--sol-base3)',
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    red: 'var(--sol-red)',
    green: 'var(--sol-green)',
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

                <ManageDeploymentSummary managingLink={managingLink} />

                <ManageDeploymentDuration
                    newTtlMinutes={newTtlMinutes}
                    setNewTtlMinutes={setNewTtlMinutes}
                    isUpdatingTtl={isUpdatingTtl}
                    isDeletingLink={isDeletingLink}
                    onUpdateTtl={onUpdateTtl}
                />

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

                <ManageDeploymentDanger
                    isUpdatingTtl={isUpdatingTtl}
                    isDeletingLink={isDeletingLink}
                    onDeleteLink={onDeleteLink}
                />
            </div>
        </div>
    );
}
