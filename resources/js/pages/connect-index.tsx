import { Head, Link } from '@inertiajs/react';

import { CodeBlock } from '@/components/code-block';
import { AgentTile } from '@/components/connect/agent-tile';
import { CopyPrompt } from '@/components/connect/copy-prompt';
import { AGENT_GROUPS, agentName } from '@/components/connect/types';
import type { AgentCard } from '@/components/connect/types';
import { SitePage } from '@/components/site-chrome';
import connectRoutes from '@/routes/connect';

interface ConnectIndexProps {
    meta: { title: string; description: string };
    guides: AgentCard[];
    prompt: string;
    quickInstall: string;
}

/**
 * The agent setup hub (RUB-502): one prompt for any agent first, then every
 * guide grouped by where the agent runs. Groups are headings rather than
 * filter chips, so the page reads in one pass and each group is a real
 * section for search engines.
 */
export default function ConnectIndex({
    meta,
    guides,
    prompt,
    quickInstall,
}: ConnectIndexProps) {
    const groups = AGENT_GROUPS.map((group) => ({
        ...group,
        guides: guides.filter((guide) => guide.kind === group.kind),
    })).filter((group) => group.guides.length > 0);

    return (
        <SitePage active="connect">
            <Head title={meta.title}>
                <meta
                    head-key="description"
                    name="description"
                    content={meta.description}
                />
            </Head>

            <main className="mx-auto max-w-[1120px] px-5 pb-20">
                <header className="grid justify-items-center gap-3.5 pt-[72px] pb-10 text-center">
                    <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                        Agent setup
                    </p>
                    <h1 className="font-serif text-[clamp(42px,6.4vw,72px)] leading-none font-normal tracking-[-0.02em] text-balance">
                        Every agent, one workspace
                    </h1>
                    <p className="max-w-[34em] text-lg text-balance text-muted-foreground">
                        Connect each AI tool your team uses to the same Artfct
                        workspace. Then what one agent publishes, the others can
                        find.
                    </p>
                    <div className="mt-5 w-full sm:w-auto">
                        <CopyPrompt prompt={prompt} />
                    </div>
                </header>

                <nav
                    aria-label="Jump to"
                    className="flex flex-wrap justify-center gap-x-6 gap-y-1.5 border-y border-border py-3.5 text-[14.5px]"
                >
                    {groups.map((group) => (
                        <a
                            key={group.kind}
                            href={`#${group.kind}`}
                            className="text-muted-foreground hover:text-foreground"
                        >
                            {group.title}
                        </a>
                    ))}
                </nav>

                {groups.map((group) => (
                    <section
                        key={group.kind}
                        id={group.kind}
                        aria-labelledby={`${group.kind}-title`}
                        className="grid scroll-mt-24 gap-4 pt-14 lg:grid-cols-[240px_minmax(0,1fr)] lg:gap-8"
                    >
                        <div>
                            <h2
                                id={`${group.kind}-title`}
                                className="font-serif text-[28px] leading-tight font-normal"
                            >
                                {group.title}
                            </h2>
                            <p className="mt-2 text-[14.5px] text-muted-foreground">
                                {group.note}
                            </p>
                        </div>
                        <ul className="grid border-t border-border sm:grid-cols-2">
                            {group.guides.map((guide) => (
                                <li
                                    key={guide.id}
                                    className="border-b border-border sm:odd:pr-7 sm:even:border-l sm:even:pl-7"
                                >
                                    <Link
                                        href={connectRoutes.show(guide.id).url}
                                        className="group grid grid-cols-[48px_minmax(0,1fr)_auto] items-start gap-x-4 gap-y-1 py-5"
                                    >
                                        <AgentTile
                                            icon={guide.icon}
                                            label={guide.label}
                                            className="row-span-2"
                                        />
                                        <span className="font-serif text-[21px] leading-tight transition-colors group-hover:text-primary">
                                            {agentName(guide.label)}
                                            {guide.maker && (
                                                <small className="ml-2 align-[2px] font-sans text-[11.5px] font-medium tracking-[0.1em] text-muted-foreground uppercase">
                                                    {guide.maker}
                                                </small>
                                            )}
                                        </span>
                                        <span
                                            aria-hidden="true"
                                            className="text-muted-foreground transition-transform group-hover:translate-x-1 group-hover:text-primary"
                                        >
                                            →
                                        </span>
                                        <span className="col-start-2 text-[15px] text-muted-foreground">
                                            {guide.summary}
                                        </span>
                                        <span className="col-start-2 mt-2 text-[13px] font-semibold text-primary">
                                            {guide.method}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}

                <section className="mt-16 grid items-center gap-7 rounded-[14px] border border-border bg-paper p-7 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
                    <div>
                        <h2 className="font-serif text-[26px] leading-tight font-normal">
                            Set up several agents at once
                        </h2>
                        <p className="mt-1.5 text-muted-foreground">
                            Finds the agents on your computer and adds Artfct to
                            each one you pick. Each still asks you to sign in
                            the first time.
                        </p>
                    </div>
                    <CodeBlock code={quickInstall} />
                </section>
            </main>
        </SitePage>
    );
}
