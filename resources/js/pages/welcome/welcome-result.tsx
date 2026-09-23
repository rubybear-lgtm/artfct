import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { timeUntil } from '@/pages/welcome/welcome-cached-links';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base3: 'var(--sol-base3)',
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base00: 'var(--sol-base00)',
    base0: 'var(--sol-base0)',
    green: 'var(--sol-green)',
} as const;

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

        const timeout = setTimeout(
            () => setIndex((current) => current + 1),
            speed,
        );

        return () => clearTimeout(timeout);
    }, [index, text.length, speed]);

    return { displayed: text.slice(0, index), done: index >= text.length };
}

export function WelcomeResult({
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
                <Button
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
                </Button>
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
                <Button
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
                </Button>
            </div>
        </div>
    );
}
