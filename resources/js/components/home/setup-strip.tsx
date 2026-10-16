import { Link } from '@inertiajs/react';
import { ArrowRight, Check, ChevronDown } from 'lucide-react';
import { useState } from 'react';

export interface SetupStep {
    done: boolean;
    href: string;
    label: string;
    detail: string;
}

/**
 * One line counting the finished steps, with a toggle that expands the
 * full checklist. Hidden entirely once every step is done.
 */
export function SetupStrip({ steps }: { steps: SetupStep[] }) {
    const [open, setOpen] = useState(false);
    const done = steps.filter((step) => step.done).length;

    return (
        <section aria-label="Setup steps">
            <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                <p className="min-w-0 flex-1 text-sm break-words">
                    {done} of {steps.length} setup steps done
                </p>
                <button
                    type="button"
                    aria-expanded={open}
                    onClick={() => setOpen((value) => !value)}
                    className="inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary"
                >
                    {open ? 'Hide steps' : 'Show steps'}
                    <ChevronDown
                        aria-hidden="true"
                        className={`size-4 transition-transform motion-safe:duration-200 ${
                            open ? 'rotate-180' : ''
                        }`}
                    />
                </button>
            </div>
            {open && (
                <ol className="mt-3 divide-y divide-border border-y border-border">
                    {steps.map((step, index) => (
                        <li key={step.label}>
                            <Link
                                href={step.href}
                                className="group flex items-center gap-4 py-5"
                            >
                                <span
                                    aria-hidden="true"
                                    className={`flex size-8 shrink-0 items-center justify-center rounded-md text-sm font-semibold ${
                                        step.done
                                            ? 'bg-success text-primary-foreground'
                                            : 'bg-muted text-muted-foreground'
                                    }`}
                                >
                                    {step.done ? (
                                        <Check className="size-4" />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span
                                        className={`font-semibold break-words ${
                                            step.done
                                                ? 'text-muted-foreground line-through'
                                                : ''
                                        }`}
                                    >
                                        {step.label}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {step.detail}
                                    </span>
                                </span>
                                <span className="sr-only">
                                    {step.done ? 'Done' : 'Not done yet'}
                                </span>
                                <ArrowRight className="ml-auto size-4 text-muted-foreground transition-transform group-hover:translate-x-1" />
                            </Link>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
