import type { DragEvent, RefObject } from 'react';

import { WelcomeFileDropzone } from '@/pages/welcome/welcome-file-dropzone';
import { WelcomeUploadActions } from '@/pages/welcome/welcome-upload-actions';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base1: 'var(--sol-base1)',
    blue: 'var(--sol-blue)',
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
            <WelcomeFileDropzone
                inputRef={inputRef}
                phase={phase}
                selectedFile={selectedFile}
                isMarkdown={isMarkdown}
                dragOver={dragOver}
                onAcceptFile={onAcceptFile}
                onDragOver={onDragOver}
                onDragLeave={onDragLeave}
                onDrop={onDrop}
            />
            <WelcomeUploadActions
                phase={phase}
                showButton={showButton}
                canDeploy={canDeploy}
                buttonActive={buttonActive}
                onReset={onReset}
                onDeploy={onDeploy}
            />{' '}
        </>
    );
}
