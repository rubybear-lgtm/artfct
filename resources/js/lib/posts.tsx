import React from 'react';

import { BreakFigure, LoopFigure } from '@/components/contradicting-figures';
import { docs } from '@/routes';

// ── post type ──────────────────────────────────────────────────────────────────

export interface Post {
    slug: string;
    date: string;
    title: string;
    tag: string;
    description: string;
    /** Header image for the article, relative to /public. */
    image?: string;
    body: React.ReactNode;
}

// ── subcomponents ──────────────────────────────────────────────────────────────

export function P({ children }: { children: React.ReactNode }) {
    return (
        <p className="mb-5 leading-[1.75] text-muted-foreground">{children}</p>
    );
}

export function H2({ children }: { children: React.ReactNode }) {
    return (
        <h2 className="mt-12 mb-4 font-serif text-2xl leading-tight font-medium tracking-tight text-foreground">
            {children}
        </h2>
    );
}

export function H3({ children }: { children: React.ReactNode }) {
    return (
        <h3 className="mt-12 mb-4 font-serif text-2xl leading-tight font-medium tracking-tight text-foreground">
            {children}
        </h3>
    );
}

export function Mono({ children }: { children: React.ReactNode }) {
    return (
        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] text-foreground">
            {children}
        </code>
    );
}

export function A({
    href,
    children,
}: {
    href: string;
    children: React.ReactNode;
}) {
    return (
        <a
            href={href}
            target={href.startsWith('http') ? '_blank' : undefined}
            rel={href.startsWith('http') ? 'noreferrer' : undefined}
            className="font-semibold text-primary underline-offset-4 hover:underline"
        >
            {children}
        </a>
    );
}

export function Quote({ children }: { children: React.ReactNode }) {
    return (
        <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
            {children}
        </blockquote>
    );
}

export function FaqItem({
    question,
    children,
}: {
    question: string;
    children: React.ReactNode;
}) {
    return (
        <>
            <h3 className="mt-8 mb-2 font-serif text-lg leading-snug font-medium text-foreground">
                {question}
            </h3>
            <P>{children}</P>
        </>
    );
}

// ── post content ───────────────────────────────────────────────────────────────

export const POSTS: Post[] = [
    {
        slug: 'stop-ai-tools-contradicting-each-other',
        date: '2026-10-05',
        title: 'Stopping Claude and Cursor from contradicting each other',
        tag: 'workflows',
        description:
            'You drop a phrase in Claude. Cursor brings it back hours later. Here is how a shared library keeps AI tools from repeating decisions you already killed.',
        body: (
            <>
                <P>
                    You use Claude for research and Cursor for code. You spend a
                    morning killing the phrase &ldquo;shared AI brain&rdquo;
                    because customers keep calling it unverifiable hype. You
                    agree on plainer positioning: searchable reports, verified
                    sources, workspace access rules. You move on.
                </P>
                <P>That afternoon someone asks Cursor:</P>
                <Quote>
                    Build a three-column comparison of artfct against a static
                    wiki and a folder of markdown files. Lead with team
                    collaboration.
                </Quote>
                <P>
                    Cursor writes clean TypeScript, matching styles, and a hero
                    badge: &ldquo;The shared AI brain for modern teams.&rdquo;
                </P>
                <P>The team is aligned. The tools are not.</P>
                <BreakFigure />
                <H2>Why Claude and Cursor contradict each other</H2>
                <P>
                    Every AI tool works from what it can see right now. The
                    active conversation, recently opened files, maybe a local
                    rules file. Cursor cannot query a closed Claude Desktop
                    thread. Claude cannot inspect your IDE state. Close the tab
                    and the decision is gone. Start a new agent run and it
                    starts from zero.
                </P>
                <P>
                    So positioning gets refined at 10am in one tool and shipped
                    at 2pm in another, and the second tool has no idea the first
                    one changed anything.
                </P>
                <H2>Why .cursorrules and CLAUDE.md don&apos;t scale</H2>
                <P>
                    The standard fix is stuffing guidelines into a rules file.
                    It breaks down quickly:
                </P>
                <ul className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">
                    <li>
                        Every request pays the token cost even when it does not
                        need messaging rules.
                    </li>
                    <li>
                        Nobody files a PR to update it after a quick research
                        call, so it goes stale.
                    </li>
                    <li>
                        It only helps people inside the repo. Your PM drafting
                        in Claude Desktop never sees it.
                    </li>
                </ul>
                <H2>How we share context between Claude and Cursor</H2>
                <P>
                    We connect both tools to the same artfct team library over
                    MCP, the Model Context Protocol, an open standard that lets
                    AI tools reach external data instead of relying only on
                    whatever sits in the prompt. Both tools can search the same
                    library on demand.
                </P>
                <P>
                    <strong className="text-foreground">
                        Publish the decision where it gets made.
                    </strong>{' '}
                    When a positioning session wraps up, we tell Claude: share
                    this brief to our artfct library. Title it clearly. Include
                    approved phrases, banned terms, interview quotes. File it in
                    the Messaging collection.
                </P>
                <P>
                    <strong className="text-foreground">
                        One lookup rule replaces the rules file.
                    </strong>{' '}
                    Our <Mono>.cursorrules</Mono> is now a single line:
                </P>
                <Quote>
                    Before writing public copy, UI components, or documentation,
                    search the artfct library for recent guidance in the
                    Messaging collection. Cite the source link.
                </Quote>
                <P>
                    <strong className="text-foreground">
                        Cursor searches before it writes.
                    </strong>{' '}
                    When it scaffolds the comparison section, it calls{' '}
                    <Mono>search_artifacts</Mono> for &ldquo;feature comparison
                    messaging.&rdquo; It gets back the approved wording, the
                    banned-phrase warning, and a link to the original brief. It
                    uses the approved copy and drops the source link in the file
                    header.
                </P>
                <P>
                    The next time someone asked Cursor to draft comparison copy,
                    it pulled the brief, used the approved language, and cited
                    the link. The phrase did not come back.
                </P>
                <P>
                    Reviewers click once and see exactly where the copy came
                    from.
                </P>
                <LoopFigure />
                <H2>What this does not fix</H2>
                <P>
                    Someone still has to publish the decision. The library only
                    helps after the brief is in it.
                </P>
                <P>
                    Search returns targeted excerpts and links, not entire
                    documents. For edge cases you still open the source.
                </P>
                <P>
                    This covers Claude and Cursor today. The same setup works
                    for other MCP-compatible tools, but we have not tested every
                    combination yet.
                </P>
                <P>
                    <A href={`${docs().url}#mcp`}>
                        Connect your AI tools to artfct via MCP
                    </A>
                </P>
                <H2>Frequently asked questions</H2>
                <FaqItem question="Why do Claude and Cursor contradict each other?">
                    Each tool operates in its own context window. A decision
                    made in Claude Desktop is invisible to Cursor unless it
                    lives somewhere both can search.
                </FaqItem>
                <FaqItem question="Can Claude and Cursor share the same context?">
                    Yes. Connect both to a shared library over MCP. Claude
                    publishes decisions, Cursor searches them before generating
                    code.
                </FaqItem>
                <FaqItem question="Why not just use .cursorrules or CLAUDE.md?">
                    Static rules files burn tokens on every request, go stale
                    fast, and are invisible to anyone outside the repository.
                </FaqItem>
                <FaqItem question="How does MCP share context between tools?">
                    MCP is an open protocol that lets AI tools call external
                    functions like <Mono>search_artifacts</Mono>. Instead of
                    cramming everything into the system prompt, each tool pulls
                    only the context it needs, when it needs it.
                </FaqItem>
            </>
        ),
    },
];

export function getPostBySlug(slug: string): Post | undefined {
    return POSTS.find((post) => post.slug === slug);
}
