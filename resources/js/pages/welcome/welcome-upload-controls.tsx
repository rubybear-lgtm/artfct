import type { DragEvent, RefObject } from 'react';

import { Button } from '@/components/ui/button';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base0: 'var(--sol-base0)',
    base00: 'var(--sol-base00)',
    red: 'var(--sol-red)',
    violet: 'var(--sol-violet)',
    blue: 'var(--sol-blue)',
    cyan: 'var(--sol-cyan)',
} as const;

export type WelcomePhase =
    | { t: 'idle' }
    | { t: 'selected'; file: File }
    | { t: 'deploying'; file: File }
    | { t: 'success'; url: string; expiresAt: string }
    | { t: 'error'; message: string };

interface WelcomeUploadControlsProps {
    inputRef: RefObject<HTMLInputElement | null>;
    phase: WelcomePhase;
    selectedFile: File | null;
    isMarkdown: boolean;
    previewBlurred: boolean;
    dragOver: boolean;
    showButton: boolean;
    canDeploy: boolean;
    buttonActive: boolean;
    onPreviewBlurredChange: (blurred: boolean) => void;
    onAcceptFile: (file: File) => void;
    onDragOver: (event: DragEvent) => void;
    onDragLeave: () => void;
    onDrop: (event: DragEvent) => void;
    onReset: () => void;
    onDeploy: () => void;
}

export function WelcomeUploadControls({
    inputRef,
    phase,
    selectedFile,
    isMarkdown,
    previewBlurred,
    dragOver,
    showButton,
    canDeploy,
    buttonActive,
    onPreviewBlurredChange,
    onAcceptFile,
    onDragOver,
    onDragLeave,
    onDrop,
    onReset,
    onDeploy,
}: WelcomeUploadControlsProps) {
    const isDeploying = phase.t === 'deploying';

    return (
        <>
            <label
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '0.6rem',
                    fontFamily: MONO,
                    fontSize: '12px',
                    color: S.base1,
                    letterSpacing: '0.03em',
                }}
            >
                <input
                    type="checkbox"
                    checked={previewBlurred}
                    onChange={(event) =>
                        onPreviewBlurredChange(event.target.checked)
                    }
                    style={{
                        accentColor: S.blue,
                        width: '14px',
                        height: '14px',
                    }}
                />
                blur link preview by default
            </label>

            <div style={{ width: '100%' }}>
                <input
                    ref={inputRef}
                    type="file"
                    accept=".html,.htm,.md,.markdown,text/html,text/markdown"
                    onChange={(event) => {
                        const file = event.target.files?.[0];

                        if (file) {
                            onAcceptFile(file);
                        }
                    }}
                    style={{ display: 'none' }}
                    aria-label="Select HTML or Markdown file"
                />
                <div
                    role="button"
                    tabIndex={0}
                    onClick={() => inputRef.current?.click()}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            inputRef.current?.click();
                        }
                    }}
                    onDragOver={onDragOver}
                    onDragLeave={onDragLeave}
                    onDrop={onDrop}
                    style={{
                        border: `1px ${dragOver ? 'double' : 'solid'} ${dragOver ? S.blue : S.base1}`,
                        padding: '2.2rem 2rem',
                        cursor: 'pointer',
                        textAlign: 'center',
                        backgroundColor: dragOver
                            ? 'color-mix(in srgb, var(--sol-blue) 7%, transparent)'
                            : 'transparent',
                        transition:
                            'border-color 0.1s ease, background-color 0.1s ease',
                        fontFamily: MONO,
                        fontSize: '16px',
                        outline: 'none',
                    }}
                >
                    {selectedFile ? (
                        <>
                            <div
                                style={{
                                    color: S.cyan,
                                    marginBottom: '0.35rem',
                                    letterSpacing: '0.02em',
                                }}
                            >
                                {phase.t === 'selected' ? '◆ ' : ''}
                                {selectedFile.name}
                                {isMarkdown && (
                                    <span
                                        style={{
                                            color: S.violet,
                                            marginLeft: '0.5rem',
                                            fontSize: '13px',
                                        }}
                                    >
                                        md
                                    </span>
                                )}
                            </div>
                            <div
                                style={{
                                    color: S.base1,
                                    fontSize: '14px',
                                }}
                            >
                                {isDeploying ? (
                                    <span className="cursor-blink">
                                        deploying
                                    </span>
                                ) : (
                                    'ready to deploy'
                                )}
                            </div>
                        </>
                    ) : (
                        <>
                            <div
                                style={{
                                    color: S.base0,
                                    marginBottom: '0.35rem',
                                }}
                            >
                                drop your{' '}
                                <span
                                    style={{
                                        color: S.base00,
                                        fontWeight: 600,
                                    }}
                                >
                                    .html
                                </span>{' '}
                                or{' '}
                                <span
                                    style={{
                                        color: S.base00,
                                        fontWeight: 600,
                                    }}
                                >
                                    .md
                                </span>{' '}
                                file here
                            </div>
                            <div
                                style={{
                                    color: S.base1,
                                    fontSize: '14px',
                                }}
                            >
                                or click to browse
                            </div>
                        </>
                    )}
                </div>
            </div>

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
