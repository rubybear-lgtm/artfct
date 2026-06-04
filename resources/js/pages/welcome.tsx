import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { renderMarkdownToHtml } from '@/lib/markdown';
import { ThemeToggle } from '@/lib/theme';

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

const ART = [
    ' █████╗ ██████╗ ████████╗███████╗ ██████╗████████╗',
    '██╔══██╗██╔══██╗╚══██╔══╝██╔════╝██╔════╝╚══██╔══╝',
    '███████║██████╔╝   ██║   █████╗  ██║        ██║   ',
    '██╔══██║██╔══██╗   ██║   ██╔══╝  ██║        ██║   ',
    '██║  ██║██║  ██║   ██║   ██║     ╚██████╗   ██║   ',
    '╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚═╝      ╚═════╝   ╚═╝   ',
];

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
const WORKER_URL =
    (import.meta.env.VITE_WORKER_URL as string | undefined) ?? '';
const MAX_BYTES = 1024 * 1024;
const TAGLINE = "share html. get a link. that's it.";

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

    return `${Math.round(min / 60)}h`;
}

function getRemainingMinutes(expiresAtStr: string): number {
    const ms = new Date(expiresAtStr).getTime() - Date.now();

    return Math.max(1, Math.round(ms / 60_000));
}

function AsciiHero({ colorA, colorB }: { colorA: string; colorB: string }) {
    return (
        <pre
            aria-label="artfct"
            className="ascii-hero"
            style={{
                fontFamily: MONO,
                fontSize: 'min(16px, calc((100vw - 3rem) / 30))',
                lineHeight: 1,
                letterSpacing: 0,
                background: `linear-gradient(to right, ${colorA}, ${colorB})`,
                WebkitBackgroundClip: 'text',
                WebkitTextFillColor: 'transparent',
                backgroundClip: 'text',
                userSelect: 'none',
                margin: 0,
                padding: 0,
                whiteSpace: 'pre',
                overflow: 'hidden',
            }}
        >
            {ART.join('\n')}
        </pre>
    );
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

export default function Welcome() {
    const [gradient] = useState<[string, string]>(pickGradient);
    const [phase, setPhase] = useState<Phase>({ t: 'idle' });
    const [dragOver, setDragOver] = useState(false);

    const [mcpExpanded, setMcpExpanded] = useState(false);
    const [copiedAgentPrompt, setCopiedAgentPrompt] = useState(false);
    const [copiedSelfInstall, setCopiedSelfInstall] = useState(false);

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
    const [newTtlMinutes, setNewTtlMinutes] = useState<number>(60);
    const [isUpdatingTtl, setIsUpdatingTtl] = useState(false);
    const [isDeletingLink, setIsDeletingLink] = useState(false);
    const [modalError, setModalError] = useState<string | null>(null);
    const [modalSuccess, setModalSuccess] = useState(false);

    const inputRef = useRef<HTMLInputElement>(null);
    const tagline = useTypewriter(TAGLINE, 38);

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
        setNewTtlMinutes(remaining > 0 ? remaining : 60);
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

            if (new Blob([html]).size > MAX_BYTES) {
                setPhase({
                    t: 'error',
                    message: 'payload exceeds 1 MB limit',
                });

                return;
            }

            const res = await fetch(`${WORKER_URL}/v1/artifacts`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ html, tier: 'ephemeral' }),
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
            setPhase({
                t: 'success',
                url: data.url,
                expiresAt: data.expires_at,
            });

            const newLink: CachedLink = {
                id: data.id,
                url: data.url,
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
    }, [phase, saveCachedLinks]);

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
            <Head title="artfct — share html instantly" />
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
                    <AsciiHero colorA={gradient[0]} colorB={gradient[1]} />

                    <p
                        aria-label={TAGLINE}
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
                                                max={1440}
                                                value={newTtlMinutes}
                                                onChange={(e) =>
                                                    setNewTtlMinutes(
                                                        parseInt(
                                                            e.target.value,
                                                        ) || 60,
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
                                                        15, 60, 360, 1440,
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
                            ephemeral · secure · 60min
                        </span>
                    </footer>

                    {/* ── cli & mcp callout ── */}
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
                            cli & mcp
                        </span>
                        <span
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.base1,
                            }}
                        >
                            install the CLI and automatically configure MCP for all detected agents (Cursor, Claude Desktop, Gemini, and Codex) in one step.
                        </span>

                        <div
                            style={{
                                display: 'flex',
                                flexDirection: 'column',
                                gap: '0.6rem',
                                marginTop: '0.25rem',
                            }}
                        >
                            <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                                <button
                                    onClick={async () => {
                                        const nextState = !mcpExpanded;
                                        setMcpExpanded(nextState);
                                        if (nextState) {
                                            await navigator.clipboard.writeText(`Please install the artfct CLI and configure it as an MCP server on my machine by running:\ncurl -fsSL https://artfct.dev/install.sh | sh`);
                                            setCopiedAgentPrompt(true);
                                            setTimeout(() => setCopiedAgentPrompt(false), 2000);
                                        }
                                    }}
                                    className="result-action-btn"
                                    style={{
                                        padding: '0.4rem 0.8rem',
                                        fontFamily: MONO,
                                        fontSize: '11px',
                                        backgroundColor: mcpExpanded ? S.base1 : S.base2,
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
                                    <span style={{ fontSize: '9px', opacity: 0.8 }}>
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
                                    <span style={{ fontFamily: MONO, fontSize: '11px', color: S.base1 }}>
                                        paste this prompt directly into your terminal-capable agent (e.g. Claude Code or Cursor Composer) to install and configure:
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
{`Please install the artfct CLI and configure it as an MCP server on my machine by running:
curl -fsSL https://artfct.dev/install.sh | sh`}
                                    </pre>
                                </div>
                            )}

                            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', marginTop: '0.5rem' }}>
                                <span style={{ fontFamily: MONO, fontSize: '11px', color: S.base1 }}>
                                    or run the command to install it yourself:
                                </span>
                                <div style={{ display: 'flex', border: `1px solid ${S.base1}`, borderRadius: '2px', backgroundColor: S.base3 }}>
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
                                        <span style={{ color: S.base1 }}>$ </span>curl -fsSL https://artfct.dev/install.sh | sh
                                    </pre>
                                    <button
                                        onClick={async () => {
                                            await navigator.clipboard.writeText(`curl -fsSL https://artfct.dev/install.sh | sh`);
                                            setCopiedSelfInstall(true);
                                            setTimeout(() => setCopiedSelfInstall(false), 2000);
                                        }}
                                        style={{
                                            padding: '0 0.8rem',
                                            fontFamily: MONO,
                                            fontSize: '11px',
                                            backgroundColor: copiedSelfInstall ? S.green : S.base2,
                                            color: copiedSelfInstall ? S.base3 : S.base00,
                                            border: 'none',
                                            borderLeft: `1px solid ${S.base1}`,
                                            cursor: 'pointer',
                                        }}
                                    >
                                        {copiedSelfInstall ? 'copied' : 'copy'}
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
                </div>
            </div>
        </>
    );
}
