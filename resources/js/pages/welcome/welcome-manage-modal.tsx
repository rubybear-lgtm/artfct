import type { RefObject } from 'react';
import { Button } from '@/components/ui/button';
import type { CachedLink } from '@/pages/welcome/welcome-cached-links';
import {
    ManageDeploymentDanger,
    ManageDeploymentDuration,
    ManageDeploymentSummary,
} from '@/pages/welcome/welcome-manage-sections';

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
        <div className="welcome-manage-overlay" onClick={onClose}>
            <div
                className="modal-content welcome-manage-dialog"
                ref={modalRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="manage-deployment-title"
                tabIndex={-1}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="welcome-manage-header">
                    <h3
                        id="manage-deployment-title"
                        className="welcome-manage-title"
                    >
                        manage deployment
                    </h3>
                    <Button onClick={onClose} className="welcome-manage-close">
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
                    <div className="welcome-manage-message is-error">
                        ✗ {modalError}
                    </div>
                )}
                {modalSuccess && (
                    <div className="welcome-manage-message is-success">
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
