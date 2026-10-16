import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { SitePage } from '@/components/site-chrome';
import { docs } from '@/routes';
import connectRoutes from '@/routes/connect';

interface GuideStep {
    text: string;
    code?: string;
}

interface Guide {
    id: string;
    label: string;
    verified: boolean;
    intro?: string;
    steps: GuideStep[];
}

interface ConnectShowProps {
    meta: { title: string; description: string };
    guide: Guide;
    mcpUrl: string;
    quickInstall: string;
    related: Array<{ id: string; label: string; verified: boolean }>;
    troubleshooting: Array<{ name: string; type: string; note: string }>;
}

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
                className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em]"
            >
                {part}
            </code>
        ) : (
            <span key={index}>{part}</span>
        ),
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="mt-12">
            <h2 className="mb-4 font-serif text-2xl leading-tight font-medium tracking-tight">
                {title}
            </h2>
            {children}
        </section>
    );
}

export default function ConnectShow({
    meta,
    guide,
    mcpUrl,
    quickInstall,
    related,
    troubleshooting,
}: ConnectShowProps) {
    const answer = guide.verified
        ? guide.label +
          ' connects to Artfct through a hosted MCP server, and this path is verified end to end.'
        : guide.label +
          ' connects to Artfct through a hosted MCP server. This path is documented, but it has not been verified end to end.';

    return (
        <SitePage active="docs">
            <Head title={meta.title}>
                <meta
                    head-key="description"
                    name="description"
                    content={meta.description}
                />
            </Head>

            <main className="mx-auto max-w-[720px] px-5 py-16">
                <p className="mb-3 text-xs font-bold tracking-[0.08em] text-primary uppercase">
                    Connect a tool
                </p>
                <h1 className="max-w-[24ch]">
                    Connect {guide.label} to Artfct
                </h1>
                <p className="mt-5 max-w-[58ch] text-lg leading-relaxed text-muted-foreground">
                    {answer}
                </p>

                {guide.intro && (
                    <p className="mt-5 max-w-[58ch] leading-[1.75] text-muted-foreground">
                        {renderStepText(guide.intro)}
                    </p>
                )}

                <Section title="What you need">
                    <p className="leading-[1.75] text-muted-foreground">
                        A {guide.label} that can reach a remote server, and the
                        address below. Artfct runs only as a hosted server, so a
                        tool that can only start programs on your own computer
                        cannot connect.
                    </p>
                    <pre className="mt-4 overflow-x-auto rounded bg-muted p-3 font-mono text-sm">
                        {mcpUrl}
                    </pre>
                    <p className="mt-4 leading-[1.75] text-muted-foreground">
                        One command adds Artfct to every supported tool it finds
                        on the machine:
                    </p>
                    <pre className="mt-4 overflow-x-auto rounded bg-muted p-3 font-mono text-sm">
                        {quickInstall}
                    </pre>
                </Section>

                <Section title="Steps">
                    <ol className="list-decimal space-y-5 pl-6 leading-[1.75] text-muted-foreground">
                        {guide.steps.map((step, index) => (
                            <li key={index}>
                                {renderStepText(step.text)}
                                {step.code && (
                                    <pre className="mt-3 overflow-x-auto rounded bg-muted p-3 font-mono text-sm text-foreground">
                                        {step.code}
                                    </pre>
                                )}
                            </li>
                        ))}
                    </ol>
                </Section>

                <Section title="If it does not work">
                    <ul className="space-y-4 leading-[1.75] text-muted-foreground">
                        {troubleshooting.map((item) => (
                            <li key={item.name}>
                                <strong className="text-foreground">
                                    {item.name}.
                                </strong>{' '}
                                {item.note}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-5 leading-[1.75] text-muted-foreground">
                        The full list, and every tool Artfct supports, is on the{' '}
                        <Link
                            href={docs.url()}
                            className="font-semibold text-primary underline-offset-4 hover:underline"
                        >
                            documentation page
                        </Link>
                        .
                    </p>
                </Section>

                <Section title="Other tools">
                    <ul className="flex flex-wrap gap-3">
                        {related.map((tool) => (
                            <li key={tool.id}>
                                <Link
                                    href={connectRoutes.show.url({
                                        tool: tool.id,
                                    })}
                                    className="inline-flex items-center gap-2 rounded-[5px] border border-border px-3 py-1.5 text-sm font-semibold text-foreground transition-colors hover:border-primary hover:text-primary"
                                >
                                    {tool.label}
                                    {tool.verified && (
                                        <span className="text-xs font-normal text-muted-foreground">
                                            verified
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Section>
            </main>
        </SitePage>
    );
}
