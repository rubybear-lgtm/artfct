import type { DragEvent, RefObject } from 'react';
import type { WelcomePhase } from '@/pages/welcome/welcome-upload-controls';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base1: 'var(--sol-base1)',
    base00: 'var(--sol-base00)',
    base0: 'var(--sol-base0)',
    blue: 'var(--sol-blue)',
    violet: 'var(--sol-violet)',
    cyan: 'var(--sol-cyan)',
} as const;

interface WelcomeFileDropzoneProps {
    inputRef: RefObject<HTMLInputElement | null>;
    phase: WelcomePhase;
    selectedFile: File | null;
    isMarkdown: boolean;
    dragOver: boolean;
    onAcceptFile: (file: File) => void;
    onDragOver: (event: DragEvent) => void;
    onDragLeave: () => void;
    onDrop: (event: DragEvent) => void;
}

export function WelcomeFileDropzone({
    inputRef,
    phase,
    selectedFile,
    isMarkdown,
    dragOver,
    onAcceptFile,
    onDragOver,
    onDragLeave,
    onDrop,
}: WelcomeFileDropzoneProps) {
    const isDeploying = phase.t === 'deploying';

    return (
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
                                <span className="cursor-blink">deploying</span>
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
    );
}
