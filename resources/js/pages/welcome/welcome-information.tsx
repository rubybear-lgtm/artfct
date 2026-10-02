import { Link } from '@inertiajs/react';

import { blog, docs, login, privacy, terms } from '@/routes';
import consoleRoutes from '@/routes/console';

function FaqItem({ q, children }: { q: string; children: React.ReactNode }) {
    return (
        <details>
            <summary className="welcome-faq-question">{q}</summary>
            <div className="welcome-faq-answer">{children}</div>
        </details>
    );
}

export function WelcomeInformation() {
    return (
        <div className="welcome-information">
            <div className="welcome-information-copy">
                <h2 className="welcome-section-heading">what is artfct?</h2>
                <p>
                    artfct is an{' '}
                    <strong className="welcome-information-emphasis">
                        instant encrypted HTML sharing
                    </strong>{' '}
                    tool for developers. Drop a self-contained HTML file — or
                    pipe one via CLI, API, or AI agent — and get a shareable
                    link in seconds. No sign-up, no accounts, no configuration.
                </p>
                <p>
                    Every artifact is encrypted in the browser with AES-GCM
                    before it ever reaches the server. The encryption key lives
                    in the URL fragment, which the server never sees. Choose
                    from three access tiers:{' '}
                    <span className="welcome-accent-text">public</span>,{' '}
                    <span className="welcome-accent-text">secure</span>, or{' '}
                    <span className="welcome-accent-text">ephemeral</span>. All
                    artifacts use sliding expiration — each access resets the
                    clock. Default TTL is 5 days, configurable up to 1 year.
                </p>
                <p>
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
        <div className="welcome-faq">
            <h2 className="welcome-section-heading">faq</h2>
            <FaqItem q="What is artfct?">
                artfct is an instant encrypted HTML sharing tool for developers.
                Drop a self-contained HTML or Markdown file — via browser, CLI,
                API, or AI agent — and get a shareable link in seconds. No
                sign-up required. Think of it as &quot;deploy and share&quot;
                for self-contained web content.
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
                <strong className="welcome-accent-text">public</strong> —
                open-access URLs, shareable with anyone. Best for demos,
                prototypes, and public documents.
                <br />
                <strong className="welcome-accent-text">secure</strong> —
                high-entropy fragment keys with blurred previews by default. The
                content is encrypted and only accessible with the full URL. Best
                for sensitive documents or internal tools.
                <br />
                <strong className="welcome-accent-text">ephemeral</strong> —
                intentionally short-lived. Same as public, just named for things
                you don&apos;t need to keep. Best for temporary shares, drafts,
                and one-off reviews.
            </FaqItem>
            <FaqItem q="How long do artifacts last?">
                All artifacts use sliding expiration — every access resets the
                clock. The default TTL is 5 days, configurable up to 1 year. If
                an artifact isn&apos;t accessed within its TTL, it expires and
                is deleted. There is no &quot;permanent&quot; tier — everything
                has a shelf life.
            </FaqItem>
            <FaqItem q="Can I use artfct from the CLI?">
                Yes. Pipe HTML from stdin:{' '}
                <code className="welcome-inline-code">
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
                <Link href={docs.url()} className="welcome-information-link">
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
        <footer className="welcome-footer">
            <div className="welcome-footer-links">
                {[
                    ['docs', docs.url()],
                    ['blog', blog.url()],
                    ['terms', terms.url()],
                    ['privacy', privacy.url()],
                ].map(([label, href]) => (
                    <Link key={label} href={href}>
                        {label}
                    </Link>
                ))}
                <a
                    href={
                        isAuthenticated && currentTeamSlug
                            ? consoleRoutes.index.url({ team: currentTeamSlug })
                            : login.url()
                    }
                >
                    {isAuthenticated ? 'open console' : 'sign up / log in'}
                </a>
                <a
                    href="https://github.com/rubybear-lgtm/artfct"
                    target="_blank"
                    rel="noreferrer"
                >
                    github
                </a>
            </div>
            <span>public · secure · ephemeral</span>
        </footer>
    );
}
