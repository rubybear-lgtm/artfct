import { Link } from '@inertiajs/react';

import { blog, docs, home, login, privacy, terms } from '@/routes';
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
                    artfct turns a self-contained HTML or Markdown file into a{' '}
                    <strong className="welcome-information-emphasis">
                        private, shareable link
                    </strong>{' '}
                    in seconds. No sign-up, no accounts. For keeping what your
                    team&apos;s AI tools make, see the{' '}
                    <Link
                        href={`${home.url()}#plans`}
                        className="welcome-information-link"
                    >
                        Team plan
                    </Link>
                    .
                </p>
                <p>
                    Every free link is encrypted in your browser before upload,
                    and the preview is blurred by default, so only someone with
                    the full link can read it. Links expire 5 days after the
                    last visit; you can change that from Recent deployments.
                </p>
                <p>
                    Perfect for sharing UI prototypes, dashboard previews,
                    AI-generated visual outputs, HTML demos, markdown documents,
                    and any other self-contained web content. Works in the
                    browser, and with AI tools that support adding a remote
                    connection.
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
                artfct turns a self-contained HTML or Markdown file into a
                private, shareable link in seconds. No sign-up, no accounts.
            </FaqItem>
            <FaqItem q="Is artfct free?">
                Yes. Free links cost nothing and need no sign-up. Team plans add
                sharing across your team.
            </FaqItem>
            <FaqItem q="How does encryption work?">
                Every link is encrypted in your browser using AES-GCM before it
                ever reaches the server. The encryption key is embedded in the
                URL fragment (the part after #), which the server never sees.
            </FaqItem>
            <FaqItem q="How private are free links?">
                Every free link is encrypted in your browser before upload, and
                the preview is blurred by default, so only someone with the full
                link can read it. Links expire 5 days after the last visit; you
                can change that from Recent deployments.
            </FaqItem>
            <FaqItem q="How long do artifacts last?">
                All links use sliding expiration — every visit resets the clock.
                The default is 5 days, and you can change it up to 1 year from
                Recent deployments. If a link isn&apos;t visited within its
                duration, it expires and is deleted.
            </FaqItem>
            <FaqItem q="Does artfct work with AI tools?">
                Yes. In your AI tool&apos;s settings, add the Artfct connection
                address shown above and approve the sign-in in your browser.
                There is nothing to install. See the{' '}
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
            <span>encrypted · private by default · expires after 5 days</span>
        </footer>
    );
}
