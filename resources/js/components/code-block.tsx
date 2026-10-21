import { useCallback, useState } from 'react';

import { Button } from '@/components/ui/button';

/** A command, address or config snippet with a copy button. */
export function CodeBlock({ code }: { code: string }) {
    const [copied, setCopied] = useState(false);

    const copy = useCallback(async () => {
        await navigator.clipboard.writeText(code);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }, [code]);

    return (
        <div className="relative">
            <pre className="overflow-x-auto rounded-[10px] border border-border bg-paper p-4 pr-20 font-mono text-[13px] leading-relaxed text-foreground">
                {code}
            </pre>
            <Button
                type="button"
                onClick={copy}
                className="mt-2 ml-auto block rounded-md border border-border bg-background px-2.5 py-1 text-xs font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary sm:absolute sm:top-2.5 sm:right-2.5 sm:mt-0 sm:ml-0"
            >
                {copied ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}
