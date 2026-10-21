import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';

import { CodeBlock } from '@/components/code-block';
import { AgentTile } from '@/components/connect/agent-tile';
import { CopyPrompt } from '@/components/connect/copy-prompt';
import { agentName } from '@/components/connect/types';
import type { AgentCard, AgentKind } from '@/components/connect/types';
import { SitePage } from '@/components/site-chrome';
import { cn } from '@/lib/utils';
import connectRoutes from '@/routes/connect';

interface GuideStep {
    text: string;
    code?: string;
}

interface Guide {
    id: string;
    label: string;
    kind: AgentKind;
    method: string;
    summary: string;
    maker?: string;
    icon?: string;
    initHost?: string;
    intro?: string;
    installLink?: string;
    installLinkFallback?: string;
    steps: GuideStep[];
}

interface ConnectShowProps {
    meta: { title: string; description: string };
    guide: Guide;
    mcpUrl: string;
    quickInstall: string;
    prompt: string;
    related: AgentCard[];
    troubleshooting: Array<{ name: string; type: string; note: string }>;
}

const KIND_LABEL: Record<AgentKind, string> = {
    terminal: 'Terminal',
    editor: 'Editor',
    chat: 'Chat app',
    other: 'Any agent',
};

/* Prompts that sell the second agent: each asks for work another agent made. */
const HANDOFF_PROMPTS: Array<[string, string]> = [
    [
        'Find the onboarding spec Cursor published and pick up where it left off.',
        'Reads another agent’s work and cites it',
    ],
    [
        'Publish this report to Artfct so the team can open it.',
        'Shares to your team’s workspace',
    ],
    [
        'What did we decide about pricing? Link the artifact.',
        'Searches by meaning, answers with a source',
    ],
    [
        'Leave a comment on the API spec with these review notes.',
        'Comments where everyone can see',
    ],
];

/**
 * Step text wraps inline code in backticks, the same convention the docs page
 * and /llms-full.txt render. Splitting on them here keeps one source for the
 * step copy instead of a second, drifting copy written for this page.
 */
function renderStepText(text: string): ReactNode[] {
    return text.split('`').map((part, index) =>
        index % 2 === 1 ? (
            <code
                key={index}
                className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.88em] text-foreground"
            >
                {part}
            </code>
        ) : (
            <span key={index}>{part}</span>
        ),
    );
}

function Section({
    id,
    title,
    lede,
    children,
}: {
    id: string;
    title: string;
    lede?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            aria-labelledby={`${id}-title`}
            className="scroll-mt-24"
        >
            <h2
                id={`${id}-title`}
                className="font-serif text-[30px] leading-tight font-normal"
            >
                {title}
            </h2>
            {lede && (
                <p className="mt-1.5 mb-5 text-muted-foreground">{lede}</p>
            )}
            {children}
        </section>
    );
}

/** A numbered step list with a hairline joining the numbers. */
function Steps({
    children,
    lit = Number.POSITIVE_INFINITY,
}: {
    children: ReactNode[];
    lit?: number;
}) {
    return (
        <ol className="m-0 list-none p-0">
            {children.map((child, index) => (
                <li
                    key={index}
                    className="relative grid grid-cols-[40px_minmax(0,1fr)] gap-4 pb-6 last:pb-0"
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'relative z-[1] grid size-8 place-items-center rounded-full border font-serif text-[15px] transition-colors',
                            index < lit
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-background text-muted-foreground',
                        )}
                    >
                        {index + 1}
                    </span>
                    {index < children.length - 1 && (
                        <span
                            aria-hidden="true"
                            className="absolute top-[34px] bottom-0 left-4 w-px bg-border"
                        />
                    )}
                    <div className="min-w-0 pt-1 leading-[1.75] text-muted-foreground">
                        {child}
                    </div>
                </li>
            ))}
        </ol>
    );
}

function AskCard({ text, hint }: { text: string; hint: string }) {
    const [copied, setCopied] = useState(false);
    const copy = useCallback(async () => {
        await navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    }, [text]);

    return (
        <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-xl border border-border bg-paper px-4 py-3.5">
            <div>
                <p className="font-serif text-[17px] leading-snug text-foreground">
                    “{text}”
                </p>
                <p className="mt-0.5 text-[13px] text-muted-foreground">
                    {hint}
                </p>
            </div>
            <button
                type="button"
                onClick={copy}
                className="rounded-full border border-border px-3 py-1.5 text-[13px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary"
            >
                {copied ? 'Copied' : 'Copy'}
            </button>
        </div>
    );
}

function Bubble({
    who,
    from,
    on,
    children,
}: {
    who: string;
    from: 'you' | 'agent';
    on: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-xl px-3 py-2.5 transition-opacity duration-300 motion-reduce:transition-none',
                from === 'you'
                    ? 'max-w-[88%] justify-self-end rounded-br-sm bg-muted'
                    : 'max-w-[92%] rounded-bl-sm border border-success bg-background',
                on ? 'opacity-100' : 'opacity-50',
            )}
        >
            <span
                className={cn(
                    'block text-[11.5px] tracking-[0.08em] uppercase',
                    from === 'you' ? 'text-muted-foreground' : 'text-success',
                )}
            >
                {who}
            </span>
            {children}
        </div>
    );
}

function Note({ on, children }: { on: boolean; children: ReactNode }) {
    return (
        <p
            className={cn(
                'justify-self-center rounded-full border border-dashed border-border px-2.5 py-1 text-center text-[12.5px] text-muted-foreground transition-opacity duration-300 motion-reduce:transition-none',
                on ? 'opacity-100' : 'opacity-50',
            )}
        >
            {children}
        </p>
    );
}

/**
 * What the person will see, as a short conversation. Each line brightens as
 * its part of the page scrolls past, so the outcome shows before the work.
 * Every line is readable at rest; only the emphasis moves.
 */
function WhatYouSee({
    name,
    chat,
    stage,
}: {
    name: string;
    chat: boolean;
    stage: number;
}) {
    return (
        <aside
            aria-label="What you’ll see"
            className="overflow-hidden rounded-2xl border border-border bg-paper lg:sticky lg:top-24"
        >
            <header className="flex items-center justify-between border-b border-border px-4 py-3.5 text-[13px] text-muted-foreground">
                <b className="font-semibold text-foreground">What you’ll see</b>
                <span aria-hidden="true" className="flex gap-1.5">
                    <i className="size-2 rounded-full bg-border" />
                    <i className="size-2 rounded-full bg-border" />
                    <i className="size-2 rounded-full bg-border" />
                </span>
            </header>
            <div className="grid gap-3 p-4 text-sm">
                <Note on={stage >= 0}>
                    {chat
                        ? 'Artfct added as a connector'
                        : 'artfct added · needs sign-in'}
                </Note>
                <Note on={stage >= 1}>
                    Browser · Allow {name} to use Artfct? · Approved
                </Note>
                <Bubble who="You" from="you" on={stage >= 2}>
                    Which Artfct workspace am I connected to?
                </Bubble>
                <Bubble
                    who={`${name} · get_connection`}
                    from="agent"
                    on={stage >= 2}
                >
                    You’re in your team’s workspace, and search is on. I can
                    search, read and publish there.
                </Bubble>
                <Bubble who="You" from="you" on={stage >= 3}>
                    Find the onboarding spec Cursor published and pick up where
                    it left off.
                </Bubble>
            </div>
            <footer className="border-t border-border px-4 py-3 text-[13px] text-muted-foreground">
                Same workspace in every agent you connect.
            </footer>
        </aside>
    );
}

const STAGES = ['start', 'check', 'try'];

/**
 * Which part of the guide is in view (0 setup, 2 check, 3 use). It rests at
 * "everything shown" until the reader scrolls, so the first frame is
 * complete for thumbnails and readers without IntersectionObserver.
 */
function useStage(): number {
    const [stage, setStage] = useState(3);
    const seen = useRef(false);

    useEffect(() => {
        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting && seen.current) {
                        const index = STAGES.indexOf(entry.target.id);

                        setStage(index === 0 ? 0 : index + 1);
                    }
                }

                seen.current = true;
            },
            { rootMargin: '-45% 0px -45% 0px' },
        );

        for (const id of STAGES) {
            const element = document.getElementById(id);

            if (element) {
                observer.observe(element);
            }
        }

        return () => observer.disconnect();
    }, []);

    return stage;
}

export default function ConnectShow({
    meta,
    guide,
    prompt,
    related,
    troubleshooting,
}: ConnectShowProps) {
    const name = guide.label === 'Other tools' ? 'Your agent' : guide.label;
    const stage = useStage();
    const initCall =
        guide.initHost === 'codex' ? '$artfct-init' : '/artfct-init';
    const tags = [
        KIND_LABEL[guide.kind],
        guide.method,
        'Browser sign-in, no key',
    ];

    return (
        <SitePage active="docs">
            <Head title={meta.title}>
                <meta
                    head-key="description"
                    name="description"
                    content={meta.description}
                />
            </Head>

            <main className="mx-auto max-w-[1120px] px-5 pb-20">
                <Link
                    href={connectRoutes.index().url}
                    className="inline-flex items-center gap-2 pt-10 text-[14.5px] text-muted-foreground hover:text-primary"
                >
                    <span aria-hidden="true">←</span> All agents
                </Link>

                <header className="grid gap-5 border-b border-border pt-7 pb-8 sm:grid-cols-[76px_minmax(0,1fr)] sm:items-center sm:gap-6">
                    <AgentTile
                        icon={guide.icon}
                        label={guide.label}
                        size="lg"
                    />
                    <div>
                        {guide.maker && (
                            <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                                {guide.maker}
                            </p>
                        )}
                        <h1 className="mt-1 mb-2 font-serif text-[clamp(36px,5vw,56px)] leading-[1.04] font-normal tracking-[-0.015em] text-balance">
                            Connect {agentName(guide.label)}{' '}
                            <span className="text-muted-foreground">
                                to Artfct
                            </span>
                        </h1>
                        <p className="max-w-[40em] text-[17.5px] text-muted-foreground">
                            {name} publishes to your team’s Artfct workspace and
                            searches what your other agents made, with links
                            back to the source.
                        </p>
                        <div className="mt-3.5 flex flex-wrap gap-2 text-[12.5px] text-muted-foreground">
                            {tags.map((tag) => (
                                <span
                                    key={tag}
                                    className="rounded-full border border-border px-2.5 py-0.5"
                                >
                                    {tag}
                                </span>
                            ))}
                        </div>
                    </div>
                </header>

                <div className="grid items-start gap-14 pt-10 lg:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="grid min-w-0 gap-14">
                        <Section
                            id="start"
                            title="Quick start"
                            lede={
                                <>
                                    Follow the steps, or copy the prompt, paste
                                    it into {name} and let it do them for you.
                                </>
                            }
                        >
                            <div className="mb-7">
                                <CopyPrompt prompt={prompt} />
                            </div>
                            {guide.intro && (
                                <p className="mb-5 border-l-2 border-primary bg-paper px-4 py-3 text-[15px] text-muted-foreground">
                                    {renderStepText(guide.intro)}
                                </p>
                            )}
                            {guide.installLink && (
                                <div className="mb-6 flex flex-wrap items-center gap-3">
                                    <a
                                        href={guide.installLink}
                                        className="inline-flex h-11 items-center rounded-full bg-primary px-5 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                                    >
                                        Add to {guide.label}
                                    </a>
                                    {guide.installLinkFallback && (
                                        <a
                                            href={guide.installLinkFallback}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-sm font-semibold text-primary underline-offset-4 hover:underline"
                                        >
                                            Open in browser instead
                                        </a>
                                    )}
                                </div>
                            )}
                            <Steps lit={stage === 0 ? 1 : undefined}>
                                {guide.steps.map((step) => (
                                    <div key={step.text}>
                                        {renderStepText(step.text)}
                                        {step.code && (
                                            <div className="mt-3">
                                                <CodeBlock code={step.code} />
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </Steps>
                        </Section>

                        <Section
                            id="check"
                            title="Check that it works"
                            lede={<>Ask {name}:</>}
                        >
                            <AskCard
                                text="Which Artfct workspace am I connected to?"
                                hint="It should name your team and say search is on."
                            />
                        </Section>

                        {guide.initHost && (
                            <Section
                                id="automatic"
                                title="Make it automatic"
                                lede={
                                    <>
                                        Set {name} up to search your team’s work
                                        before it starts: for all your projects,
                                        just you in this project, or the whole
                                        team in this repo.
                                    </>
                                }
                            >
                                <Steps>
                                    {[
                                        <div key="add">
                                            Add the setup skill:
                                            <div className="mt-3">
                                                <CodeBlock code="npx skills add rubybear-lgtm/artfct@artfct-init" />
                                            </div>
                                        </div>,
                                        <div key="run">
                                            In your project, run{' '}
                                            {renderStepText(`\`${initCall}\``)}{' '}
                                            or ask {name} to “set up Artfct”. It
                                            asks where the setup should apply,
                                            and asks again before it grants any
                                            permission.
                                        </div>,
                                    ]}
                                </Steps>
                            </Section>
                        )}

                        <Section
                            id="try"
                            title="Try it across your agents"
                            lede="The point is the handoff. Ask for work another agent made."
                        >
                            <div className="grid gap-2.5">
                                {HANDOFF_PROMPTS.map(([text, hint]) => (
                                    <AskCard
                                        key={text}
                                        text={text}
                                        hint={hint}
                                    />
                                ))}
                            </div>
                        </Section>

                        <Section id="help" title="If it does not work">
                            <div className="border-t border-border">
                                {troubleshooting.map((item) => (
                                    <details
                                        key={item.name}
                                        className="group border-b border-border"
                                    >
                                        <summary className="flex cursor-pointer list-none justify-between gap-3 py-3.5 font-semibold text-foreground">
                                            {item.name}
                                            <span
                                                aria-hidden="true"
                                                className="font-normal text-muted-foreground transition-transform group-open:rotate-45"
                                            >
                                                +
                                            </span>
                                        </summary>
                                        <p className="pb-4 text-muted-foreground">
                                            {renderStepText(item.note)}
                                        </p>
                                    </details>
                                ))}
                            </div>
                        </Section>

                        <Section
                            id="others"
                            title="Other agents"
                            lede="Connect them to the same workspace."
                        >
                            <ul className="flex flex-wrap gap-2">
                                {related.map((tool) => (
                                    <li key={tool.id}>
                                        <Link
                                            href={
                                                connectRoutes.show(tool.id).url
                                            }
                                            className="inline-flex items-center gap-2 rounded-full border border-border py-1.5 pr-3.5 pl-1.5 text-[14.5px] text-foreground transition-colors hover:border-primary hover:text-primary"
                                        >
                                            <AgentTile
                                                icon={tool.icon}
                                                label={tool.label}
                                                size="sm"
                                            />
                                            {agentName(tool.label)}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    </div>

                    <WhatYouSee
                        name={name}
                        chat={guide.kind === 'chat'}
                        stage={stage}
                    />
                </div>
            </main>
        </SitePage>
    );
}
