import { Button } from '@/components/ui/button';
import type { WelcomePhase } from '@/pages/welcome/welcome-upload-controls';

interface WelcomeUploadActionsProps {
    phase: WelcomePhase;
    showButton: boolean;
    canDeploy: boolean;
    buttonActive: boolean;
    onReset: () => void;
    onDeploy: () => void;
}

export function WelcomeUploadActions({
    phase,
    showButton,
    canDeploy,
    buttonActive,
    onReset,
    onDeploy,
}: WelcomeUploadActionsProps) {
    const isDeploying = phase.t === 'deploying';

    return (
        <>
            {phase.t === 'error' && (
                <p className="fade-in welcome-upload-error">
                    {phase.message}{' '}
                    <Button onClick={onReset} className="welcome-upload-retry">
                        try again
                    </Button>
                </p>
            )}

            {showButton && (
                <Button
                    className={`deploy-btn welcome-deploy-button ${buttonActive ? 'is-active' : ''}`}
                    onClick={onDeploy}
                    disabled={!canDeploy}
                >
                    {isDeploying ? (
                        <span className="cursor-blink">deploying</span>
                    ) : (
                        '[ deploy ]'
                    )}
                </Button>
            )}
        </>
    );
}
