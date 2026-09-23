import { Button } from '@/components/ui/button';
import type { WelcomePhase } from '@/pages/welcome/welcome-upload-controls';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    red: 'var(--sol-red)',
} as const;

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
                <p
                    className="fade-in"
                    style={{
                        margin: '-1.25rem 0 0',
                        fontFamily: MONO,
                        fontSize: '14px',
                        color: S.red,
                    }}
                >
                    {phase.message}{' '}
                    <Button
                        onClick={onReset}
                        style={{
                            background: 'none',
                            border: 'none',
                            color: S.red,
                            cursor: 'pointer',
                            fontFamily: 'inherit',
                            fontSize: 'inherit',
                            textDecoration: 'underline',
                            padding: 0,
                        }}
                    >
                        try again
                    </Button>
                </p>
            )}

            {showButton && (
                <Button
                    className="deploy-btn"
                    onClick={onDeploy}
                    disabled={!canDeploy}
                    style={{
                        fontFamily: MONO,
                        fontSize: '16px',
                        padding: '0.625rem 2.8rem',
                        backgroundColor: buttonActive ? S.red : 'transparent',
                        color: buttonActive ? 'var(--sol-base3)' : S.base1,
                        border: `1px solid ${buttonActive ? S.red : S.base2}`,
                        cursor: canDeploy ? 'pointer' : 'not-allowed',
                        transition:
                            'background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease',
                        letterSpacing: '0.06em',
                    }}
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
