import { Head, usePage } from '@inertiajs/react';
import { AsciiLogo } from '@/lib/asciiLogo';
import { ThemeToggle } from '@/lib/theme';
import { useWelcomeController } from '@/pages/welcome/use-welcome-controller';
import { WelcomeCachedLinks } from '@/pages/welcome/welcome-cached-links';
import { WelcomeCliCallout } from '@/pages/welcome/welcome-cli-callout';
import {
    WelcomeFaq,
    WelcomeFooter,
    WelcomeInformation,
} from '@/pages/welcome/welcome-information';
import { WelcomeManageModal } from '@/pages/welcome/welcome-manage-modal';
import { WelcomeResult } from '@/pages/welcome/welcome-result';
import { WelcomeUploadControls } from '@/pages/welcome/welcome-upload-controls';
import { login } from '@/routes';
import consoleRoutes from '@/routes/console';

const FAQ_SCHEMA = {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: [
        {
            '@type': 'Question',
            name: 'What is artfct?',
            acceptedAnswer: {
                '@type': 'Answer',
                text: 'artfct is an instant encrypted HTML sharing tool for developers. Drop a self-contained HTML or Markdown file — via browser, CLI, API, or AI agent — and get a shareable link in seconds. No sign-up required.',
            },
        },
        {
            '@type': 'Question',
            name: 'Is artfct free?',
            acceptedAnswer: {
                '@type': 'Answer',
                text: 'Yes. All artifact tiers are free right now. Paid plans with higher usage limits may be added in the future, but the core service will remain free.',
            },
        },
        {
            '@type': 'Question',
            name: 'How does encryption work?',
            acceptedAnswer: {
                '@type': 'Answer',
                text: 'Every artifact is encrypted in the browser using AES-GCM before it ever reaches the server. The encryption key is embedded in the URL fragment (the part after #), which the server never sees.',
            },
        },
        {
            '@type': 'Question',
            name: 'What are the three tiers?',
            acceptedAnswer: {
                '@type': 'Answer',
                text: "Public — open-access URLs, shareable with anyone. Secure — high-entropy fragment keys with blurred previews by default. Ephemeral — intentionally short-lived, same as public but named for things you don't need to keep.",
            },
        },
        {
            '@type': 'Question',
            name: 'How long do artifacts last?',
            acceptedAnswer: {
                '@type': 'Answer',
                text: 'All artifacts use sliding expiration — every access resets the clock. Default TTL is 5 days, configurable up to 1 year. There is no permanent tier.',
            },
        },
        {
            '@type': 'Question',
            name: 'Can I use artfct from the CLI?',
            acceptedAnswer: {
                '@type': 'Answer',
                text: 'Yes. Pipe HTML from stdin: "cat dashboard.html | npx artfct". The CLI returns a URL to stdout — perfect for shell scripts, CI pipelines, and automation.',
            },
        },
        {
            '@type': 'Question',
            name: 'Does artfct work with AI agents?',
            acceptedAnswer: {
                '@type': 'Answer',
                text: 'Yes. Install the artfct MCP server or the artfct skill in Claude Code, Cursor, Codex, or Gemini. Your agent can build HTML artifacts and deploy them automatically with a single MCP call.',
            },
        },
    ],
};

export default function Welcome() {
    const { auth, currentTeam } = usePage<{
        auth: { user: { id: number } | null };
        currentTeam: { slug: string } | null;
    }>().props;
    const controller = useWelcomeController();
    const isAuthenticated = Boolean(auth?.user && currentTeam);
    const signInUrl = isAuthenticated
        ? consoleRoutes.index.url({ team: currentTeam!.slug })
        : login.url();

    return (
        <>
            <Head title="artfct — share encrypted html instantly">
                <meta
                    property="og:title"
                    content="artfct — share encrypted html. get a link. that's it."
                />
                <meta
                    property="og:description"
                    content="Drop a self-contained HTML file — via browser, CLI, API, or AI agent — and get back a shareable encrypted link. No sign-up required."
                />
                <meta property="og:url" content="https://artfct.dev" />
                <meta property="og:type" content="website" />
                <meta
                    name="description"
                    content="Drop a self-contained HTML file — via browser, CLI, API, or AI agent — and get back a shareable encrypted link. No sign-up required."
                />
            </Head>
            <ThemeToggle />
            <a href={signInUrl} className="welcome-signin">
                {isAuthenticated ? 'open console' : 'sign in'}
            </a>
            <div className="welcome-shell">
                <div className="welcome-content">
                    <AsciiLogo
                        colorA={controller.gradient[0]}
                        colorB={controller.gradient[1]}
                        className="ascii-hero"
                    />
                    <p
                        aria-label="share encrypted html or markdown. get a link. that's it."
                        className="welcome-tagline"
                    >
                        <span aria-hidden="true">
                            {controller.tagline.displayed}
                            {!controller.tagline.done && (
                                <span className="cursor-blink" />
                            )}
                        </span>
                    </p>
                    <WelcomeUploadControls
                        inputRef={controller.inputRef}
                        phase={controller.phase}
                        selectedFile={controller.selectedFile}
                        isMarkdown={controller.isMarkdown}
                        previewBlurred={controller.previewBlurred}
                        dragOver={controller.dragOver}
                        showButton={controller.showButton}
                        canDeploy={controller.canDeploy}
                        buttonActive={controller.buttonActive}
                        onPreviewBlurredChange={controller.setPreviewBlurred}
                        onAcceptFile={controller.acceptFile}
                        onDragOver={controller.handleDragOver}
                        onDragLeave={controller.handleDragLeave}
                        onDrop={controller.handleDrop}
                        onReset={controller.reset}
                        onDeploy={controller.handleDeploy}
                    />
                    {controller.phase.t === 'success' && (
                        <WelcomeResult
                            url={controller.phase.url}
                            expiresAt={controller.phase.expiresAt}
                            onReset={controller.reset}
                        />
                    )}
                    <WelcomeCachedLinks
                        links={controller.cachedLinks}
                        onClear={() => {
                            if (confirm('clear all cached links?')) {
                                controller.saveCachedLinks([]);
                            }
                        }}
                        onManage={controller.openManageModal}
                    />
                    {controller.managingLink && (
                        <WelcomeManageModal
                            managingLink={controller.managingLink}
                            modalRef={controller.modalRef}
                            newTtlMinutes={controller.newTtlMinutes}
                            setNewTtlMinutes={controller.setNewTtlMinutes}
                            isUpdatingTtl={controller.isUpdatingTtl}
                            isDeletingLink={controller.isDeletingLink}
                            modalError={controller.modalError}
                            modalSuccess={controller.modalSuccess}
                            onClose={controller.closeManageModal}
                            onUpdateTtl={controller.handleUpdateTtl}
                            onDeleteLink={controller.handleDeleteLink}
                        />
                    )}
                    <WelcomeCliCallout />
                    <WelcomeInformation />
                    <WelcomeFaq />
                    <WelcomeFooter
                        isAuthenticated={isAuthenticated}
                        currentTeamSlug={currentTeam?.slug ?? null}
                    />
                </div>
            </div>
            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={{
                    __html: JSON.stringify(FAQ_SCHEMA),
                }}
            />
        </>
    );
}
