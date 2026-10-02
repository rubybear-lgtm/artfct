import { useCallback, useEffect, useRef, useState } from 'react';
import type { DragEvent } from 'react';
import {
    encryptArtifactBody,
    extractArtifactMetadata,
    withArtifactFragment,
} from '@/lib/artifactCrypto';
import { renderMarkdownToFragment, renderMarkdownToHtml } from '@/lib/markdown';
import type { CachedLink } from '@/pages/welcome/welcome-cached-links';
import type { WelcomePhase } from '@/pages/welcome/welcome-upload-controls';

const S = {
    yellow: 'var(--sol-yellow)',
    orange: 'var(--sol-orange)',
    red: 'var(--sol-red)',
    magenta: 'var(--sol-magenta)',
    violet: 'var(--sol-violet)',
    blue: 'var(--sol-blue)',
    cyan: 'var(--sol-cyan)',
    green: 'var(--sol-green)',
} as const;

const ACCENTS = [
    S.yellow,
    S.orange,
    S.red,
    S.magenta,
    S.violet,
    S.blue,
    S.cyan,
    S.green,
] as const;

const PAIRS: [number, number][] = [
    [0, 5],
    [1, 6],
    [2, 7],
    [3, 7],
    [4, 0],
    [2, 5],
    [6, 3],
    [1, 4],
];

const WORKER_URL =
    (import.meta.env.VITE_WORKER_URL as string | undefined) ??
    (import.meta.env.DEV ? 'http://127.0.0.1:8788' : '');
const MAX_BYTES = 1024 * 1024;
const DEFAULT_TTL_MINUTES = 5 * 24 * 60;
const TAGLINES = [
    "share encrypted html. get a link. that's it.",
    "share encrypted markdown. get a link. that's it.",
];

function pickGradient(): [string, string] {
    const [a, b] = PAIRS[Math.floor(Math.random() * PAIRS.length)];

    return [ACCENTS[a], ACCENTS[b]];
}

function getRemainingMinutes(expiresAtStr: string): number {
    const ms = new Date(expiresAtStr).getTime() - Date.now();

    return Math.max(1, Math.round(ms / 60_000));
}

export function isMarkdownFile(name: string): boolean {
    return (
        name.toLowerCase().endsWith('.md') ||
        name.toLowerCase().endsWith('.markdown')
    );
}

function fileStem(name: string): string {
    const lastDot = name.lastIndexOf('.');

    if (lastDot <= 0) {
        return name;
    }

    return name.slice(0, lastDot);
}

function useRotatingTypewriter(
    texts: string[],
    typeSpeed: number = 38,
    deleteSpeed: number = 20,
    delayMs: number = 3000,
) {
    const [textIndex, setTextIndex] = useState(0);
    const [displayed, setDisplayed] = useState('');
    const [isDeleting, setIsDeleting] = useState(false);
    const [isWaiting, setIsWaiting] = useState(false);

    useEffect(() => {
        if (texts.length === 0) {
            return;
        }

        const currentText = texts[textIndex];
        const nextText = texts[(textIndex + 1) % texts.length];
        let commonLength = 0;

        while (
            commonLength < currentText.length &&
            commonLength < nextText.length &&
            currentText[commonLength] === nextText[commonLength]
        ) {
            commonLength++;
        }

        const commonPrefix = currentText.slice(0, commonLength);

        if (isWaiting) {
            const timeout = setTimeout(() => {
                setIsWaiting(false);
                setIsDeleting(true);
            }, delayMs);

            return () => clearTimeout(timeout);
        }

        if (isDeleting) {
            const timeout = setTimeout(() => {
                setDisplayed((previous) => {
                    const next = previous.slice(0, -1);

                    if (next === commonPrefix) {
                        setIsDeleting(false);
                        setTextIndex(
                            (previousIndex) =>
                                (previousIndex + 1) % texts.length,
                        );
                    }

                    return next;
                });
            }, deleteSpeed);

            return () => clearTimeout(timeout);
        }

        const timeout = setTimeout(() => {
            setDisplayed((previous) => {
                const next = currentText.slice(0, previous.length + 1);

                if (next === currentText) {
                    setIsWaiting(true);
                }

                return next;
            });
        }, typeSpeed);

        return () => clearTimeout(timeout);
    }, [
        displayed,
        isDeleting,
        isWaiting,
        textIndex,
        texts,
        typeSpeed,
        deleteSpeed,
        delayMs,
    ]);

    return { displayed, done: isWaiting };
}

export function useWelcomeController() {
    const [gradient] = useState<[string, string]>(pickGradient);
    const [phase, setPhase] = useState<WelcomePhase>({ t: 'idle' });
    const [dragOver, setDragOver] = useState(false);
    const [previewBlurred, setPreviewBlurred] = useState(true);
    const [cachedLinks, setCachedLinks] = useState<CachedLink[]>(() => {
        if (typeof window !== 'undefined') {
            const raw = localStorage.getItem('artfct_cached_links');

            if (raw) {
                try {
                    return JSON.parse(raw) as CachedLink[];
                } catch (error) {
                    console.error('Failed to parse cached links', error);
                }
            }
        }

        return [];
    });
    const [managingLink, setManagingLink] = useState<CachedLink | null>(null);
    const [newTtlMinutes, setNewTtlMinutes] = useState(DEFAULT_TTL_MINUTES);
    const [isUpdatingTtl, setIsUpdatingTtl] = useState(false);
    const [isDeletingLink, setIsDeletingLink] = useState(false);
    const [modalError, setModalError] = useState<string | null>(null);
    const [modalSuccess, setModalSuccess] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const modalRef = useRef<HTMLDivElement>(null);
    const tagline = useRotatingTypewriter(TAGLINES, 38, 20, 3000);

    const saveCachedLinks = useCallback(
        (
            updater: CachedLink[] | ((previous: CachedLink[]) => CachedLink[]),
        ) => {
            setCachedLinks((previous) => {
                const next =
                    typeof updater === 'function' ? updater(previous) : updater;
                localStorage.setItem(
                    'artfct_cached_links',
                    JSON.stringify(next),
                );

                return next;
            });
        },
        [],
    );

    const openManageModal = useCallback((link: CachedLink) => {
        setManagingLink(link);
        setNewTtlMinutes(
            getRemainingMinutes(link.expiresAt) || DEFAULT_TTL_MINUTES,
        );
        setModalError(null);
        setModalSuccess(false);
        setIsUpdatingTtl(false);
        setIsDeletingLink(false);
    }, []);

    const closeManageModal = useCallback(() => setManagingLink(null), []);

    useEffect(() => {
        if (!managingLink) {
            return;
        }

        const previouslyFocused = document.activeElement as HTMLElement | null;
        const modal = modalRef.current;
        const focusableSelector =
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

        modal?.focus();

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeManageModal();

                return;
            }

            if (event.key !== 'Tab' || !modal) {
                return;
            }

            const focusable = Array.from(
                modal.querySelectorAll<HTMLElement>(focusableSelector),
            ).filter((element) => !element.hasAttribute('disabled'));

            if (focusable.length === 0) {
                event.preventDefault();

                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            previouslyFocused?.focus();
        };
    }, [closeManageModal, managingLink]);

    const handleUpdateTtl = useCallback(async () => {
        if (!managingLink) {
            return;
        }

        setIsUpdatingTtl(true);
        setModalError(null);
        setModalSuccess(false);

        try {
            const response = await fetch(
                `${WORKER_URL}/v1/artifacts/${managingLink.id}`,
                {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ttl_minutes: newTtlMinutes }),
                },
            );

            if (!response.ok) {
                const body = (await response.json().catch(() => ({}))) as {
                    error?: string;
                };

                throw new Error(
                    body.error ?? `server error ${response.status}`,
                );
            }

            const data = (await response.json()) as {
                expires_at: string;
            };

            saveCachedLinks((previous) =>
                previous.map((link) =>
                    link.id === managingLink.id
                        ? { ...link, expiresAt: data.expires_at }
                        : link,
                ),
            );
            setManagingLink((previous) =>
                previous ? { ...previous, expiresAt: data.expires_at } : null,
            );
            setModalSuccess(true);
            setTimeout(() => setModalSuccess(false), 3000);
        } catch (error) {
            setModalError(
                error instanceof Error ? error.message : 'failed to update TTL',
            );
        } finally {
            setIsUpdatingTtl(false);
        }
    }, [managingLink, newTtlMinutes, saveCachedLinks]);

    const handleDeleteLink = useCallback(async () => {
        if (
            !managingLink ||
            !confirm(
                'are you sure you want to delete this deployment? it will be permanently removed from the server.',
            )
        ) {
            return;
        }

        setIsDeletingLink(true);
        setModalError(null);

        try {
            const response = await fetch(
                `${WORKER_URL}/v1/artifacts/${managingLink.id}`,
                { method: 'DELETE' },
            );

            if (!response.ok && response.status !== 204) {
                const body = (await response.json().catch(() => ({}))) as {
                    error?: string;
                };

                throw new Error(
                    body.error ?? `server error ${response.status}`,
                );
            }

            saveCachedLinks((previous) =>
                previous.filter((link) => link.id !== managingLink.id),
            );
            closeManageModal();
        } catch (error) {
            setModalError(
                error instanceof Error
                    ? error.message
                    : 'failed to delete artifact',
            );
        } finally {
            setIsDeletingLink(false);
        }
    }, [managingLink, saveCachedLinks, closeManageModal]);

    const acceptFile = useCallback((file: File) => {
        const name = file.name.toLowerCase();

        if (
            name.endsWith('.html') ||
            name.endsWith('.htm') ||
            isMarkdownFile(file.name)
        ) {
            setPhase({ t: 'selected', file });

            return;
        }

        setPhase({
            t: 'error',
            message: 'only .html and .md files are accepted',
        });
    }, []);

    const handleDragOver = useCallback((event: DragEvent) => {
        event.preventDefault();
        setDragOver(true);
    }, []);
    const handleDragLeave = useCallback(() => setDragOver(false), []);
    const handleDrop = useCallback(
        (event: DragEvent) => {
            event.preventDefault();
            setDragOver(false);
            const file = event.dataTransfer.files[0];

            if (file) {
                acceptFile(file);
            }
        },
        [acceptFile],
    );

    const handleDeploy = useCallback(async () => {
        if (phase.t !== 'selected') {
            return;
        }

        const { file } = phase;
        setPhase({ t: 'deploying', file });

        try {
            const text = await file.text();
            const markdown = isMarkdownFile(file.name);
            const html = markdown ? renderMarkdownToHtml(text) : text;
            const metadataSource = markdown
                ? renderMarkdownToFragment(text)
                : text;

            if (new Blob([html]).size > MAX_BYTES) {
                setPhase({
                    t: 'error',
                    message: 'payload exceeds 1 MB limit',
                });

                return;
            }

            const metadata = extractArtifactMetadata(
                metadataSource,
                fileStem(file.name),
            );
            const encrypted = await encryptArtifactBody(html);
            const response = await fetch(`${WORKER_URL}/v1/artifacts`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    body_ciphertext_b64: encrypted.bodyCiphertextB64,
                    body_iv_b64: encrypted.bodyIvB64,
                    tier: 'ephemeral',
                    ttl_minutes: DEFAULT_TTL_MINUTES,
                    title: metadata.title,
                    description: metadata.description,
                    thumbnail: metadata.thumbnail,
                    preview_blurred: previewBlurred,
                }),
            });

            if (!response.ok) {
                const body = (await response.json().catch(() => ({}))) as {
                    message?: string;
                };

                throw new Error(
                    body.message ?? `server error ${response.status}`,
                );
            }

            const data = (await response.json()) as {
                id: string;
                url: string;
                expires_at: string;
            };
            const fullUrl = withArtifactFragment(
                data.url,
                encrypted.keyFragment,
            );
            setPhase({
                t: 'success',
                url: fullUrl,
                expiresAt: data.expires_at,
            });
            saveCachedLinks((previous) => [
                {
                    id: data.id,
                    url: fullUrl,
                    expiresAt: data.expires_at,
                    filename: file.name,
                    deployedAt: new Date().toISOString(),
                },
                ...previous,
            ]);
        } catch (error) {
            setPhase({
                t: 'error',
                message:
                    error instanceof Error
                        ? error.message
                        : 'deployment failed',
            });
        }
    }, [phase, previewBlurred, saveCachedLinks]);

    const reset = useCallback(() => {
        setPhase({ t: 'idle' });

        if (inputRef.current) {
            inputRef.current.value = '';
        }
    }, []);

    const canDeploy = phase.t === 'selected';
    const isDeploying = phase.t === 'deploying';
    const isSuccess = phase.t === 'success';
    const selectedFile =
        phase.t === 'selected' || phase.t === 'deploying' ? phase.file : null;

    return {
        gradient,
        tagline,
        inputRef,
        modalRef,
        phase,
        selectedFile,
        isMarkdown: selectedFile ? isMarkdownFile(selectedFile.name) : false,
        previewBlurred,
        dragOver,
        showButton: !isSuccess,
        canDeploy,
        buttonActive: canDeploy || isDeploying,
        cachedLinks,
        managingLink,
        newTtlMinutes,
        setNewTtlMinutes,
        isUpdatingTtl,
        isDeletingLink,
        modalError,
        modalSuccess,
        setPreviewBlurred,
        acceptFile,
        handleDragOver,
        handleDragLeave,
        handleDrop,
        reset,
        handleDeploy,
        saveCachedLinks,
        openManageModal,
        closeManageModal,
        handleUpdateTtl,
        handleDeleteLink,
    };
}
