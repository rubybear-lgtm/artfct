import type { DragEvent, RefObject } from 'react';
import type { WelcomePhase } from '@/pages/welcome/welcome-upload-controls';

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
        <div className="welcome-file-dropzone-wrapper">
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
                className="welcome-file-input"
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
                className={`welcome-file-dropzone ${dragOver ? 'is-dragging' : ''}`}
            >
                {selectedFile ? (
                    <>
                        <div className="welcome-file-name">
                            {phase.t === 'selected' ? '◆ ' : ''}
                            {selectedFile.name}
                            {isMarkdown && (
                                <span className="welcome-file-type">md</span>
                            )}
                        </div>
                        <div className="welcome-file-status">
                            {isDeploying ? (
                                <span className="cursor-blink">deploying</span>
                            ) : (
                                'ready to deploy'
                            )}
                        </div>
                    </>
                ) : (
                    <>
                        <div className="welcome-file-prompt">
                            drop your{' '}
                            <span className="welcome-file-extension">
                                .html
                            </span>{' '}
                            or{' '}
                            <span className="welcome-file-extension">.md</span>{' '}
                            file here
                        </div>
                        <div className="welcome-file-status">
                            or click to browse
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
