import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    encryptArtifactBody,
    extractArtifactMetadata,
    withArtifactFragment,
} from '@/lib/artifactCrypto';
import { AsciiLogo } from '@/lib/asciiLogo';
import { renderMarkdownToFragment, renderMarkdownToHtml } from '@/lib/markdown';
import { ThemeToggle } from '@/lib/theme';
import type { CachedLink } from '@/pages/welcome/welcome-cached-links';
import { WelcomeCachedLinks } from '@/pages/welcome/welcome-cached-links';
import { WelcomeCliCallout } from '@/pages/welcome/welcome-cli-callout';
import {
    WelcomeFaq,
    WelcomeFooter,
    WelcomeInformation,
} from '@/pages/welcome/welcome-information';
import { WelcomeManageModal } from '@/pages/welcome/welcome-manage-modal';
import { WelcomeResult } from '@/pages/welcome/welcome-result';
import { WelcomeUploadControls } from '@/pages/welcome/welcome-upload-controls';
import type { WelcomePhase } from '@/pages/welcome/welcome-upload-controls';
import { login } from '@/routes';
import consoleRoutes from '@/routes/console';

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

        // Find the common prefix of current and next text
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
            const t = setTimeout(() => {
                setIsWaiting(false);
                setIsDeleting(true);
            }, delayMs);

            return () => clearTimeout(t);
        }

        if (isDeleting) {
            const t = setTimeout(() => {
                setDisplayed((prev) => {
                    const next = prev.slice(0, -1);

                    if (next === commonPrefix) {
                        setIsDeleting(false);
                        setTextIndex(
                            (prevIndex) => (prevIndex + 1) % texts.length,
                        );
                    }

                    return next;
                });
            }, deleteSpeed);

            return () => clearTimeout(t);
        }

        // Typing
        const t = setTimeout(() => {
            setDisplayed((prev) => {
                const next = currentText.slice(0, prev.length + 1);

                if (next === currentText) {
                    setIsWaiting(true);
                }

                return next;
            });
        }, typeSpeed);

        return () => clearTimeout(t);
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

function pickGradient(): [string, string] {
    const [a, b] = PAIRS[Math.floor(Math.random() * PAIRS.length)];

    return [ACCENTS[a], ACCENTS[b]];
}

function getRemainingMinutes(expiresAtStr: string): number {
    const ms = new Date(expiresAtStr).getTime() - Date.now();

    return Math.max(1, Math.round(ms / 60_000));
}

function isMarkdownFile(name: string): boolean {
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

export default function Welcome() {
    const { auth, currentTeam } = usePage<{
        auth: { user: { id: number } | null };
        currentTeam: { slug: string } | null;
    }>().props;
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
                } catch (e) {
                    console.error('Failed to parse cached links', e);
                }
            }
        }

        return [];
    });
    const [managingLink, setManagingLink] = useState<CachedLink | null>(null);
    const [newTtlMinutes, setNewTtlMinutes] =
        useState<number>(DEFAULT_TTL_MINUTES);
    const [isUpdatingTtl, setIsUpdatingTtl] = useState(false);
    const [isDeletingLink, setIsDeletingLink] = useState(false);
    const [modalError, setModalError] = useState<string | null>(null);
    const [modalSuccess, setModalSuccess] = useState(false);

    const inputRef = useRef<HTMLInputElement>(null);
    const modalRef = useRef<HTMLDivElement>(null);
    const tagline = useRotatingTypewriter(TAGLINES, 38, 20, 3000);

    const saveCachedLinks = useCallback(
        (updater: CachedLink[] | ((prev: CachedLink[]) => CachedLink[])) => {
            setCachedLinks((prev) => {
                const next =
                    typeof updater === 'function' ? updater(prev) : updater;
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
        const remaining = getRemainingMinutes(link.expiresAt);
        setNewTtlMinutes(remaining > 0 ? remaining : DEFAULT_TTL_MINUTES);
        setModalError(null);
        setModalSuccess(false);
        setIsUpdatingTtl(false);
        setIsDeletingLink(false);
    }, []);

    const closeManageModal = useCallback(() => {
        setManagingLink(null);
    }, []);

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
            const res = await fetch(
                `${WORKER_URL}/v1/artifacts/${managingLink.id}`,
                {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ttl_minutes: newTtlMinutes }),
                },
            );

            if (!res.ok) {
                const body = (await res.json().catch(() => ({}))) as {
                    error?: string;
                };

                throw new Error(body.error ?? `server error ${res.status}`);
            }

            const data = (await res.json()) as {
                id: string;
                expires_at: string;
            };

            saveCachedLinks((prev) =>
                prev.map((link) =>
                    link.id === managingLink.id
                        ? { ...link, expiresAt: data.expires_at }
                        : link,
                ),
            );

            setManagingLink((prev) =>
                prev ? { ...prev, expiresAt: data.expires_at } : null,
            );
            setModalSuccess(true);
            setTimeout(() => setModalSuccess(false), 3000);
        } catch (err) {
            setModalError(
                err instanceof Error ? err.message : 'failed to update TTL',
            );
        } finally {
            setIsUpdatingTtl(false);
        }
    }, [managingLink, newTtlMinutes, saveCachedLinks]);

    const handleDeleteLink = useCallback(async () => {
        if (!managingLink) {
            return;
        }

        if (
            !confirm(
                'are you sure you want to delete this deployment? it will be permanently removed from the server.',
            )
        ) {
            return;
        }

        setIsDeletingLink(true);
        setModalError(null);

        try {
            const res = await fetch(
                `${WORKER_URL}/v1/artifacts/${managingLink.id}`,
                {
                    method: 'DELETE',
                },
            );

            if (!res.ok && res.status !== 204) {
                const body = (await res.json().catch(() => ({}))) as {
                    error?: string;
                };

                throw new Error(body.error ?? `server error ${res.status}`);
            }

            saveCachedLinks((prev) =>
                prev.filter((link) => link.id !== managingLink.id),
            );
            closeManageModal();
        } catch (err) {
            setModalError(
                err instanceof Error
                    ? err.message
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

    const handleDragOver = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        setDragOver(true);
    }, []);
    const handleDragLeave = useCallback(() => setDragOver(false), []);
    const handleDrop = useCallback(
        (e: React.DragEvent) => {
            e.preventDefault();
            setDragOver(false);
            const file = e.dataTransfer.files[0];

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

            const html = isMarkdownFile(file.name)
                ? renderMarkdownToHtml(text)
                : text;
            const metadataSource = isMarkdownFile(file.name)
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
            const res = await fetch(`${WORKER_URL}/v1/artifacts`, {
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

            if (!res.ok) {
                const body = (await res.json().catch(() => ({}))) as {
                    message?: string;
                };

                throw new Error(body.message ?? `server error ${res.status}`);
            }

            const data = (await res.json()) as {
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

            const newLink: CachedLink = {
                id: data.id,
                url: fullUrl,
                expiresAt: data.expires_at,
                filename: file.name,
                deployedAt: new Date().toISOString(),
            };
            saveCachedLinks((prev) => [newLink, ...prev]);
        } catch (err) {
            setPhase({
                t: 'error',
                message:
                    err instanceof Error ? err.message : 'deployment failed',
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
    const showButton = !isSuccess;
    const buttonActive = canDeploy || isDeploying;
    const selectedFile =
        phase.t === 'selected' || phase.t === 'deploying' ? phase.file : null;
    const isMd = selectedFile ? isMarkdownFile(selectedFile.name) : false;

    return (
        <>
            <Head title="artfct — share encrypted html instantly">
                <meta
                    property="og:title"
                    content="artfct — share encrypted html. get a link. that's it."
                />
                <meta
                    property="og:description"
                    content="Drop a self-contained HTML file — via browser, CLI, API, or AI agent — and get back a shareable encrypted link. No sign-up required."
                />
                <meta property="og:url" content="https://artfct.dev" />
                <meta property="og:type" content="website" />
                <meta
                    name="description"
                    content="Drop a self-contained HTML file — via browser, CLI, API, or AI agent — and get back a shareable encrypted link. No sign-up required."
                />
            </Head>
            <ThemeToggle />
            <a
                href={
                    auth?.user && currentTeam
                        ? consoleRoutes.index.url({ team: currentTeam.slug })
                        : login.url()
                }
                className="welcome-signin"
            >
                {auth?.user && currentTeam ? 'open console' : 'sign in'}
            </a>
            <div className="welcome-shell">
                <div className="welcome-content">
                    <AsciiLogo
                        colorA={gradient[0]}
                        colorB={gradient[1]}
                        className="ascii-hero"
                    />

                    <p
                        aria-label="share encrypted html or markdown. get a link. that's it."
                        className="welcome-tagline"
                    >
                        <span aria-hidden="true">
                            {tagline.displayed}
                            {!tagline.done && <span className="cursor-blink" />}
                        </span>
                    </p>

                    <WelcomeUploadControls
                        inputRef={inputRef}
                        phase={phase}
                        selectedFile={selectedFile}
                        isMarkdown={isMd}
                        previewBlurred={previewBlurred}
                        dragOver={dragOver}
                        showButton={showButton}
                        canDeploy={canDeploy}
                        buttonActive={buttonActive}
                        onPreviewBlurredChange={setPreviewBlurred}
                        onAcceptFile={acceptFile}
                        onDragOver={handleDragOver}
                        onDragLeave={handleDragLeave}
                        onDrop={handleDrop}
                        onReset={reset}
                        onDeploy={handleDeploy}
                    />

                    {/* ── success ── */}
                    {isSuccess && phase.t === 'success' && (
                        <WelcomeResult
                            url={phase.url}
                            expiresAt={phase.expiresAt}
                            onReset={reset}
                        />
                    )}

                    <WelcomeCachedLinks
                        links={cachedLinks}
                        onClear={() => {
                            if (confirm('clear all cached links?')) {
                                saveCachedLinks([]);
                            }
                        }}
                        onManage={openManageModal}
                    />

                    {managingLink && (
                        <WelcomeManageModal
                            managingLink={managingLink}
                            modalRef={modalRef}
                            newTtlMinutes={newTtlMinutes}
                            setNewTtlMinutes={setNewTtlMinutes}
                            isUpdatingTtl={isUpdatingTtl}
                            isDeletingLink={isDeletingLink}
                            modalError={modalError}
                            modalSuccess={modalSuccess}
                            onClose={closeManageModal}
                            onUpdateTtl={handleUpdateTtl}
                            onDeleteLink={handleDeleteLink}
                        />
                    )}

                    <WelcomeCliCallout />
                    <WelcomeInformation />
                    <WelcomeFaq />
                    <WelcomeFooter
                        isAuthenticated={Boolean(auth?.user && currentTeam)}
                        currentTeamSlug={currentTeam?.slug ?? null}
                    />
                </div>
            </div>

            {/* ── FAQPage JSON-LD schema ── */}
            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={{
                    __html: JSON.stringify({
                        '@context': 'https://schema.org',
                        '@type': 'FAQPage',
                        mainEntity: [
                            {
                                '@type': 'Question',
                                name: 'What is artfct?',
                                acceptedAnswer: {
                                    '@type': 'Answer',
                                    text: 'artfct is an instant encrypted HTML sharing tool for developers. Drop a self-contained HTML or Markdown file — via browser, CLI, API, or AI agent — and get a shareable link in seconds. No sign-up required.',
                                },
                            },
                            {
                                '@type': 'Question',
                                name: 'Is artfct free?',
                                acceptedAnswer: {
                                    '@type': 'Answer',
                                    text: 'Yes. All artifact tiers are free right now. Paid plans with higher usage limits may be added in the future, but the core service will remain free.',
                                },
                            },
                            {
                                '@type': 'Question',
                                name: 'How does encryption work?',
                                acceptedAnswer: {
                                    '@type': 'Answer',
                                    text: 'Every artifact is encrypted in the browser using AES-GCM before it ever reaches the server. The encryption key is embedded in the URL fragment (the part after #), which the server never sees.',
                                },
                            },
                            {
                                '@type': 'Question',
                                name: 'What are the three tiers?',
                                acceptedAnswer: {
                                    '@type': 'Answer',
                                    text: "Public — open-access URLs, shareable with anyone. Secure — high-entropy fragment keys with blurred previews by default. Ephemeral — intentionally short-lived, same as public but named for things you don't need to keep.",
                                },
                            },
                            {
                                '@type': 'Question',
                                name: 'How long do artifacts last?',
                                acceptedAnswer: {
                                    '@type': 'Answer',
                                    text: 'All artifacts use sliding expiration — every access resets the clock. Default TTL is 5 days, configurable up to 1 year. There is no permanent tier.',
                                },
                            },
                            {
                                '@type': 'Question',
                                name: 'Can I use artfct from the CLI?',
                                acceptedAnswer: {
                                    '@type': 'Answer',
                                    text: 'Yes. Pipe HTML from stdin: "cat dashboard.html | npx artfct". The CLI returns a URL to stdout — perfect for shell scripts, CI pipelines, and automation.',
                                },
                            },
                            {
                                '@type': 'Question',
                                name: 'Does artfct work with AI agents?',
                                acceptedAnswer: {
                                    '@type': 'Answer',
                                    text: 'Yes. Install the artfct MCP server or the artfct skill in Claude Code, Cursor, Codex, or Gemini. Your agent can build HTML artifacts and deploy them automatically with a single MCP call.',
                                },
                            },
                        ],
                    }),
                }}
            />
        </>
    );
}
