import { useCallback, useState } from 'react';

import { AiToolIcon } from '@/components/ai-tool-icon';
import { cn } from '@/lib/utils';

const MARKS = ['claude', 'chatgpt', 'cursor', 'copilot'] as const;

/**
 * The "Copy prompt" pill: one prompt that connects any agent to Artfct
 * (AiToolSetup::agentSetupPrompt). Hovering or focusing it lifts a slip with
 * the prompt's opening line, so copying is never blind. When the clipboard is
 * blocked the full prompt opens below the pill to copy by hand.
 *
 * The `copy-prompt-*` classes let the landing page, which has its own
 * palette, recolour it (resources/css/landing.css).

 */
export function CopyPrompt({
    prompt,
    className,
}: {
    prompt: string;
    className?: string;
}) {
    const [state, setState] = useState<'idle' | 'copied' | 'manual'>('idle');
    const opening = prompt.split('\n')[0];

    const copy = useCallback(async () => {
        try {
            await navigator.clipboard.writeText(prompt);
            setState('copied');
            setTimeout(() => setState('idle'), 2000);
        } catch {
            setState('manual');
        }
    }, [prompt]);

    return (
        <div className={cn('group/prompt relative', className)}>
            <button
                type="button"
                onClick={copy}
                data-testid="copy-prompt"
                className={cn(
                    'copy-prompt-button inline-flex h-14 w-full items-center justify-center gap-3.5 rounded-full border pr-6 pl-3.5 text-base font-semibold transition-colors sm:w-auto',
                    'border-border bg-paper text-foreground hover:border-muted-foreground',
                )}
            >
                <span className="inline-flex" aria-hidden="true">
                    {MARKS.map((mark) => (
                        <span
                            key={mark}
                            className="copy-prompt-mark -ml-[7px] grid size-8 place-items-center rounded-full border border-border bg-background transition-[margin] duration-300 group-focus-within/prompt:ml-0.5 group-hover/prompt:ml-0.5 first:ml-0 first:group-focus-within/prompt:ml-0 first:group-hover/prompt:ml-0 motion-reduce:transition-none"
                        >
                            <AiToolIcon tool={mark} size={18} />
                        </span>
                    ))}
                </span>
                <span className="min-w-[6.6em] text-left" aria-live="polite">
                    {state === 'copied' ? 'Copied' : 'Copy prompt'}
                </span>
            </button>

            <div
                aria-hidden="true"
                className="copy-prompt-slip pointer-events-none absolute bottom-[calc(100%+12px)] left-1/2 hidden w-[min(420px,86vw)] -translate-x-1/2 translate-y-2 rounded-[10px] border border-border bg-paper px-4 pt-3.5 pb-3 text-left text-[13.5px] leading-normal text-muted-foreground opacity-0 shadow-[0_18px_40px_-18px_rgba(20,10,10,0.35)] transition duration-200 group-focus-within/prompt:translate-y-0 group-focus-within/prompt:opacity-100 group-hover/prompt:translate-y-0 group-hover/prompt:opacity-100 motion-reduce:transition-none sm:block"
            >
                <span className="text-foreground">{opening}</span>
                <span className="mt-1.5 block text-xs">
                    Paste it into any agent. It finds the steps for its own app.
                </span>
            </div>

            {state === 'manual' && (
                <pre className="mt-3 max-h-72 overflow-auto rounded-[10px] border border-border bg-paper p-4 text-left font-mono text-[12.5px] leading-relaxed whitespace-pre-wrap text-foreground">
                    {prompt}
                </pre>
            )}
        </div>
    );
}
