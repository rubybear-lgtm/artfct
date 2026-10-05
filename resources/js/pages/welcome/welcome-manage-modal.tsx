import { useState } from 'react';
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
    const [failedAction, setFailedAction] = useState<
        'update' | 'delete' | null
    >(null);

    const handleUpdateTtl = (): void | Promise<void> => {
        setFailedAction('update');

        return onUpdateTtl();
    };

    const handleDeleteLink = (): void | Promise<void> => {
        setFailedAction('delete');

        return onDeleteLink();
    };

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
                    onUpdateTtl={handleUpdateTtl}
                />

                {modalError && (
                    <div
                        role="alert"
                        className="welcome-manage-message is-error"
                    >
                        {failedAction === 'delete'
                            ? `Couldn't delete: ${modalError}`
                            : `Couldn't update: ${modalError}`}
                    </div>
                )}
                {modalSuccess && (
                    <div
                        role="status"
                        className="welcome-manage-message is-success"
                    >
                        Duration updated.
                    </div>
                )}

                <ManageDeploymentDanger
                    isUpdatingTtl={isUpdatingTtl}
                    isDeletingLink={isDeletingLink}
                    onDeleteLink={handleDeleteLink}
                />
            </div>
        </div>
    );
}
