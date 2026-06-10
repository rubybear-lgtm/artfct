import { Head, Link } from '@inertiajs/react';
import { ThemeToggle } from '@/lib/theme';

const S = {
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

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
const SANS = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";
const GITHUB = 'https://github.com/rubybear-lgtm/artfct';

// ── post content ──────────────────────────────────────────────────────────────

interface Post {
    slug: string;
    date: string;
    title: string;
    tag: string;
    body: React.ReactNode;
}

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

const POSTS: Post[] = [
    {
        slug: 'developer-tools',
        date: '2026-06-04',
        title: 'Four developer tools, one skill install',
        tag: 'skills',
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
→ https://artfct.dev/p/790DiZFA457XQur1w0H27u`}
                />

                <P>
                    <A href="https://artfct.dev/p/790DiZFA457XQur1w0H27u">
                        Click that link
                    </A>{' '}
                    — it's a live sequence diagram. Theme-aware (light/dark),
                    zoomable, rendered from Mermaid. The person on the other end
                    doesn't need Mermaid installed, doesn't need a plugin. They
                    click and see the diagram.
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

// ── subcomponents ─────────────────────────────────────────────────────────────

function P({ children }: { children: React.ReactNode }) {
    return (
        <p
            style={{
                fontFamily: SANS,
                fontSize: '15px',
                lineHeight: 1.75,
                color: S.base0,
                margin: '0 0 1.1rem',
            }}
        >
            {children}
        </p>
    );
}

function H3({ children }: { children: React.ReactNode }) {
    return (
        <h3
            style={{
                fontFamily: MONO,
                fontSize: '12px',
                fontWeight: 400,
                color: S.base00,
                letterSpacing: '0.06em',
                textTransform: 'uppercase',
                margin: '2rem 0 0.75rem',
            }}
        >
            {children}
        </h3>
    );
}

function Mono({ children }: { children: React.ReactNode }) {
    return (
        <code
            style={{
                fontFamily: MONO,
                fontSize: '13px',
                color: S.base00,
                backgroundColor: S.base2,
                padding: '0.1em 0.4em',
            }}
        >
            {children}
        </code>
    );
}

function A({ href, children }: { href: string; children: React.ReactNode }) {
    return (
        <a
            href={href}
            target={href.startsWith('http') ? '_blank' : undefined}
            rel={href.startsWith('http') ? 'noreferrer' : undefined}
            style={{ color: S.blue, textDecoration: 'none' }}
        >
            {children}
        </a>
    );
}

function CodeBlock({ code }: { code: string }) {
    return (
        <pre
            style={{
                fontFamily: MONO,
                fontSize: '12px',
                lineHeight: 1.65,
                color: S.base00,
                backgroundColor: S.base2,
                padding: '1rem 1.1rem',
                margin: '0 0 1.25rem',
                overflowX: 'auto',
                whiteSpace: 'pre',
            }}
        >
            {code}
        </pre>
    );
}

// ── page ─────────────────────────────────────────────────────────────────────

export default function Blog() {
    return (
        <>
            <Head title="blog — artfct" />
            <ThemeToggle />
            <div
                style={{
                    minHeight: '100dvh',
                    backgroundColor: S.base3,
                    fontFamily: SANS,
                    color: S.base0,
                }}
            >
                {/* ── top nav ── */}
                <nav
                    style={{
                        borderBottom: `1px solid ${S.base2}`,
                        padding: '0.85rem 1.5rem',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                    }}
                >
                    <Link
                        href="/"
                        style={{
                            fontFamily: MONO,
                            fontSize: '13px',
                            color: S.base00,
                            textDecoration: 'none',
                            letterSpacing: '0.04em',
                        }}
                    >
                        artfct
                    </Link>
                    <Link
                        href="/"
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                            color: S.base1,
                            textDecoration: 'none',
                        }}
                    >
                        ← deploy
                    </Link>
                </nav>

                {/* ── content ── */}
                <div
                    style={{
                        maxWidth: '680px',
                        margin: '0 auto',
                        padding: '2.5rem 1.5rem 4rem',
                    }}
                >
                    <h1
                        style={{
                            fontFamily: MONO,
                            fontSize: '14px',
                            fontWeight: 400,
                            color: S.base00,
                            letterSpacing: '0.04em',
                            margin: '0 0 2.5rem',
                        }}
                    >
                        blog
                    </h1>

                    {POSTS.map((post) => (
                        <article
                            key={post.slug}
                            style={{ marginBottom: '4rem' }}
                        >
                            {/* post header */}
                            <div
                                style={{
                                    display: 'flex',
                                    alignItems: 'baseline',
                                    gap: '0.75rem',
                                    marginBottom: '1.25rem',
                                    paddingBottom: '1rem',
                                    borderBottom: `1px solid ${S.base2}`,
                                }}
                            >
                                <time
                                    dateTime={post.date}
                                    style={{
                                        fontFamily: MONO,
                                        fontSize: '11px',
                                        color: S.base1,
                                        letterSpacing: '0.04em',
                                        flexShrink: 0,
                                    }}
                                >
                                    {post.date}
                                </time>
                                <span
                                    style={{
                                        fontFamily: MONO,
                                        fontSize: '10px',
                                        color: S.cyan,
                                        backgroundColor: S.base2,
                                        padding: '0.15rem 0.45rem',
                                        letterSpacing: '0.05em',
                                        flexShrink: 0,
                                    }}
                                >
                                    {post.tag}
                                </span>
                                <h2
                                    style={{
                                        fontFamily: SANS,
                                        fontSize: '16px',
                                        fontWeight: 600,
                                        color: S.base00,
                                        margin: 0,
                                        lineHeight: 1.3,
                                    }}
                                >
                                    {post.title}
                                </h2>
                            </div>

                            {/* post body */}
                            <div>{post.body}</div>
                        </article>
                    ))}
                </div>

                {/* ── footer ── */}
                <footer
                    style={{
                        borderTop: `1px solid ${S.base2}`,
                        padding: '1rem 1.5rem',
                        maxWidth: '680px',
                        margin: '0 auto',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        fontFamily: MONO,
                        fontSize: '11px',
                    }}
                >
                    <div style={{ display: 'flex', gap: '1.25rem' }}>
                        <Link
                            href="/"
                            style={{ color: S.base1, textDecoration: 'none' }}
                        >
                            home
                        </Link>
                        <Link
                            href="/docs"
                            style={{ color: S.base1, textDecoration: 'none' }}
                        >
                            docs
                        </Link>
                        <a
                            href={GITHUB}
                            target="_blank"
                            rel="noreferrer"
                            style={{ color: S.base1, textDecoration: 'none' }}
                        >
                            github
                        </a>
                    </div>
                    <a
                        href="#"
                        style={{ color: S.base1, textDecoration: 'none' }}
                    >
                        ↑ top
                    </a>
                </footer>
            </div>
        </>
    );
}
