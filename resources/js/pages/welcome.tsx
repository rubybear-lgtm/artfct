import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    encryptArtifactBody,
    extractArtifactMetadata,
    withArtifactFragment,
} from '@/lib/artifactCrypto';
import { AsciiLogo } from '@/lib/asciiLogo';
import { renderMarkdownToFragment, renderMarkdownToHtml } from '@/lib/markdown';
import { ThemeToggle } from '@/lib/theme';

const SANS = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";

const S = {
    base3: 'var(--sol-base3)',
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base0: 'var(--sol-base0)',
    base00: 'var(--sol-base00)',
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

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
const WORKER_URL =
    (import.meta.env.VITE_WORKER_URL as string | undefined) ??
    (import.meta.env.DEV ? 'http://127.0.0.1:8788' : '');
const MAX_BYTES = 1024 * 1024;
const DEFAULT_TTL_MINUTES = 5 * 24 * 60;
const MAX_TTL_MINUTES = 365 * 24 * 60;
const TAGLINES = [
    "share encrypted html. get a link. that's it.",
    "share encrypted markdown. get a link. that's it.",
];

interface CachedLink {
    id: string;
    url: string;
    expiresAt: string;
    filename: string;
    deployedAt: string;
}

type Phase =
    | { t: 'idle' }
    | { t: 'selected'; file: File }
    | { t: 'deploying'; file: File }
    | { t: 'success'; url: string; expiresAt: string }
    | { t: 'error'; message: string };

function useTypewriter(text: string, speed: number) {
    const [index, setIndex] = useState(0);
    const [prevText, setPrevText] = useState(text);

    if (text !== prevText) {
        setPrevText(text);
        setIndex(0);
    }

    useEffect(() => {
        if (index >= text.length) {
            return;
        }

        const t = setTimeout(() => setIndex((i) => i + 1), speed);

        return () => clearTimeout(t);
    }, [index, text.length, speed]);

    return { displayed: text.slice(0, index), done: index >= text.length };
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

function timeUntil(iso: string): string {
    const ms = new Date(iso).getTime() - Date.now();
    const min = Math.round(ms / 60_000);

    if (min <= 0) {
        return 'expired';
    }

    if (min < 60) {
        return `${min} min`;
    }

    const hours = Math.round(min / 60);

    if (hours < 24) {
        return `${hours}h`;
    }

    const days = Math.round(hours / 24);

    if (days < 30) {
        return `${days}d`;
    }

    const months = Math.round(days / 30);

    if (months < 12) {
        return `${months}mo`;
    }

    return `${Math.round(months / 12)}y`;
}

function getRemainingMinutes(expiresAtStr: string): number {
    const ms = new Date(expiresAtStr).getTime() - Date.now();

    return Math.max(1, Math.round(ms / 60_000));
}

function Result({
    url,
    expiresAt,
    onReset,
}: {
    url: string;
    expiresAt: string;
    onReset: () => void;
}) {
    const [copied, setCopied] = useState(false);
    const { displayed, done } = useTypewriter(url, 18);
    const copy = useCallback(async () => {
        await navigator.clipboard.writeText(url);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }, [url]);

    return (
        <div
            className="fade-in"
            style={{
                width: '100%',
                display: 'flex',
                flexDirection: 'column',
                gap: '0.6rem',
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'stretch',
                    border: `1px solid ${S.green}`,
                }}
            >
                <div
                    style={{
                        flex: 1,
                        padding: '0.75rem 0.9rem',
                        fontFamily: MONO,
                        fontSize: '15px',
                        color: S.base00,
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap',
                        backgroundColor:
                            'color-mix(in srgb, var(--sol-green) 8%, transparent)',
                        minWidth: 0,
                    }}
                >
                    {displayed}
                    {!done && <span className="cursor-blink" />}
                </div>
                <button
                    onClick={copy}
                    className={`result-action-btn ${copied ? 'copied' : ''}`}
                    style={{
                        padding: '0 1.25rem',
                        fontFamily: MONO,
                        fontSize: '14px',
                        backgroundColor: copied ? S.green : S.base2,
                        color: copied ? S.base3 : S.base0,
                        border: 'none',
                        borderLeft: `1px solid ${S.green}`,
                        cursor: 'pointer',
                        flexShrink: 0,
                        letterSpacing: '0.04em',
                        minWidth: '7rem',
                    }}
                >
                    {copied ? 'copied' : 'copy'}
                </button>
                <a
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="result-action-btn"
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        padding: '0 1.25rem',
                        fontFamily: MONO,
                        fontSize: '14px',
                        backgroundColor: S.base2,
                        color: S.base0,
                        border: 'none',
                        borderLeft: `1px solid ${S.green}`,
                        cursor: 'pointer',
                        flexShrink: 0,
                        letterSpacing: '0.04em',
                        textDecoration: 'none',
                        minWidth: '7rem',
                    }}
                >
                    open
                </a>
            </div>
            <div
                style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                }}
            >
                <span
                    style={{
                        fontFamily: MONO,
                        fontSize: '14px',
                        color: S.base1,
                    }}
                >
                    expires in {timeUntil(expiresAt)}
                </span>
                <button
                    onClick={onReset}
                    style={{
                        fontFamily: MONO,
                        fontSize: '14px',
                        background: 'none',
                        border: 'none',
                        color: S.base1,
                        cursor: 'pointer',
                        textDecoration: 'underline',
                        padding: 0,
                    }}
                >
                    deploy another
                </button>
            </div>
        </div>
    );
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

function FaqItem({ q, children }: { q: string; children: React.ReactNode }) {
    return (
        <details
            style={{
                fontFamily: SANS,
                fontSize: '14px',
                lineHeight: 1.65,
                color: S.base0,
                cursor: 'pointer',
            }}
        >
            <summary
                style={{
                    color: S.base00,
                    fontWeight: 500,
                    marginBottom: '0.35rem',
                    userSelect: 'none',
                }}
            >
                {q}
            </summary>
            <div
                style={{
                    paddingLeft: '0.5rem',
                    borderLeft: `2px solid ${S.base2}`,
                    marginBottom: '0.5rem',
                }}
            >
                {children}
            </div>
        </details>
    );
}

export default function Welcome() {
    const [gradient] = useState<[string, string]>(pickGradient);
    const [phase, setPhase] = useState<Phase>({ t: 'idle' });
    const [dragOver, setDragOver] = useState(false);
    const [previewBlurred, setPreviewBlurred] = useState(true);

    const [mcpExpanded, setMcpExpanded] = useState(false);
    const [copiedAgentPrompt, setCopiedAgentPrompt] = useState(false);
    const [copiedSelfInstall, setCopiedSelfInstall] = useState(false);
    const [copiedSkillsInstall, setCopiedSkillsInstall] = useState(false);

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
            <div
                style={{
                    minHeight: '100dvh',
                    backgroundColor: S.base3,
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: '3rem 1.5rem',
                    fontFamily:
                        "'Instrument Sans', ui-sans-serif, system-ui, sans-serif",
                    color: S.base0,
                    boxSizing: 'border-box',
                }}
            >
                <div
                    style={{
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        gap: '2.2rem',
                        width: '100%',
                        maxWidth: '700px',
                    }}
                >
                    <AsciiLogo
                        colorA={gradient[0]}
                        colorB={gradient[1]}
                        className="ascii-hero"
                    />

                    <p
                        aria-label="share encrypted html or markdown. get a link. that's it."
                        style={{
                            margin: 0,
                            fontFamily: MONO,
                            fontSize: '15px',
                            color: S.base1,
                            letterSpacing: '0.04em',
                            minHeight: '1em',
                        }}
                    >
                        <span aria-hidden="true">
                            {tagline.displayed}
                            {!tagline.done && <span className="cursor-blink" />}
                        </span>
                    </p>

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
                            onChange={(e) =>
                                setPreviewBlurred(e.target.checked)
                            }
                            style={{
                                accentColor: S.blue,
                                width: '14px',
                                height: '14px',
                            }}
                        />
                        blur link preview by default
                    </label>

                    {/* ── drop zone ── */}
                    <div style={{ width: '100%' }}>
                        <input
                            ref={inputRef}
                            type="file"
                            accept=".html,.htm,.md,.markdown,text/html,text/markdown"
                            onChange={(e) => {
                                const file = e.target.files?.[0];

                                if (file) {
                                    acceptFile(file);
                                }
                            }}
                            style={{ display: 'none' }}
                            aria-label="Select HTML or Markdown file"
                        />
                        <div
                            role="button"
                            tabIndex={0}
                            onClick={() => inputRef.current?.click()}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    inputRef.current?.click();
                                }
                            }}
                            onDragOver={handleDragOver}
                            onDragLeave={handleDragLeave}
                            onDrop={handleDrop}
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
                                        {isMd && (
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
                                        {phase.t === 'deploying' ? (
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

                    {/* ── error ── */}
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
                            <button
                                onClick={reset}
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
                            </button>
                        </p>
                    )}

                    {/* ── deploy button ── */}
                    {showButton && (
                        <button
                            className="deploy-btn"
                            onClick={handleDeploy}
                            disabled={!canDeploy}
                            style={{
                                fontFamily: MONO,
                                fontSize: '16px',
                                padding: '0.625rem 2.8rem',
                                backgroundColor: buttonActive
                                    ? S.red
                                    : 'transparent',
                                color: buttonActive ? S.base3 : S.base1,
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
                        </button>
                    )}

                    {/* ── success ── */}
                    {isSuccess && phase.t === 'success' && (
                        <Result
                            url={phase.url}
                            expiresAt={phase.expiresAt}
                            onReset={reset}
                        />
                    )}

                    {/* ── cached links list ── */}
                    {cachedLinks.length > 0 && (
                        <div
                            style={{
                                width: '100%',
                                display: 'flex',
                                flexDirection: 'column',
                                gap: '0.75rem',
                                marginTop: '1rem',
                                animation: 'fadeSlideUp 0.25s ease-out both',
                            }}
                        >
                            <h3
                                style={{
                                    fontFamily: MONO,
                                    fontSize: '13px',
                                    color: S.base00,
                                    margin: 0,
                                    borderBottom: `1px solid ${S.base2}`,
                                    paddingBottom: '0.5rem',
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    alignItems: 'center',
                                }}
                            >
                                <span>recent deployments</span>
                                <button
                                    onClick={() => {
                                        if (
                                            confirm('clear all cached links?')
                                        ) {
                                            saveCachedLinks([]);
                                        }
                                    }}
                                    style={{
                                        background: 'none',
                                        border: 'none',
                                        color: S.base1,
                                        cursor: 'pointer',
                                        fontSize: '11px',
                                        textDecoration: 'underline',
                                        fontFamily: 'inherit',
                                    }}
                                >
                                    clear history
                                </button>
                            </h3>
                            <div
                                style={{
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: '0.5rem',
                                }}
                            >
                                {cachedLinks.map((link) => {
                                    const timeStr = timeUntil(link.expiresAt);
                                    const isExpired = timeStr === 'expired';

                                    return (
                                        <div
                                            key={link.id}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: 'space-between',
                                                padding: '0.6rem 0.8rem',
                                                backgroundColor: S.base2,
                                                border: `1px solid ${isExpired ? S.red : S.base2}`,
                                                fontFamily: MONO,
                                                fontSize: '13px',
                                                boxSizing: 'border-box',
                                            }}
                                        >
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    flexDirection: 'column',
                                                    gap: '0.2rem',
                                                    overflow: 'hidden',
                                                    textOverflow: 'ellipsis',
                                                    whiteSpace: 'nowrap',
                                                    flex: 1,
                                                    marginRight: '1rem',
                                                }}
                                            >
                                                <span
                                                    style={{
                                                        color: S.cyan,
                                                        fontWeight: 'bold',
                                                        overflow: 'hidden',
                                                        textOverflow:
                                                            'ellipsis',
                                                        whiteSpace: 'nowrap',
                                                    }}
                                                >
                                                    {link.filename}
                                                </span>
                                                <a
                                                    href={link.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    style={{
                                                        color: isExpired
                                                            ? S.base1
                                                            : S.blue,
                                                        textDecoration: 'none',
                                                        overflow: 'hidden',
                                                        textOverflow:
                                                            'ellipsis',
                                                        whiteSpace: 'nowrap',
                                                    }}
                                                >
                                                    {link.url}
                                                </a>
                                            </div>
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    gap: '0.75rem',
                                                    flexShrink: 0,
                                                }}
                                            >
                                                <span
                                                    style={{
                                                        fontSize: '11px',
                                                        color: isExpired
                                                            ? S.red
                                                            : S.green,
                                                    }}
                                                >
                                                    {isExpired
                                                        ? 'expired'
                                                        : `expires in ${timeStr}`}
                                                </span>
                                                <button
                                                    onClick={() =>
                                                        openManageModal(link)
                                                    }
                                                    className="manage-btn"
                                                    style={{
                                                        padding:
                                                            '0.25rem 0.6rem',
                                                        backgroundColor:
                                                            'transparent',
                                                        color: S.base0,
                                                        border: `1px solid ${S.base1}`,
                                                        cursor: 'pointer',
                                                        fontSize: '11px',
                                                        fontFamily: MONO,
                                                        letterSpacing: '0.04em',
                                                    }}
                                                >
                                                    manage
                                                </button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* ── manage modal ── */}
                    {managingLink && (
                        <div
                            style={{
                                position: 'fixed',
                                inset: 0,
                                backgroundColor: 'rgba(0, 0, 0, 0.65)',
                                backdropFilter: 'blur(4px)',
                                zIndex: 1000,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                padding: '1.5rem',
                                boxSizing: 'border-box',
                            }}
                            onClick={closeManageModal}
                        >
                            <div
                                className="modal-content"
                                style={{
                                    backgroundColor: S.base3,
                                    border: `1px solid ${S.base1}`,
                                    width: '100%',
                                    maxWidth: '460px',
                                    padding: '1.8rem',
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: '1.5rem',
                                    position: 'relative',
                                    boxSizing: 'border-box',
                                }}
                                onClick={(e) => e.stopPropagation()}
                            >
                                <div
                                    style={{
                                        display: 'flex',
                                        justifyContent: 'space-between',
                                        alignItems: 'center',
                                        borderBottom: `1px solid ${S.base2}`,
                                        paddingBottom: '0.75rem',
                                    }}
                                >
                                    <h3
                                        style={{
                                            margin: 0,
                                            fontFamily: MONO,
                                            fontSize: '15px',
                                            color: S.cyan,
                                            fontWeight: 'bold',
                                        }}
                                    >
                                        manage deployment
                                    </h3>
                                    <button
                                        onClick={closeManageModal}
                                        style={{
                                            background: 'none',
                                            border: 'none',
                                            color: S.base1,
                                            cursor: 'pointer',
                                            fontSize: '18px',
                                            fontFamily: MONO,
                                        }}
                                    >
                                        ✕
                                    </button>
                                </div>

                                <div
                                    style={{
                                        display: 'flex',
                                        flexDirection: 'column',
                                        gap: '0.5rem',
                                        fontFamily: MONO,
                                        fontSize: '12px',
                                    }}
                                >
                                    <div style={{ display: 'flex' }}>
                                        <span
                                            style={{
                                                color: S.base1,
                                                width: '90px',
                                                flexShrink: 0,
                                            }}
                                        >
                                            file:
                                        </span>
                                        <span
                                            style={{
                                                color: S.base00,
                                                fontWeight: 'bold',
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                            }}
                                        >
                                            {managingLink.filename}
                                        </span>
                                    </div>
                                    <div
                                        style={{
                                            display: 'flex',
                                            overflow: 'hidden',
                                        }}
                                    >
                                        <span
                                            style={{
                                                color: S.base1,
                                                width: '90px',
                                                flexShrink: 0,
                                            }}
                                        >
                                            url:
                                        </span>
                                        <a
                                            href={managingLink.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            style={{
                                                color: S.blue,
                                                textDecoration: 'none',
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                            }}
                                        >
                                            {managingLink.url}
                                        </a>
                                    </div>
                                    <div style={{ display: 'flex' }}>
                                        <span
                                            style={{
                                                color: S.base1,
                                                width: '90px',
                                                flexShrink: 0,
                                            }}
                                        >
                                            status:
                                        </span>
                                        <span
                                            style={{
                                                color:
                                                    timeUntil(
                                                        managingLink.expiresAt,
                                                    ) === 'expired'
                                                        ? S.red
                                                        : S.green,
                                                fontWeight: 'bold',
                                            }}
                                        >
                                            {timeUntil(
                                                managingLink.expiresAt,
                                            ) === 'expired'
                                                ? 'expired'
                                                : `expires in ${timeUntil(managingLink.expiresAt)}`}
                                        </span>
                                    </div>
                                </div>

                                <div
                                    style={{
                                        display: 'flex',
                                        flexDirection: 'column',
                                        gap: '0.75rem',
                                    }}
                                >
                                    <label
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '12px',
                                            color: S.base00,
                                            display: 'flex',
                                            flexDirection: 'column',
                                            gap: '0.5rem',
                                        }}
                                    >
                                        <span>
                                            adjust duration (minutes from now)
                                        </span>
                                        <div
                                            style={{
                                                display: 'flex',
                                                gap: '0.5rem',
                                            }}
                                        >
                                            <input
                                                type="number"
                                                min={1}
                                                max={MAX_TTL_MINUTES}
                                                value={newTtlMinutes}
                                                onChange={(e) =>
                                                    setNewTtlMinutes(
                                                        parseInt(
                                                            e.target.value,
                                                        ) ||
                                                            DEFAULT_TTL_MINUTES,
                                                    )
                                                }
                                                style={{
                                                    flex: 1,
                                                    padding: '0.5rem',
                                                    backgroundColor: S.base2,
                                                    border: `1px solid ${S.base1}`,
                                                    color: S.base0,
                                                    fontFamily: MONO,
                                                    fontSize: '13px',
                                                    outline: 'none',
                                                    boxSizing: 'border-box',
                                                }}
                                            />
                                            <select
                                                value={
                                                    [
                                                        15,
                                                        60,
                                                        360,
                                                        1440,
                                                        DEFAULT_TTL_MINUTES,
                                                        10080,
                                                        43200,
                                                        MAX_TTL_MINUTES,
                                                    ].includes(newTtlMinutes)
                                                        ? newTtlMinutes
                                                        : ''
                                                }
                                                onChange={(e) =>
                                                    e.target.value &&
                                                    setNewTtlMinutes(
                                                        parseInt(
                                                            e.target.value,
                                                        ),
                                                    )
                                                }
                                                style={{
                                                    padding: '0.5rem',
                                                    backgroundColor: S.base2,
                                                    border: `1px solid ${S.base1}`,
                                                    color: S.base0,
                                                    fontFamily: MONO,
                                                    fontSize: '13px',
                                                    outline: 'none',
                                                    boxSizing: 'border-box',
                                                }}
                                            >
                                                <option value="" disabled>
                                                    presets
                                                </option>
                                                <option value={15}>
                                                    15 min
                                                </option>
                                                <option value={60}>
                                                    1 hour
                                                </option>
                                                <option value={360}>
                                                    6 hours
                                                </option>
                                                <option value={1440}>
                                                    24 hours
                                                </option>
                                                <option
                                                    value={DEFAULT_TTL_MINUTES}
                                                >
                                                    5 days
                                                </option>
                                                <option value={10080}>
                                                    7 days
                                                </option>
                                                <option value={43200}>
                                                    30 days
                                                </option>
                                                <option value={MAX_TTL_MINUTES}>
                                                    365 days
                                                </option>
                                            </select>
                                        </div>
                                    </label>

                                    <button
                                        onClick={handleUpdateTtl}
                                        disabled={
                                            isUpdatingTtl || isDeletingLink
                                        }
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '12px',
                                            padding: '0.5rem 1rem',
                                            backgroundColor: S.blue,
                                            color: S.base3,
                                            border: 'none',
                                            cursor:
                                                isUpdatingTtl || isDeletingLink
                                                    ? 'not-allowed'
                                                    : 'pointer',
                                            transition: 'opacity 0.15s ease',
                                            alignSelf: 'flex-start',
                                        }}
                                    >
                                        {isUpdatingTtl
                                            ? 'updating...'
                                            : 'update duration'}
                                    </button>
                                </div>

                                {modalError && (
                                    <div
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '12px',
                                            color: S.red,
                                        }}
                                    >
                                        ✗ {modalError}
                                    </div>
                                )}
                                {modalSuccess && (
                                    <div
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '12px',
                                            color: S.green,
                                        }}
                                    >
                                        ✓ duration updated successfully!
                                    </div>
                                )}

                                <div
                                    style={{
                                        borderTop: `1px solid ${S.base2}`,
                                        paddingTop: '1.2rem',
                                        display: 'flex',
                                        flexDirection: 'column',
                                        gap: '0.6rem',
                                    }}
                                >
                                    <div
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '11px',
                                            color: S.base1,
                                        }}
                                    >
                                        danger zone: permanently delete
                                        deployment from server
                                    </div>
                                    <button
                                        onClick={handleDeleteLink}
                                        disabled={
                                            isUpdatingTtl || isDeletingLink
                                        }
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '12px',
                                            padding: '0.5rem 1rem',
                                            backgroundColor: S.red,
                                            color: S.base3,
                                            border: 'none',
                                            cursor:
                                                isUpdatingTtl || isDeletingLink
                                                    ? 'not-allowed'
                                                    : 'pointer',
                                            transition: 'opacity 0.15s ease',
                                            alignSelf: 'flex-start',
                                        }}
                                    >
                                        {isDeletingLink
                                            ? 'deleting...'
                                            : 'delete deployment'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ── cli, mcp & skills callout ── */}
                    <div
                        style={{
                            width: '100%',
                            paddingTop: '1.5rem',
                            borderTop: `1px solid ${S.base2}`,
                            display: 'flex',
                            flexDirection: 'column',
                            gap: '0.75rem',
                        }}
                    >
                        <span
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.base00,
                            }}
                        >
                            cli, mcp & skills
                        </span>
                        <span
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.base1,
                            }}
                        >
                            install the CLI, configure MCP for native agent tool
                            calls, or add the artfct skills to guide your
                            agent's deployment workflows.
                        </span>

                        <div
                            style={{
                                display: 'flex',
                                flexDirection: 'column',
                                gap: '0.6rem',
                                marginTop: '0.25rem',
                            }}
                        >
                            <div
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: '0.75rem',
                                }}
                            >
                                <button
                                    onClick={async () => {
                                        const nextState = !mcpExpanded;
                                        setMcpExpanded(nextState);

                                        if (nextState) {
                                            await navigator.clipboard.writeText(
                                                `Please add the artfct skill to guide your deployment workflows:\nnpx skills add rubybear-lgtm/artfct@artfct\n\nNote: The MCP server is not required to use the skill, but is highly encouraged for native agent tool calls:\ncurl -fsSL https://artfct.dev/install.sh | sh && artfct setup`,
                                            );
                                            setCopiedAgentPrompt(true);
                                            setTimeout(
                                                () =>
                                                    setCopiedAgentPrompt(false),
                                                2000,
                                            );
                                        }
                                    }}
                                    className="result-action-btn"
                                    style={{
                                        padding: '0.4rem 0.8rem',
                                        fontFamily: MONO,
                                        fontSize: '11px',
                                        backgroundColor: mcpExpanded
                                            ? S.base1
                                            : S.base2,
                                        color: mcpExpanded ? S.base3 : S.base00,
                                        border: `1px solid ${S.base1}`,
                                        borderRadius: '3px',
                                        cursor: 'pointer',
                                        letterSpacing: '0.04em',
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: '0.4rem',
                                    }}
                                >
                                    <span>ask an ai agent</span>
                                    <span
                                        style={{
                                            fontSize: '9px',
                                            opacity: 0.8,
                                        }}
                                    >
                                        {mcpExpanded ? '▲' : '▼'}
                                    </span>
                                </button>
                                {copiedAgentPrompt && (
                                    <span
                                        className="fade-in"
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '11px',
                                            color: S.green,
                                        }}
                                    >
                                        copied prompt to clipboard!
                                    </span>
                                )}
                            </div>

                            {mcpExpanded && (
                                <div
                                    className="fade-in"
                                    style={{
                                        padding: '0.9rem 1rem',
                                        backgroundColor: S.base2,
                                        border: `1px solid ${S.base1}`,
                                        borderRadius: '3px',
                                        display: 'flex',
                                        flexDirection: 'column',
                                        gap: '0.4rem',
                                    }}
                                >
                                    <span
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '11px',
                                            color: S.base1,
                                        }}
                                    >
                                        install the artfct skill to give your
                                        agent built-in guidance. MCP is not a
                                        requirement to the skills (they fall
                                        back to API deploys), but it is highly
                                        encouraged for a native tool call:
                                    </span>
                                    <pre
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '11px',
                                            color: S.base0,
                                            backgroundColor: S.base3,
                                            padding: '0.75rem',
                                            margin: 0,
                                            borderRadius: '2px',
                                            border: `1px solid ${S.base2}`,
                                            whiteSpace: 'pre-wrap',
                                            wordBreak: 'break-word',
                                            lineHeight: 1.5,
                                        }}
                                    >
                                        {`# 1. install the skill (MCP optional but encouraged):
npx skills add rubybear-lgtm/artfct@artfct

# 2. (optional but highly encouraged) setup MCP for native tool calls:
curl -fsSL https://artfct.dev/install.sh | sh && artfct setup`}
                                    </pre>
                                </div>
                            )}

                            <div
                                style={{
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: '0.4rem',
                                    marginTop: '0.5rem',
                                }}
                            >
                                <span
                                    style={{
                                        fontFamily: MONO,
                                        fontSize: '11px',
                                        color: S.base1,
                                    }}
                                >
                                    or run the command to install it yourself:
                                </span>
                                <div
                                    style={{
                                        display: 'flex',
                                        border: `1px solid ${S.base1}`,
                                        borderRadius: '2px',
                                        backgroundColor: S.base3,
                                    }}
                                >
                                    <pre
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '11px',
                                            color: S.base0,
                                            padding: '0.6rem 0.8rem',
                                            margin: 0,
                                            flexGrow: 1,
                                            overflowX: 'auto',
                                        }}
                                    >
                                        <span style={{ color: S.base1 }}>
                                            ${' '}
                                        </span>
                                        curl -fsSL https://artfct.dev/install.sh
                                        | sh
                                    </pre>
                                    <button
                                        onClick={async () => {
                                            await navigator.clipboard.writeText(
                                                `curl -fsSL https://artfct.dev/install.sh | sh`,
                                            );
                                            setCopiedSelfInstall(true);
                                            setTimeout(
                                                () =>
                                                    setCopiedSelfInstall(false),
                                                2000,
                                            );
                                        }}
                                        style={{
                                            padding: '0 0.8rem',
                                            fontFamily: MONO,
                                            fontSize: '11px',
                                            backgroundColor: copiedSelfInstall
                                                ? S.green
                                                : S.base2,
                                            color: copiedSelfInstall
                                                ? S.base3
                                                : S.base00,
                                            border: 'none',
                                            borderLeft: `1px solid ${S.base1}`,
                                            cursor: 'pointer',
                                        }}
                                    >
                                        {copiedSelfInstall ? 'copied' : 'copy'}
                                    </button>
                                </div>
                            </div>

                            <div
                                style={{
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: '0.4rem',
                                    marginTop: '0.5rem',
                                }}
                            >
                                <span
                                    style={{
                                        fontFamily: MONO,
                                        fontSize: '11px',
                                        color: S.base1,
                                    }}
                                >
                                    or install only the agent skills:
                                </span>
                                <div
                                    style={{
                                        display: 'flex',
                                        border: `1px solid ${S.base1}`,
                                        borderRadius: '2px',
                                        backgroundColor: S.base3,
                                    }}
                                >
                                    <pre
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: '11px',
                                            color: S.base0,
                                            padding: '0.6rem 0.8rem',
                                            margin: 0,
                                            flexGrow: 1,
                                            overflowX: 'auto',
                                        }}
                                    >
                                        <span style={{ color: S.base1 }}>
                                            ${' '}
                                        </span>
                                        npx skills add
                                        rubybear-lgtm/artfct@artfct
                                    </pre>
                                    <button
                                        onClick={async () => {
                                            await navigator.clipboard.writeText(
                                                `npx skills add rubybear-lgtm/artfct@artfct`,
                                            );
                                            setCopiedSkillsInstall(true);
                                            setTimeout(
                                                () =>
                                                    setCopiedSkillsInstall(
                                                        false,
                                                    ),
                                                2000,
                                            );
                                        }}
                                        style={{
                                            padding: '0 0.8rem',
                                            fontFamily: MONO,
                                            fontSize: '11px',
                                            backgroundColor: copiedSkillsInstall
                                                ? S.green
                                                : S.base2,
                                            color: copiedSkillsInstall
                                                ? S.base3
                                                : S.base00,
                                            border: 'none',
                                            borderLeft: `1px solid ${S.base1}`,
                                            cursor: 'pointer',
                                        }}
                                    >
                                        {copiedSkillsInstall
                                            ? 'copied'
                                            : 'copy'}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <Link
                            href="/docs#cli"
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.blue,
                                textDecoration: 'none',
                                marginTop: '0.25rem',
                            }}
                        >
                            install & usage docs
                        </Link>
                    </div>

                    {/* ── about / seo content ── */}
                    <div
                        style={{
                            width: '100%',
                            paddingTop: '2rem',
                            borderTop: `1px solid ${S.base2}`,
                            display: 'flex',
                            flexDirection: 'column',
                            gap: '1.2rem',
                        }}
                    >
                        <div
                            style={{
                                fontFamily: SANS,
                                fontSize: '14px',
                                lineHeight: 1.7,
                                color: S.base0,
                            }}
                        >
                            <h2
                                style={{
                                    fontFamily: MONO,
                                    fontSize: '12px',
                                    fontWeight: 400,
                                    color: S.base00,
                                    margin: '0 0 1rem',
                                    letterSpacing: '0.04em',
                                    textTransform: 'uppercase',
                                }}
                            >
                                what is artfct?
                            </h2>
                            <p style={{ margin: '0 0 0.8rem' }}>
                                artfct is an{' '}
                                <strong style={{ color: S.base00 }}>
                                    instant encrypted HTML sharing
                                </strong>{' '}
                                tool for developers. Drop a self-contained HTML
                                file — or pipe one via CLI, API, or AI agent —
                                and get a shareable link in seconds. No sign-up,
                                no accounts, no configuration.
                            </p>
                            <p style={{ margin: '0 0 0.8rem' }}>
                                Every artifact is encrypted in the browser with
                                AES-GCM before it ever reaches the server. The
                                encryption key lives in the URL fragment, which
                                the server never sees. Choose from three access
                                tiers:{' '}
                                <span style={{ color: S.cyan }}>public</span>,{' '}
                                <span style={{ color: S.cyan }}>secure</span>,
                                or{' '}
                                <span style={{ color: S.cyan }}>ephemeral</span>
                                . All artifacts use sliding expiration — each
                                access resets the clock. Default TTL is 5 days,
                                configurable up to 1 year.
                            </p>
                            <p style={{ margin: 0 }}>
                                Perfect for sharing UI prototypes, dashboard
                                previews, AI-generated visual outputs, HTML
                                demos, markdown documents, and any other
                                self-contained web content. Works from the
                                browser, terminal, and through MCP-compatible AI
                                agents like Claude, Cursor, and Gemini.
                            </p>
                        </div>
                    </div>

                    {/* ── FAQ ── */}
                    <div
                        style={{
                            width: '100%',
                            paddingTop: '2rem',
                            borderTop: `1px solid ${S.base2}`,
                            display: 'flex',
                            flexDirection: 'column',
                            gap: '0.75rem',
                        }}
                    >
                        <h2
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                fontWeight: 400,
                                color: S.base00,
                                margin: '0 0 0.5rem',
                                letterSpacing: '0.04em',
                                textTransform: 'uppercase',
                            }}
                        >
                            faq
                        </h2>
                        <FaqItem q="What is artfct?">
                            artfct is an instant encrypted HTML sharing tool for
                            developers. Drop a self-contained HTML or Markdown
                            file — via browser, CLI, API, or AI agent — and get
                            a shareable link in seconds. No sign-up required.
                            Think of it as "deploy and share" for self-contained
                            web content.
                        </FaqItem>
                        <FaqItem q="Is artfct free?">
                            Yes. All artifact tiers are free right now. Paid
                            plans with higher usage limits may be added in the
                            future, but the core service will remain free.
                        </FaqItem>
                        <FaqItem q="How does encryption work?">
                            Every artifact is encrypted in the browser using
                            AES-GCM before it ever reaches the server. The
                            encryption key is embedded in the URL fragment (the
                            part after #), which the server never sees. For
                            secure artifacts, the preview is blurred by default
                            — only someone with the full URL can read the
                            content.
                        </FaqItem>
                        <FaqItem q="What are the three tiers?">
                            <strong style={{ color: S.cyan }}>public</strong> —
                            open-access URLs, shareable with anyone. Best for
                            demos, prototypes, and public documents.
                            <br />
                            <strong style={{ color: S.cyan }}>secure</strong> —
                            high-entropy fragment keys with blurred previews by
                            default. The content is encrypted and only
                            accessible with the full URL. Best for sensitive
                            documents or internal tools.
                            <br />
                            <strong style={{ color: S.cyan }}>
                                ephemeral
                            </strong>{' '}
                            — intentionally short-lived. Same as public, just
                            named for things you don't need to keep. Best for
                            temporary shares, drafts, and one-off reviews.
                        </FaqItem>
                        <FaqItem q="How long do artifacts last?">
                            All artifacts use sliding expiration — every access
                            resets the clock. The default TTL is 5 days,
                            configurable up to 1 year. If an artifact isn't
                            accessed within its TTL, it expires and is deleted.
                            There is no "permanent" tier — everything has a
                            shelf life.
                        </FaqItem>
                        <FaqItem q="Can I use artfct from the CLI?">
                            Yes. Pipe HTML from stdin:{' '}
                            <code
                                style={{
                                    fontFamily: MONO,
                                    fontSize: '12px',
                                    color: S.base00,
                                    backgroundColor: S.base2,
                                    padding: '0.1em 0.3em',
                                }}
                            >
                                cat dashboard.html | npx artfct
                            </code>
                            . The CLI returns a URL to stdout — perfect for
                            shell scripts, CI pipelines, and automation.
                        </FaqItem>
                        <FaqItem q="Does artfct work with AI agents?">
                            Yes. Install the artfct MCP server or the artfct
                            skill in Claude Code, Cursor, Codex, or Gemini. Your
                            agent can build HTML artifacts (dashboards,
                            diagrams, presentations, tools) and deploy them
                            automatically with a single MCP call. See the{' '}
                            <Link
                                href="/docs"
                                style={{
                                    color: S.blue,
                                    textDecoration: 'none',
                                }}
                            >
                                docs
                            </Link>{' '}
                            for setup instructions.
                        </FaqItem>
                    </div>

                    {/* ── footer ── */}
                    <footer
                        style={{
                            width: '100%',
                            paddingTop: '1.25rem',
                            borderTop: `1px solid ${S.base2}`,
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            fontFamily: MONO,
                            fontSize: '14px',
                        }}
                    >
                        <div style={{ display: 'flex', gap: '1.5rem' }}>
                            <Link
                                href="/docs"
                                style={{
                                    color: S.base1,
                                    textDecoration: 'none',
                                }}
                            >
                                docs
                            </Link>
                            <Link
                                href="/blog"
                                style={{
                                    color: S.base1,
                                    textDecoration: 'none',
                                }}
                            >
                                blog
                            </Link>
                            <a
                                href="https://github.com/rubybear-lgtm/artfct"
                                target="_blank"
                                rel="noreferrer"
                                style={{
                                    color: S.base1,
                                    textDecoration: 'none',
                                }}
                            >
                                github
                            </a>
                        </div>
                        <span style={{ color: S.base1 }}>
                            public · secure · ephemeral
                        </span>
                    </footer>
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
