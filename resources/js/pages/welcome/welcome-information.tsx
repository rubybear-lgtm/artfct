import { Link } from '@inertiajs/react';

import { blog, docs, login, privacy, terms } from '@/routes';
import consoleRoutes from '@/routes/console';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base0: 'var(--sol-base0)',
    base00: 'var(--sol-base00)',
    blue: 'var(--sol-blue)',
    cyan: 'var(--sol-cyan)',
} as const;

function FaqItem({ q, children }: { q: string; children: React.ReactNode }) {
    return (
        <details>
            <summary
                style={{
                    cursor: 'pointer',
                    fontFamily: MONO,
                    fontSize: '13px',
                    color: S.base00,
                    fontWeight: 500,
                    marginBottom: '0.35rem',
                    userSelect: 'none',
                }}
            >
                {q}
            </summary>
            <div
                style={{
                    paddingLeft: '0.5rem',
                    borderLeft: `2px solid ${S.base2}`,
                    marginBottom: '0.5rem',
                }}
            >
                {children}
            </div>
        </details>
    );
}

export function WelcomeInformation() {
    return (
        <div
            style={{
                width: '100%',
                paddingTop: '2rem',
                borderTop: `1px solid ${S.base2}`,
                display: 'flex',
                flexDirection: 'column',
                gap: '1.2rem',
            }}
        >
            <div
                style={{
                    fontFamily:
                        "'Instrument Sans', ui-sans-serif, system-ui, sans-serif",
                    fontSize: '14px',
                    lineHeight: 1.7,
                    color: S.base0,
                }}
            >
                <h2
                    style={{
                        fontFamily: MONO,
                        fontSize: '12px',
                        fontWeight: 400,
                        color: S.base00,
                        margin: '0 0 1rem',
                        letterSpacing: '0.04em',
                        textTransform: 'uppercase',
                    }}
                >
                    what is artfct?
                </h2>
                <p style={{ margin: '0 0 0.8rem' }}>
                    artfct is an{' '}
                    <strong style={{ color: S.base00 }}>
                        instant encrypted HTML sharing
                    </strong>{' '}
                    tool for developers. Drop a self-contained HTML file — or
                    pipe one via CLI, API, or AI agent — and get a shareable
                    link in seconds. No sign-up, no accounts, no configuration.
                </p>
                <p style={{ margin: '0 0 0.8rem' }}>
                    Every artifact is encrypted in the browser with AES-GCM
                    before it ever reaches the server. The encryption key lives
                    in the URL fragment, which the server never sees. Choose
                    from three access tiers:{' '}
                    <span style={{ color: S.cyan }}>public</span>,{' '}
                    <span style={{ color: S.cyan }}>secure</span>, or{' '}
                    <span style={{ color: S.cyan }}>ephemeral</span>. All
                    artifacts use sliding expiration — each access resets the
                    clock. Default TTL is 5 days, configurable up to 1 year.
                </p>
                <p style={{ margin: 0 }}>
                    Perfect for sharing UI prototypes, dashboard previews,
                    AI-generated visual outputs, HTML demos, markdown documents,
                    and any other self-contained web content. Works from the
                    browser, terminal, and through MCP-compatible AI agents like
                    Claude, Cursor, and Gemini.
                </p>
            </div>
        </div>
    );
}

export function WelcomeFaq() {
    return (
        <div
            style={{
                width: '100%',
                paddingTop: '2rem',
                borderTop: `1px solid ${S.base2}`,
                display: 'flex',
                flexDirection: 'column',
                gap: '0.75rem',
            }}
        >
            <h2
                style={{
                    fontFamily: MONO,
                    fontSize: '12px',
                    fontWeight: 400,
                    color: S.base00,
                    margin: '0 0 0.5rem',
                    letterSpacing: '0.04em',
                    textTransform: 'uppercase',
                }}
            >
                faq
            </h2>
            <FaqItem q="What is artfct?">
                artfct is an instant encrypted HTML sharing tool for developers.
                Drop a self-contained HTML or Markdown file — via browser, CLI,
                API, or AI agent — and get a shareable link in seconds. No
                sign-up required. Think of it as "deploy and share" for
                self-contained web content.
            </FaqItem>
            <FaqItem q="Is artfct free?">
                Yes. All artifact tiers are free right now. Paid plans with
                higher usage limits may be added in the future, but the core
                service will remain free.
            </FaqItem>
            <FaqItem q="How does encryption work?">
                Every artifact is encrypted in the browser using AES-GCM before
                it ever reaches the server. The encryption key is embedded in
                the URL fragment (the part after #), which the server never
                sees. For secure artifacts, the preview is blurred by default —
                only someone with the full URL can read the content.
            </FaqItem>
            <FaqItem q="What are the three tiers?">
                <strong style={{ color: S.cyan }}>public</strong> — open-access
                URLs, shareable with anyone. Best for demos, prototypes, and
                public documents.
                <br />
                <strong style={{ color: S.cyan }}>secure</strong> — high-
                entropy fragment keys with blurred previews by default. The
                content is encrypted and only accessible with the full URL. Best
                for sensitive documents or internal tools.
                <br />
                <strong style={{ color: S.cyan }}>ephemeral</strong> —
                intentionally short-lived. Same as public, just named for things
                you don't need to keep. Best for temporary shares, drafts, and
                one-off reviews.
            </FaqItem>
            <FaqItem q="How long do artifacts last?">
                All artifacts use sliding expiration — every access resets the
                clock. The default TTL is 5 days, configurable up to 1 year. If
                an artifact isn't accessed within its TTL, it expires and is
                deleted. There is no "permanent" tier — everything has a shelf
                life.
            </FaqItem>
            <FaqItem q="Can I use artfct from the CLI?">
                Yes. Pipe HTML from stdin:{' '}
                <code
                    style={{
                        fontFamily: MONO,
                        fontSize: '12px',
                        color: S.base00,
                        backgroundColor: S.base2,
                        padding: '0.1em 0.3em',
                    }}
                >
                    cat dashboard.html | npx artfct
                </code>
                . The CLI returns a URL to stdout — perfect for shell scripts,
                CI pipelines, and automation.
            </FaqItem>
            <FaqItem q="Does artfct work with AI agents?">
                Yes. Install the artfct MCP server or the artfct skill in Claude
                Code, Cursor, Codex, or Gemini. Your agent can build HTML
                artifacts (dashboards, diagrams, presentations, tools) and
                deploy them automatically with a single MCP call. See the{' '}
                <Link
                    href={docs.url()}
                    style={{ color: S.blue, textDecoration: 'none' }}
                >
                    docs
                </Link>{' '}
                for setup instructions.
            </FaqItem>
        </div>
    );
}

interface WelcomeFooterProps {
    isAuthenticated: boolean;
    currentTeamSlug: string | null;
}

export function WelcomeFooter({
    isAuthenticated,
    currentTeamSlug,
}: WelcomeFooterProps) {
    return (
        <footer
            style={{
                width: '100%',
                paddingTop: '1.25rem',
                borderTop: `1px solid ${S.base2}`,
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                fontFamily: MONO,
                fontSize: '14px',
            }}
        >
            <div style={{ display: 'flex', gap: '1.5rem' }}>
                {[
                    ['docs', docs.url()],
                    ['blog', blog.url()],
                    ['terms', terms.url()],
                    ['privacy', privacy.url()],
                ].map(([label, href]) => (
                    <Link
                        key={label}
                        href={href}
                        style={{ color: S.base1, textDecoration: 'none' }}
                    >
                        {label}
                    </Link>
                ))}
                <a
                    href={
                        isAuthenticated && currentTeamSlug
                            ? consoleRoutes.index.url({ team: currentTeamSlug })
                            : login.url()
                    }
                    style={{ color: S.base1, textDecoration: 'none' }}
                >
                    {isAuthenticated ? 'open console' : 'sign up / log in'}
                </a>
                <a
                    href="https://github.com/rubybear-lgtm/artfct"
                    target="_blank"
                    rel="noreferrer"
                    style={{ color: S.base1, textDecoration: 'none' }}
                >
                    github
                </a>
            </div>
            <span style={{ color: S.base1 }}>public · secure · ephemeral</span>
        </footer>
    );
}
