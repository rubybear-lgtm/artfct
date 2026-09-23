import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { timeUntil } from '@/pages/welcome/welcome-cached-links';

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
        <div className="fade-in welcome-result">
            <div className="welcome-result-link-row">
                <div className="welcome-result-url">
                    {displayed}
                    {!done && <span className="cursor-blink" />}
                </div>
                <Button
                    onClick={copy}
                    className={`result-action-btn welcome-result-action ${copied ? 'copied' : ''}`}
                >
                    {copied ? 'copied' : 'copy'}
                </Button>
                <a
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="result-action-btn welcome-result-action welcome-result-open"
                >
                    open
                </a>
            </div>
            <div className="welcome-result-meta">
                <span className="welcome-result-expiration">
                    expires in {timeUntil(expiresAt)}
                </span>
                <Button onClick={onReset} className="welcome-result-reset">
                    deploy another
                </Button>
            </div>
        </div>
    );
}
