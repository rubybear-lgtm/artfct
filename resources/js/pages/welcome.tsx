import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ThemeToggle } from '@/lib/theme';

// ── Solarized (CSS custom properties — light/dark via prefers-color-scheme) ──
const S = {
    base3:   'var(--sol-base3)',
    base2:   'var(--sol-base2)',
    base1:   'var(--sol-base1)',
    base0:   'var(--sol-base0)',
    base00:  'var(--sol-base00)',
    yellow:  'var(--sol-yellow)',
    orange:  'var(--sol-orange)',
    red:     'var(--sol-red)',
    magenta: 'var(--sol-magenta)',
    violet:  'var(--sol-violet)',
    blue:    'var(--sol-blue)',
    cyan:    'var(--sol-cyan)',
    green:   'var(--sol-green)',
} as const;

const ACCENTS = [S.yellow, S.orange, S.red, S.magenta, S.violet, S.blue, S.cyan, S.green] as const;

// Complementary / analogous pairs (warm→cool or high-contrast)
const PAIRS: [number, number][] = [
    [0, 5],  // yellow → blue
    [1, 6],  // orange → cyan
    [2, 7],  // red → green
    [3, 7],  // magenta → green
    [4, 0],  // violet → yellow
    [2, 5],  // red → blue
    [6, 3],  // cyan → magenta
    [1, 4],  // orange → violet
];

// ── ASCII art ────────────────────────────────────────────────────────────────
// ANSI Shadow font, "artfct" — 50 cols × 6 rows
const ART = [
    ' █████╗ ██████╗ ████████╗███████╗ ██████╗████████╗',
    '██╔══██╗██╔══██╗╚══██╔══╝██╔════╝██╔════╝╚══██╔══╝',
    '███████║██████╔╝   ██║   █████╗  ██║        ██║   ',
    '██╔══██║██╔══██╗   ██║   ██╔══╝  ██║        ██║   ',
    '██║  ██║██║  ██║   ██║   ██║     ╚██████╗   ██║   ',
    '╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚═╝     ╚═════╝   ╚═╝   ',
];

// ── constants ────────────────────────────────────────────────────────────────
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
const WORKER_URL = (import.meta.env.VITE_WORKER_URL as string | undefined) ?? '';

// ── types ────────────────────────────────────────────────────────────────────
type Phase =
    | { t: 'idle' }
    | { t: 'selected';  file: File }
    | { t: 'deploying'; file: File }
    | { t: 'success';   url: string; expiresAt: string }
    | { t: 'error';     message: string };

// ── hooks ────────────────────────────────────────────────────────────────────
function useTypewriter(text: string, speed: number) {
    const [index, setIndex] = useState(0);

    useEffect(() => {
        setIndex(0);
    }, [text]);

    useEffect(() => {
        if (index >= text.length) return;
        const t = setTimeout(() => setIndex((i) => i + 1), speed);
        return () => clearTimeout(t);
    }, [index, text.length, speed]);

    return { displayed: text.slice(0, index), done: index >= text.length };
}

// ── helpers ──────────────────────────────────────────────────────────────────
function pickGradient(): [string, string] {
    const [a, b] = PAIRS[Math.floor(Math.random() * PAIRS.length)];
    return [ACCENTS[a], ACCENTS[b]];
}

function timeUntil(iso: string): string {
    const ms = new Date(iso).getTime() - Date.now();
    const min = Math.round(ms / 60_000);
    if (min <= 0) return 'expired';
    if (min < 60) return `${min} min`;
    return `${Math.round(min / 60)}h`;
}

// ── subcomponents ─────────────────────────────────────────────────────────────
function AsciiHero({ colorA, colorB }: { colorA: string; colorB: string }) {
    return (
        <pre
            aria-label="artfct"
            className="ascii-hero"
            style={{
                fontFamily: MONO,
                fontSize: 'clamp(9px, 2.1vw, 16px)',
                lineHeight: 1.2,
                letterSpacing: 0,
                background: `linear-gradient(to right, ${colorA}, ${colorB})`,
                WebkitBackgroundClip: 'text',
                WebkitTextFillColor: 'transparent',
                backgroundClip: 'text',
                userSelect: 'none',
                margin: 0,
                padding: 0,
                whiteSpace: 'pre',
            }}
        >
            {ART.join('\n')}
        </pre>
    );
}

interface DropZoneProps {
    phase: Phase;
    dragOver: boolean;
    onDragOver: (e: React.DragEvent) => void;
    onDragLeave: () => void;
    onDrop: (e: React.DragEvent) => void;
    onClick: () => void;
}

function DropZone({ phase, dragOver, onDragOver, onDragLeave, onDrop, onClick }: DropZoneProps) {
    const isActive = dragOver;
    const borderColor = isActive ? S.blue : S.base1;
    const borderStyle = isActive ? 'double' : 'solid';

    const hasFile = phase.t === 'selected' || phase.t === 'deploying';
    const filename = hasFile ? phase.file.name : null;

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={onClick}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') onClick(); }}
            onDragOver={onDragOver}
            onDragLeave={onDragLeave}
            onDrop={onDrop}
            style={{
                border: `1px ${borderStyle} ${borderColor}`,
                padding: '2.2rem 2rem',
                cursor: 'pointer',
                textAlign: 'center',
                backgroundColor: isActive ? 'color-mix(in srgb, var(--sol-blue) 7%, transparent)' : 'transparent',
                transition: 'border-color 0.1s ease, background-color 0.1s ease',
                fontFamily: MONO,
                fontSize: '16px',
                outline: 'none',
            }}
        >
            {filename ? (
                <>
                    <div style={{ color: S.cyan, marginBottom: '0.35rem', letterSpacing: '0.02em' }}>
                        ◆ {filename}
                    </div>
                    <div style={{ color: S.base1, fontSize: '14px' }}>
                        {phase.t === 'deploying' ? (
                            <span className="cursor-blink">deploying</span>
                        ) : (
                            'ready to deploy'
                        )}
                    </div>
                </>
            ) : (
                <>
                    <div style={{ color: S.base0, marginBottom: '0.35rem' }}>
                        drop your{' '}
                        <span style={{ color: S.base00, fontWeight: 600 }}>.html</span>
                        {' '}file here
                    </div>
                    <div style={{ color: S.base1, fontSize: '14px' }}>or click to browse</div>
                </>
            )}
        </div>
    );
}

interface ResultProps {
    url: string;
    expiresAt: string;
    onReset: () => void;
}

function Result({ url, expiresAt, onReset }: ResultProps) {
    const [copied, setCopied] = useState(false);
    const { displayed, done } = useTypewriter(url, 18);

    const copy = useCallback(async () => {
        await navigator.clipboard.writeText(url);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }, [url]);

    return (
        <div className="fade-in" style={{ width: '100%', display: 'flex', flexDirection: 'column', gap: '0.6rem' }}>
            <div style={{ display: 'flex', alignItems: 'stretch', border: `1px solid ${S.green}` }}>
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
                        backgroundColor: 'color-mix(in srgb, var(--sol-green) 8%, transparent)',
                        minWidth: 0,
                    }}
                >
                    {displayed}
                    {!done && <span className="cursor-blink" />}
                </div>
                <button
                    onClick={copy}
                    style={{
                        padding: '0 1.25rem',
                        fontFamily: MONO,
                        fontSize: '14px',
                        backgroundColor: copied ? S.green : S.base2,
                        color: copied ? S.base3 : S.base0,
                        border: 'none',
                        borderLeft: `1px solid ${S.base2}`,
                        cursor: 'pointer',
                        transition: 'background-color 0.15s ease, color 0.15s ease',
                        flexShrink: 0,
                        letterSpacing: '0.04em',
                        minWidth: '7rem',
                    }}
                >
                    {copied ? '✓ copied' : '⎘ copy'}
                </button>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ fontFamily: MONO, fontSize: '14px', color: S.base1 }}>
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
                    deploy another →
                </button>
            </div>
        </div>
    );
}

// ── page ─────────────────────────────────────────────────────────────────────
const TAGLINE = "share html. get a link. that's it.";

export default function Welcome() {
    const [gradient] = useState<[string, string]>(pickGradient);
    const [phase, setPhase] = useState<Phase>({ t: 'idle' });
    const [dragOver, setDragOver] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const tagline = useTypewriter(TAGLINE, 38);

    const acceptFile = useCallback((file: File) => {
        if (!file.name.toLowerCase().endsWith('.html') && file.type !== 'text/html') {
            setPhase({ t: 'error', message: 'only .html files are accepted' });
            return;
        }
        setPhase({ t: 'selected', file });
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
            if (file) acceptFile(file);
        },
        [acceptFile],
    );

    const handleDeploy = useCallback(async () => {
        if (phase.t !== 'selected') return;
        const { file } = phase;
        setPhase({ t: 'deploying', file });

        try {
            const html = await file.text();
            const res = await fetch(`${WORKER_URL}/v1/artifacts`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ html, tier: 'ephemeral' }),
            });

            if (!res.ok) {
                const body = (await res.json().catch(() => ({}))) as { message?: string };
                throw new Error(body.message ?? `server error ${res.status}`);
            }

            const data = (await res.json()) as { url: string; expires_at: string };
            setPhase({ t: 'success', url: data.url, expiresAt: data.expires_at });
        } catch (err) {
            const message = err instanceof Error ? err.message : 'deployment failed';
            setPhase({ t: 'error', message });
        }
    }, [phase]);

    const reset = useCallback(() => {
        setPhase({ t: 'idle' });
        if (inputRef.current) inputRef.current.value = '';
    }, []);

    const canDeploy = phase.t === 'selected';
    const isDeploying = phase.t === 'deploying';
    const isSuccess = phase.t === 'success';
    const showButton = !isSuccess;
    const buttonActive = canDeploy || isDeploying;

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
                    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif",
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
                    {/* ascii hero */}
                    <AsciiHero colorA={gradient[0]} colorB={gradient[1]} />

                    {/* tagline */}
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

                    {/* drop zone */}
                    <div style={{ width: '100%' }}>
                        <input
                            ref={inputRef}
                            type="file"
                            accept=".html,text/html"
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (file) acceptFile(file);
                            }}
                            style={{ display: 'none' }}
                            aria-label="Select HTML file"
                        />
                        <DropZone
                            phase={phase}
                            dragOver={dragOver}
                            onDragOver={handleDragOver}
                            onDragLeave={handleDragLeave}
                            onDrop={handleDrop}
                            onClick={() => inputRef.current?.click()}
                        />
                    </div>

                    {/* error */}
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
                            ✗ {phase.message}{' '}
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

                    {/* deploy button */}
                    {showButton && (
                        <button
                            className="deploy-btn"
                            onClick={handleDeploy}
                            disabled={!canDeploy}
                            style={{
                                fontFamily: MONO,
                                fontSize: '16px',
                                padding: '0.625rem 2.8rem',
                                backgroundColor: buttonActive ? S.red : 'transparent',
                                color: buttonActive ? S.base3 : S.base1,
                                border: `1px solid ${buttonActive ? S.red : S.base2}`,
                                cursor: canDeploy ? 'pointer' : 'not-allowed',
                                transition: 'background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease',
                                letterSpacing: '0.06em',
                            }}
                        >
                            {isDeploying ? (
                                <span className="cursor-blink">deploying</span>
                            ) : (
                                '[ deploy → ]'
                            )}
                        </button>
                    )}

                    {/* success */}
                    {isSuccess && phase.t === 'success' && (
                        <Result url={phase.url} expiresAt={phase.expiresAt} onReset={reset} />
                    )}

                    {/* footer */}
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
                                style={{ color: S.base1, textDecoration: 'none' }}
                            >
                                docs
                            </Link>
                            <a
                                href="https://github.com/rubybear-lgtm/artfct"
                                target="_blank"
                                rel="noreferrer"
                                style={{ color: S.base1, textDecoration: 'none' }}
                            >
                                github
                            </a>
                        </div>
                        <span style={{ color: S.base1 }}>ephemeral · secure · 60min</span>
                    </footer>

                    {/* cli callout */}
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
                        <span style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>
                            cli
                        </span>
                        <span style={{ fontFamily: MONO, fontSize: '12px', color: S.base1 }}>
                            deploy from your terminal, or run as an mcp server for claude code, cursor & more.
                        </span>
                        <pre
                            style={{
                                fontFamily: MONO,
                                fontSize: '13px',
                                color: S.base0,
                                backgroundColor: S.base2,
                                padding: '0.9rem 1rem',
                                margin: 0,
                                lineHeight: 1.7,
                            }}
                        >
                            <span style={{ color: S.base1 }}>$ </span>
                            {'curl -fsSL https://artfct.dev/install.sh | sh'}
                        </pre>
                        <Link
                            href="/docs#cli"
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.blue,
                                textDecoration: 'none',
                            }}
                        >
                            install & usage →
                        </Link>
                    </div>
                </div>
            </div>
        </>
    );
}
