import { Link } from '@inertiajs/react';
import React from 'react';

import { BlogDemo } from '@/components/blog-demo';
import { docs } from '@/routes';
import { show as blogShow } from '@/routes/blog';

// ── design tokens ──────────────────────────────────────────────────────────────

export const S = {
    base3: 'var(--sol-base3)',
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base0: 'var(--sol-base0)',
    base00: 'var(--sol-base00)',
    yellow: 'var(--sol-yellow)',
    orange: 'var(--sol-orange)',
    blue: 'var(--sol-blue)',
    cyan: 'var(--sol-cyan)',
    green: 'var(--sol-green)',
} as const;

export const MONO =
    'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
export const SANS = 'var(--font-sans)';
export const GITHUB = 'https://github.com/rubybear-lgtm/artfct';

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

export function CodeBlock({ code }: { code: string }) {
    return (
        <pre className="mb-6 overflow-x-auto rounded-[10px] border border-border bg-paper p-4 font-mono text-[13px] leading-relaxed whitespace-pre text-foreground">
            {code}
        </pre>
    );
}

// ── post content ───────────────────────────────────────────────────────────────

const CODE_DEVTOOLS_INSTALL = `npx skills add rubybear-lgtm/artfct@developer-tools`;

const CODE_DEVTOOLS_EXAMPLE = `# JSON table
"Show this query result as a table"
→ https://artfct.dev/p/xK2mNp7q  (sortable, filterable, click to copy)

# API diff
"What changed between the v1 and v2 response?"
→ https://artfct.dev/p/rT9wBc4j  (deep diff, grouped by path)

# ENV diff
"Which env vars differ between prod and staging?"
→ https://artfct.dev/p/hQ5vLm8n  (values redacted by default)

# Regex tester
"Does this pattern match ISO dates? Share the tester."
→ https://artfct.dev/p/jY3sDk6f  (live highlighting, group capture)`;

const CODE_DEVTOOLS_DEPLOY = `deploy_to_canvas({
  html: "...",   // tool HTML with your context pre-filled
  tier: "public" // permanent link, shareable with anyone
})`;

const CODE_SKILL_INSTALL = `npx skills add rubybear-lgtm/artfct@presentation`;

const CODE_DEPLOY_EXAMPLE = `# 1. Ask your agent
"Create a 10-slide presentation on async/await in JavaScript"

# 2. Agent builds the HTML deck, then calls:
deploy_to_canvas({
  html: "<!DOCTYPE html>...",
  tier: "public"
})

# 3. You get a link
→ https://artfct.dev/p/4fA8gX9z`;

const CODE_TEMPLATE_SNIPPET = `<section class="slide slide-content">
  <h2>Key Insight</h2>
  <ul>
    <li>Point one</li>
    <li>Point two</li>
    <li>Point three</li>
  </ul>
</section>`;
export const POSTS: Post[] = [
    {
        slug: 'share-ai-agent-knowledge-team',
        date: '2026-10-04',
        title: 'Share AI Agent Knowledge Across Your Team With artfct',
        tag: 'workflows',
        description:
            "Publish an agent's findings to your team's artfct library so other connected agents can find them and use them to guide their next task.",
        body: (
            <>
                <P>
                    An agent investigates a webhook failure and finds the trap:
                    a timed-out request may have reached the receiver. Sending
                    it again without deduplication can repeat the operation.
                </P>
                <P>
                    The finding is useful. The next agent needs it before
                    suggesting a retry loop.
                </P>
                <P>
                    If the investigation stays in the first conversation,
                    another teammate's agent starts without it. It may recommend
                    exactly the approach the investigation ruled out. The team
                    has learned something, but the learning hasn't reached the
                    next task.
                </P>
                <P>
                    artfct gives agents a way to publish that work to a shared
                    team library. Once indexed, the finding is available to
                    every supported agent connected to that team with read
                    permission. Another agent can use a relevant search result
                    to guide its proposal, even when a different teammate
                    generated it in a different tool.
                </P>
                <BlogDemo kind="team" />
                <H2>From an investigation to shared context</H2>
                <P>Here's an illustrative example of that handoff.</P>
                <P>
                    A developer asks their agent to investigate duplicate
                    billing webhooks. The investigation produces a report
                    explaining the failure sequence and recommending that the
                    receiver deduplicate using the provider's event ID before
                    applying a billing operation.
                </P>
                <P>
                    The developer reviews the finding and its evidence. Then
                    they ask the agent to keep it:
                </P>
                <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
                    Publish this reviewed investigation to our team's artfct
                    library as a secure artifact. Title it “Billing webhook
                    duplicates after timeouts.” Include the evidence, the
                    recommendation to deduplicate by provider event ID, and the
                    provider and service this applies to. Describe it so another
                    agent looking into webhook retries can find it.
                </blockquote>
                <P>
                    The agent uses <Mono>deploy_artifact</Mono> to upload the
                    report with a title and description. Secure is the default
                    tier, with access through the workspace: teammates with
                    workspace access can open the link.
                </P>
                <P>
                    The report now has a home beyond the conversation that
                    produced it. It could be a debugging investigation or a
                    deployment checklist. The team chooses what deserves to
                    enter its library.
                </P>
                <H2>The next agent starts with the finding</H2>
                <P>
                    A second developer is working on retry behavior in another
                    supported agent tool. Their agent is connected to artfct and
                    authenticated to the same team with the relevant
                    permissions.
                </P>
                <P>They ask:</P>
                <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
                    Search our artfct library for findings about billing
                    webhooks, timeouts, and duplicate delivery. Use any relevant
                    finding to guide your retry proposal. Include the source
                    link and flag assumptions that the source doesn't cover.
                </blockquote>
                <P>
                    Once background indexing succeeds, the published
                    investigation can appear in natural-language search. The
                    second agent doesn't need the first agent's conversation or
                    the exact report title to look for it.
                </P>
                <P>
                    Suppose the returned snippet includes the report's
                    recommendation: deduplicate using the provider's event ID
                    before applying the billing operation. The agent can use
                    that finding to make deduplication part of the retry
                    proposal. It can cite the artifact link and identify which
                    implementation details still need a decision.
                </P>
                <P>
                    A finding from one task now informs the proposed approach in
                    another. The source remains available for the developer or
                    reviewer to inspect.
                </P>
                <P>
                    Search returns short snippets and viewing links;{' '}
                    <Mono>get_artifact</Mono> returns metadata and a link. When
                    a decision depends on evidence or details beyond a snippet,
                    someone opens the report and supplies the relevant material.
                </P>
                <H2>The library travels across supported agent tools</H2>
                <P>
                    The shared library belongs to the authenticated workspace.
                    It works whichever agent tool each person prefers. Supported
                    clients connected through MCP can retrieve the same team
                    artifacts, subject to their access permissions.
                </P>
                <P>
                    The artifact's link is ready after successful publishing.
                    Other agents can find it through search once background
                    indexing succeeds. Your workspace needs indexing enabled and
                    its providers configured.
                </P>
                <P>
                    For findings that become approved team guidance, collections
                    help keep related work together. A team admin can pin a
                    collection as canonical, which boosts its members in search
                    ranking. Write an owner's name and a review date into the
                    report itself — perhaps in a short ownership section at the
                    top — and revisit its collection membership when the
                    guidance changes.
                </P>
                <H2>Make the next task benefit from this one</H2>
                <P>
                    Start with one investigation your team would otherwise
                    repeat. Ask the agent to turn its reviewed findings into an
                    artifact and publish it. On the next related task, ask a
                    connected agent to search the library and explain how the
                    result affects its proposal.
                </P>
                <P>
                    <A href={`${docs().url}#mcp`}>
                        Connect your agents to artfct through MCP
                    </A>
                    . For the tool-to-tool version of this workflow, see{' '}
                    <Link
                        href={blogShow.url({
                            slug: 'share-context-claude-code-codex-mcp',
                        })}
                        className="font-semibold text-primary underline-offset-4 hover:underline"
                    >
                        sharing context between Claude Code and Codex with MCP
                    </Link>{' '}
                    and{' '}
                    <Link
                        href={blogShow.url({
                            slug: 'semantic-search-ai-generated-reports',
                        })}
                        className="font-semibold text-primary underline-offset-4 hover:underline"
                    >
                        finding AI-generated reports when you forget the title
                    </Link>
                    .
                </P>
            </>
        ),
    },
    {
        slug: 'share-context-claude-code-codex-mcp',
        date: '2026-10-04',
        title: 'Share Context Between Claude Code and Codex With MCP',
        tag: 'workflows',
        description:
            "Save an investigation from Claude Code, find it in Codex, and carry verified findings forward with artfct's hosted MCP server.",
        body: (
            <>
                <P>
                    An architecture investigation can take longer than the
                    change it prepares you to make. The agent traces a request
                    through three services, checks the tests, and explains why
                    the obvious fix would break another caller. Then you switch
                    tools. The next session starts with a repository and none of
                    those findings.
                </P>
                <P>
                    You can paste the conversation into Codex. You also get the
                    abandoned theories, terminal output, and the point where
                    everyone briefly blamed the cache. A useful handoff takes
                    some editing.
                </P>
                <P>
                    Claude Code can publish the investigation to your team's
                    artfct library as a titled HTML artifact. Later, Codex can
                    search the same workspace for the problem it describes. The
                    result includes a snippet and a source link, giving the new
                    session a finding to check before it starts another
                    investigation.
                </P>
                <P>The following worked example is illustrative.</P>
                <BlogDemo kind="handoff" />
                <H2>Finish the investigation with something worth keeping</H2>
                <P>
                    Suppose you ask Claude Code to investigate duplicate webhook
                    deliveries. It examines the request handler, retry behavior,
                    and existing tests. The useful output is a short report:
                    what the code does, which evidence supports the conclusion,
                    what remains uncertain, and where a proposed fix needs
                    tests.
                </P>
                <P>
                    Before leaving the session, give it a concrete publishing
                    instruction:
                </P>
                <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
                    Turn this investigation into a self-contained HTML report
                    titled “Duplicate webhook delivery: retry and
                    acknowledgement findings.” Include the files and commit we
                    checked, confirmed findings, rejected explanations, and
                    unresolved questions. Keep hypotheses clearly marked.
                    Publish it to our artfct workspace with deploy_artifact,
                    using the secure tier and a description that mentions
                    webhook retries and acknowledgements.
                </blockquote>
                <P>
                    “Retries are configured here” and “this retry may explain
                    the duplicates” should survive the handoff as different
                    statements.
                </P>
                <P>
                    <Mono>deploy_artifact</Mono> saves a permanent workspace
                    artifact. Secure is the default tier, and publishing
                    identical content returns the same artifact. Give it a
                    useful title and description so the next search has
                    something concrete to work with.
                </P>
                <H2>Find it from the next tool</H2>
                <P>
                    Later, you open Codex to work on the fix. You remember the
                    problem, but perhaps not the report's title. Ask for it by
                    description:
                </P>
                <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
                    Before changing webhook handling, use artfct's
                    search_artifacts to find earlier investigations about
                    duplicate webhook deliveries, retries, and acknowledgements.
                    Show the relevant snippets and source links. Use any
                    findings to guide what you check in the current code.
                </blockquote>
                <P>
                    Once background indexing succeeds, artfct can search by
                    meaning. A collection or created-since filter helps narrow
                    the results when several projects have reports called
                    “Webhook investigation.” Put the project name in the title,
                    too.
                </P>
                <P>
                    Codex can use the returned snippet to choose its next check.
                    If the argument needs more detail than the snippet provides,
                    open the linked report and supply the relevant section:
                </P>
                <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
                    I reviewed the linked report. Here are its verified findings
                    and the files it checked: [paste relevant details]. Recheck
                    them against the current checkout before proposing a change.
                    The report's unresolved question is [paste question]. Start
                    there, then run the affected tests.
                </blockquote>
                <P>
                    That last check protects against a mundane problem:
                    yesterday's correct explanation can describe code that
                    changed this morning.
                </P>
                <H2>Connect both agents to the same workspace</H2>
                <P>
                    artfct is a hosted remote HTTP MCP server. Add it to Claude
                    Code and Codex, authenticate each client, and confirm that
                    both are connected to the intended workspace. There is no
                    local artfct CLI to install. The setup guide also covers
                    OpenCode and Antigravity; other clients have not been
                    verified.
                </P>
                <P>
                    Workspace permissions still apply. Connecting two tools does
                    not give either one access to another team's artifacts.
                </P>
                <P>
                    Publishing and searching are explicit actions. Search
                    returns snippets and links; <Mono>get_artifact</Mono>{' '}
                    returns metadata and a link rather than the full HTML. Keep
                    the report's key finding in readable text, and expect to
                    open it when the next task needs the full explanation.
                    Search requires indexing to be configured and the indexing
                    job to finish.
                </P>
                <P>
                    Before this workflow, the investigation sits in a
                    conversation you have to locate and condense. After it,
                    there is a named report that another supported tool can help
                    you find. You still have to judge the findings, but you know
                    where they are.
                </P>
                <P>
                    <A href={`${docs().url}#mcp`}>
                        Connect Claude Code and Codex to artfct
                    </A>
                    , then publish one investigation you would otherwise have to
                    explain twice. To see what search can and cannot find, read{' '}
                    <Link
                        href={blogShow.url({
                            slug: 'semantic-search-ai-generated-reports',
                        })}
                        className="font-semibold text-primary underline-offset-4 hover:underline"
                    >
                        finding AI-generated reports when you forget the title
                    </Link>
                    , or start with{' '}
                    <Link
                        href={blogShow.url({
                            slug: 'share-ai-agent-knowledge-team',
                        })}
                        className="font-semibold text-primary underline-offset-4 hover:underline"
                    >
                        sharing AI agent knowledge across your team
                    </Link>
                    .
                </P>
            </>
        ),
    },
    {
        slug: 'semantic-search-ai-generated-reports',
        date: '2026-10-04',
        title: 'Find AI-generated reports when you forget the title',
        tag: 'workflows',
        description:
            'Use semantic search to find published AI reports by the problem they describe, with snippets, provenance and links to inspect the source.',
        body: (
            <>
                <P>
                    You remember the finding. You don't remember what the agent
                    called the report.
                </P>
                <P>
                    Something about webhook retries. A billing integration.
                    Duplicate charges under a particular failure condition.
                    Searching for “webhooks” in a folder of exports might work,
                    provided the report is there and uses that word. Searching
                    the chat history means remembering which tool you used to
                    produce it.
                </P>
                <P>
                    Semantic search lets you look for a saved explanation by
                    describing its subject, even when your wording differs from
                    the title.
                </P>
                <P>
                    artfct gives agents a place to publish HTML reports and,
                    when indexing is configured, a way to search those artifacts
                    through MCP. The search starts from the question you
                    remember.
                </P>
                <BlogDemo kind="search" />
                <H2>A worked example: the billing report</H2>
                <P>
                    Here is an illustrative example. An agent reviewed a billing
                    integration and published an HTML report titled{' '}
                    <strong>Billing integration review</strong>. Inside it, a
                    section explains how repeated webhook deliveries could
                    trigger duplicate charges when an idempotency check is
                    missing.
                </P>
                <P>A week later, a teammate asks their connected agent:</P>
                <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
                    Search our artfct workspace for the report about why retries
                    created duplicate charges. Look in the billing collection
                    and show me the relevant snippet and link.
                </blockquote>
                <P>
                    The phrase “why retries created duplicate charges” is more
                    useful than a guessed filename. It describes the
                    relationship the reader remembers.
                </P>
                <P>
                    Semantic search can surface a report about that relationship
                    even if its title says nothing about retries. Whether it
                    appears depends on the indexed text, the query and the other
                    artifacts in the workspace.
                </P>
                <P>
                    The returned title and snippet help the teammate decide
                    whether to open it. A collection filter can help distinguish
                    the integration review from an unrelated report with similar
                    language. Where provenance is available, the result also
                    gives you that context.
                </P>
                <H2>What indexing actually does</H2>
                <P>
                    Publishing creates an artifact people can open. Indexing
                    prepares its contents for search. Those are separate steps.
                </P>
                <P>
                    artfct's indexing process extracts visible text from the
                    HTML. When its heuristics indicate that rendering is needed,
                    it can use a browser to render the page before extraction.
                    The text is stored, split into chunks and converted into
                    embeddings: numerical representations that let search
                    compare related passages and queries.
                </P>
                <P>
                    The difference matters for HTML. A report containing normal
                    headings and paragraphs gives the index something to read. A
                    chart drawn entirely on a canvas, or an image containing all
                    the findings, may not. Rendering has limits too. Put the
                    conclusions in readable text if you expect someone to find
                    them later.
                </P>
                <P>
                    Indexing runs as queued work with retries. A published link
                    can be available before its searchable text is ready. Search
                    also requires indexing and its providers to be configured;
                    publishing alone does not establish that setup.
                </P>
                <H2>Meaning helps; exact words still matter</H2>
                <P>
                    artfct combines vector search candidates with full-text
                    candidates, then reranks the results. It can therefore use
                    related meaning alongside actual words in the report.
                </P>
                <P>
                    This is useful when one person says “repeated deliveries”
                    and another remembers “retries.” Exact words remain useful
                    for a distinctive error message, endpoint name or system
                    identifier. Include those in the query when you have them.
                </P>
                <P>
                    Ranking also considers signals such as recency, repository,
                    usage and whether an artifact is marked canonical. You can
                    narrow the search with repository, agent, created-since or
                    collection filters — <Mono>since</Mono> is a lower bound on
                    when artifacts were created, so use it to skip anything
                    older than the change you care about. Collection filters are
                    useful once the workspace contains several reviews of the
                    same system. Repository and agent filters depend on those
                    fields being present; a hosted MCP upload does not
                    automatically capture them.
                </P>
                <P>
                    Search is scoped to the authenticated team and excludes
                    revoked artifacts. It does not automatically crawl every
                    repository or chat transcript. The material available to
                    search is the material published to the workspace and
                    successfully indexed.
                </P>
                <H2>Give the next search a fair chance</H2>
                <P>The publishing prompt is part of the workflow. Try:</P>
                <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
                    Publish this review to artfct with a descriptive title and a
                    one-sentence description. Add it to the billing collection.
                    Keep the findings and recommendations as readable HTML text.
                </blockquote>
                <P>Then, before commissioning another review:</P>
                <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
                    Search artfct for existing reports about webhook retries and
                    duplicate charges in the billing collection. Return the
                    closest matches with snippets and links so I can inspect
                    them.
                </blockquote>
                <P>
                    The agent can use a relevant snippet to guide its next check
                    and cite the source link. Search returns only a short
                    excerpt, so open the report when you need the full argument
                    or its assumptions.
                </P>
                <P>
                    Connect a supported agent using the{' '}
                    <A href={`${docs().url}#mcp`}>MCP setup guide</A>. Once your
                    workspace has indexing enabled, try searching for a report
                    by the problem it solved, without looking up the title
                    first. For a handoff between tools, see{' '}
                    <Link
                        href={blogShow.url({
                            slug: 'share-context-claude-code-codex-mcp',
                        })}
                        className="font-semibold text-primary underline-offset-4 hover:underline"
                    >
                        sharing context between Claude Code and Codex with MCP
                    </Link>
                    . For shared team guidance, see{' '}
                    <Link
                        href={blogShow.url({
                            slug: 'share-ai-agent-knowledge-team',
                        })}
                        className="font-semibold text-primary underline-offset-4 hover:underline"
                    >
                        sharing AI agent knowledge across your team
                    </Link>
                    .
                </P>
            </>
        ),
    },
    {
        slug: 'developer-tools',
        date: '2026-06-04',
        title: 'Four developer tools, one skill install',
        tag: 'skills',
        image: '/images/blog/developer-tools.png',
        description:
            'A walkthrough of the artfct developer-tools skill and the four utilities it deploys.',
        body: (
            <>
                <P>
                    Most developer tools require an account, a browser
                    extension, or a tab you'll forget to close. The artfct{' '}
                    <Mono>developer-tools</Mono> skill takes a different
                    approach: your agent builds the tool, deploys it, and hands
                    you a link. Open it, use it, share it if you want. No setup
                    on the other end.
                </P>

                <H3>Install</H3>
                <CodeBlock code={CODE_DEVTOOLS_INSTALL} />
                <P>
                    Once installed, agents automatically reach for the right
                    tool when you ask:
                </P>
                <CodeBlock code={CODE_DEVTOOLS_EXAMPLE} />

                <H3>What's in the skill</H3>
                <P>
                    Four tools, each a self-contained HTML file with Solarized
                    styling and zero external dependencies.
                </P>
                <P>
                    <Mono>json-table</Mono> — paste a JSON array or CSV and get
                    a sortable, filterable table. Click any cell to copy its
                    value. Useful for sharing query results or API responses
                    without reaching for a spreadsheet.
                </P>
                <P>
                    <Mono>api-diff</Mono> — paste two JSON objects and see
                    exactly what changed: added keys in green, removed in red,
                    modified values in yellow, grouped by path. A swap button
                    reverses the comparison.
                </P>
                <P>
                    <Mono>env-diff</Mono> — paste two <Mono>.env</Mono> files
                    and get a table of added, removed, and changed keys. Values
                    are redacted by default — safe to share. Toggle to reveal
                    when you need to see the actual values.
                </P>
                <P>
                    <Mono>regex-tester</Mono> — a live regex playground with
                    match highlighting, capture group display, and flag toggles.
                    Pre-fill the pattern and test string so the tool opens ready
                    to use.
                </P>

                <H3>How agents use it</H3>
                <P>
                    The skill ships with an HTML template for each tool. Agents
                    read the template, substitute context-specific titles and
                    labels, optionally pre-populate data if you've already
                    provided it, then deploy:
                </P>
                <CodeBlock code={CODE_DEVTOOLS_DEPLOY} />
                <P>
                    The tools are intentionally unstyled beyond Solarized — no
                    branding, no chrome, nothing between you and the data. If
                    you need a different theme, the{' '}
                    <Mono>customization.md</Mono> reference in the skill covers
                    switching to Solarized Dark, adding frozen columns, named
                    regex groups, and more.
                </P>

                <H3>Tiers</H3>
                <P>
                    Most of these tools make sense as <Mono>public</Mono> links
                    — permanent, shareable with teammates who don't have artfct
                    installed. Use <Mono>ephemeral</Mono> when you're iterating
                    on a regex pattern or checking a diff you don't need to
                    keep.
                </P>
            </>
        ),
    },
    {
        slug: 'ai-presentations',
        date: '2026-06-04',
        title: 'AI-generated slide decks, deployed in one step',
        tag: 'skills',
        image: '/images/blog/ai-presentations.png',
        description:
            'How the artfct presentation skill turns a prompt into a shareable HTML deck.',
        body: (
            <>
                <P>
                    The fastest way to share a presentation is a URL. No
                    exports, no file attachments, no "let me send you the
                    Keynote." Just a link that opens in any browser,
                    fullscreen-ready, with keyboard navigation built in.
                </P>
                <P>
                    The new artfct <Mono>presentation</Mono> skill teaches
                    agents exactly how to do this. Install it once, and your
                    agent will automatically build and deploy an HTML slide deck
                    whenever you ask for a presentation.
                </P>

                <H3>Install the skill</H3>
                <CodeBlock code={CODE_SKILL_INSTALL} />
                <P>
                    That's it. The skill is sourced from the{' '}
                    <A href={GITHUB}>artfct repo</A> and follows the{' '}
                    <A href="https://skills.sh">skills.sh</A> format —
                    compatible with Claude Code, OpenAI Codex, OpenCode, and
                    other agents that support the skills ecosystem.
                </P>

                <H3>What happens when you ask for a presentation</H3>
                <CodeBlock code={CODE_DEPLOY_EXAMPLE} />
                <P>
                    The agent reads the built-in HTML template, fills in your
                    content, then calls <Mono>deploy_to_canvas</Mono> via the
                    artfct MCP server. You get a permanent public URL in
                    seconds. No file to download, no app to open.
                </P>

                <H3>The template</H3>
                <P>
                    The presentation template ships with the skill as a bundled
                    asset. It's a single self-contained HTML file — Solarized
                    palette, keyboard navigation (arrow keys, spacebar, swipe),
                    slide counter, accent bar. No external dependencies.
                </P>
                <CodeBlock code={CODE_TEMPLATE_SNIPPET} />
                <P>
                    Each slide is a <Mono>{'<section class="slide">'}</Mono>{' '}
                    element. The agent adds or removes sections to match the
                    outline, replaces the placeholder tokens, and the JS counter
                    updates automatically.
                </P>

                <H3>Tiers</H3>
                <P>
                    Finished deck? Deploy as <Mono>public</Mono> — permanent,
                    shareable with anyone. Iterating? <Mono>ephemeral</Mono>{' '}
                    with a 1-year TTL keeps drafts from accumulating. Sensitive
                    content? <Mono>secure</Mono> keeps the preview encrypted and
                    blurred by default.
                </P>

                <H3>When the skill steps aside</H3>
                <P>
                    Speaker notes, animated fragments, and PDF export all
                    require Reveal.js. The skill knows this and falls back to a
                    Reveal.js setup automatically when those features are
                    requested. For everything else — talks, briefings, technical
                    walkthroughs, pitch decks — the built-in template is faster
                    and lighter.
                </P>
            </>
        ),
    },
    {
        slug: 'mermaid-diagrams',
        date: '2026-06-06',
        title: 'Share Mermaid diagrams as live links — no screenshots needed',
        tag: 'skills',
        image: '/images/blog/mermaid-diagrams.png',
        description:
            'Why the artfct Mermaid skill exists and how it helps people share diagrams faster.',
        body: (
            <>
                <P>
                    Mermaid is the best thing to happen to technical
                    documentation since Markdown. Write a flowchart in plain
                    text, get a diagram. It works in GitHub READMEs, Notion
                    blocks, and documentation generators. It's version-control
                    friendly. It doesn't require a design tool.
                </P>

                <P>
                    But there's a gap:{' '}
                    <strong>
                        sharing Mermaid diagrams outside those environments is a
                        pain
                    </strong>
                    .
                </P>

                <P>
                    Want to show an architecture diagram in a Discord thread?
                    You take a screenshot. Sending a sequence diagram to a
                    teammate on Slack? Screenshot. Including a flowchart in a
                    bug report on Linear? Screenshot. Screenshots are dead
                    content — they don't render at different sizes, they don't
                    respond to dark mode, they can't be zoomed, and they're
                    useless for accessibility.
                </P>

                <P>
                    This is exactly the kind of problem artfct was built to
                    solve.
                </P>

                <H3>The artfct approach</H3>

                <P>
                    The artfct <Mono>developer-tools</Mono> skill includes a
                    Mermaid renderer tool. When an agent detects Mermaid source
                    — whether you wrote it, pasted it, or the agent generated it
                    from a description — the tool renders it to an interactive
                    HTML page and deploys it as a shareable link. No accounts,
                    no setup, no screenshots.
                </P>

                <CodeBlock
                    code={`# Generate a diagram
"What does the request lifecycle look like?"

# Agent builds this Mermaid source, renders it, and deploys:
→ https://artfct.dev/p/{shareable-artifact-id}`}
                />

                <P>
                    The result is a live sequence diagram. Theme-aware
                    (light/dark), zoomable, rendered from Mermaid. The person on
                    the other end doesn't need Mermaid installed, doesn't need a
                    plugin. They open the link and see the diagram.
                </P>

                <H3>Diagrams that adapt</H3>

                <P>
                    Because the rendered output is a real HTML page (not an
                    image), the diagram inherits all the benefits of the web:
                </P>

                <P>
                    <strong>Theme-aware.</strong> The page detects
                    <Mono>prefers-color-scheme</Mono> and swaps between
                    Solarized Light and Solarized Dark automatically. Dark mode
                    users see dark diagrams, light mode users see light ones —
                    from the same URL.
                </P>

                <P>
                    <strong>Zoomable.</strong> Click or pinch to zoom into any
                    part of the diagram. Complex architecture diagrams with
                    dozens of nodes become readable without squinting or
                    exporting at 4x resolution.
                </P>

                <P>
                    <strong>Exportable.</strong> Right-click to save as SVG. The
                    diagram is <em>alive</em> — not trapped in a screenshot.
                </P>

                <P>
                    <strong>Zero dependencies.</strong> The output is a single
                    HTML file. It loads in any browser, on any device.
                </P>

                <H3>How agents use it</H3>

                <P>
                    The Mermaid renderer is one of four tools in the
                    <Mono>developer-tools</Mono> skill. When a user asks for a
                    diagram, the agent:
                </P>

                <P>
                    1. Generates Mermaid source from the description (or uses
                    what the user pasted)
                    <br />
                    2. Loads the renderer HTML template from the skill
                    <br />
                    3. Injects the Mermaid source into the template body
                    <br />
                    4. Calls <Mono>deploy_to_canvas</Mono> via the artfct MCP
                    server
                    <br />
                    5. Returns the URL
                </P>

                <CodeBlock
                    code={`# Install once
npx skills add rubybear-lgtm/artfct@developer-tools

# Use anywhere
"Show me the CI/CD pipeline as a diagram"
→ live diagram link

"Render this Mermaid for the bug report"
flowchart LR
  A[Start] --> B{Valid?}
  B -->|Yes| C[Process]
  B -->|No| D[Reject]
  C --> E[End]
  D --> E
→ live diagram link`}
                />

                <H3>Why this matters</H3>

                <P>
                    Technical communication is increasingly async and
                    link-driven. Code reviews happen in GitHub, discussions in
                    Discord, documentation in Notion, bugs in Linear. Each
                    platform has its own rendering limitations. The common
                    denominator is a URL.
                </P>

                <P>
                    By making diagrams linkable — truly linkable, not
                    "screenshot posted in a thread" — artfct closes a gap that's
                    been annoying developers for years. It's a small thing that
                    makes a big difference in daily workflow.
                </P>

                <P>
                    <strong>
                        The best diagram tool is the one that gets out of your
                        way.
                    </strong>{' '}
                    Write your Mermaid, get your link. That's the whole thing.
                </P>
            </>
        ),
    },
];

export function getPostBySlug(slug: string): Post | undefined {
    return POSTS.find((post) => post.slug === slug);
}
