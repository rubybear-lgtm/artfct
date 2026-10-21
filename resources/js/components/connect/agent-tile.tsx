import { AiToolIcon, aiToolMarkKey } from '@/components/ai-tool-icon';
import { cn } from '@/lib/utils';

/** The square mark for an agent: its logo, or its initial in the serif. */
export function AgentTile({
    icon,
    label,
    size = 'md',
    className,
}: {
    icon: string | null | undefined;
    label: string;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}) {
    const box = {
        sm: 'size-7 rounded-full',
        md: 'size-12 rounded-xl',
        lg: 'size-[76px] rounded-[18px]',
    }[size];
    const mark = { sm: 16, md: 26, lg: 40 }[size];
    const letter = { sm: 'text-sm', md: 'text-[22px]', lg: 'text-[34px]' }[
        size
    ];

    return (
        <span
            aria-hidden="true"
            className={cn(
                'grid shrink-0 place-items-center border border-border bg-background',
                box,
                className,
            )}
        >
            {aiToolMarkKey(icon) ? (
                <AiToolIcon tool={icon} size={mark} />
            ) : (
                <span
                    className={cn(
                        'font-serif leading-none text-muted-foreground',
                        letter,
                    )}
                >
                    {label.charAt(0)}
                </span>
            )}
        </span>
    );
}
