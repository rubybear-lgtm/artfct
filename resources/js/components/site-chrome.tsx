import { Link, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import { useAppTheme } from '@/lib/useAppTheme';
import { blog, docs, home, login, privacy, terms } from '@/routes';
import consoleRoutes from '@/routes/console';
import onboardingRoutes from '@/routes/onboarding';

const GITHUB = 'https://github.com/rubybear-lgtm/artfct';

type SiteSection = 'docs' | 'blog';

/**
 * Account controls shared by the public site chrome. `auth.user` is the login
 * state; `currentTeam` only chooses the destination once signed in, so a user
 * without a team is still treated as signed in.
 */
export function useAccountNav(): {
    isAuthenticated: boolean;
    accountLabel: string;
    accountUrl: string;
} {
    const { auth, currentTeam } = usePage<{
        auth: { user: { id: number } | null };
        currentTeam: { slug: string } | null;
    }>().props;

    if (!auth?.user) {
        return {
            isAuthenticated: false,
            accountLabel: 'Try Team free',
            accountUrl: login.url(),
        };
    }

    if (currentTeam) {
        return {
            isAuthenticated: true,
            accountLabel: 'Open console',
            accountUrl: consoleRoutes.index.url({ team: currentTeam.slug }),
        };
    }

    return {
        isAuthenticated: true,
        accountLabel: 'Create team',
        accountUrl: onboardingRoutes.team.show.url(),
    };
}

/** Wordmark shared by the site header and footer. */
function Wordmark({ className = '' }: { className?: string }) {
    return (
        <Link
            href={home.url()}
            className={`flex items-center gap-2 font-serif text-2xl font-medium tracking-tight ${className}`}
        >
            <span
                aria-hidden="true"
                className="size-2 rounded-full bg-primary"
            />
            Artfct
        </Link>
    );
}

/** Header for public pages outside the landing page (docs, blog). */
export function SiteHeader({ active }: { active?: SiteSection }) {
    const link = (section?: SiteSection) =>
        `transition-colors hover:text-foreground ${
            section && active === section ? 'text-foreground' : ''
        }`;
    const phoneMenu = useRef<HTMLDetailsElement>(null);
    const { isAuthenticated, accountLabel, accountUrl } = useAccountNav();

    return (
        <header className="border-b border-border">
            <div className="mx-auto flex max-w-[1120px] flex-wrap items-center justify-between gap-x-4 gap-y-3 px-4 py-[14px] sm:gap-6 sm:px-5 sm:py-[18px]">
                <Wordmark />
                <nav
                    aria-label="Site"
                    className="hidden gap-7 text-sm text-muted-foreground sm:flex"
                >
                    <Link href={`${home.url()}#how`} className={link()}>
                        How it works
                    </Link>
                    <Link href={`${home.url()}#plans`} className={link()}>
                        Pricing
                    </Link>
                    <Link href={docs.url()} className={link('docs')}>
                        Docs
                    </Link>
                    <Link href={blog.url()} className={link('blog')}>
                        Blog
                    </Link>
                </nav>
                <details ref={phoneMenu} className="relative sm:hidden">
                    <summary className="flex min-h-11 cursor-pointer list-none items-center text-sm text-muted-foreground [&::-webkit-details-marker]:hidden">
                        Menu
                    </summary>
                    <div
                        className="absolute right-0 z-20 mt-2 flex min-w-[200px] flex-col rounded-md border border-border bg-background p-2 text-sm text-muted-foreground shadow-lg"
                        onClick={() => {
                            if (phoneMenu.current) {
                                phoneMenu.current.open = false;
                            }
                        }}
                    >
                        <Link
                            href={`${home.url()}#how`}
                            className="flex min-h-11 items-center rounded-md px-3 transition-colors hover:text-foreground"
                        >
                            How it works
                        </Link>
                        <Link
                            href={`${home.url()}#plans`}
                            className="flex min-h-11 items-center rounded-md px-3 transition-colors hover:text-foreground"
                        >
                            Pricing
                        </Link>
                        <Link
                            href={docs.url()}
                            className="flex min-h-11 items-center rounded-md px-3 transition-colors hover:text-foreground"
                        >
                            Docs
                        </Link>
                        <Link
                            href={blog.url()}
                            className="flex min-h-11 items-center rounded-md px-3 transition-colors hover:text-foreground"
                        >
                            Blog
                        </Link>
                    </div>
                </details>
                <div
                    className="flex flex-wrap items-center gap-x-[18px] gap-y-2 text-sm"
                    data-testid="site-account"
                >
                    {!isAuthenticated && (
                        <Link
                            href={login.url()}
                            data-testid="site-signin"
                            className="site-signin"
                        >
                            Sign in
                        </Link>
                    )}
                    <Link
                        href={accountUrl}
                        data-testid="site-account-cta"
                        className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary-deep"
                    >
                        {accountLabel}
                    </Link>
                </div>
            </div>
        </header>
    );
}

export function SiteFooter() {
    return (
        <footer className="border-t border-border">
            <div className="mx-auto flex max-w-[1120px] flex-wrap items-baseline justify-between gap-4 px-5 py-7 text-sm text-muted-foreground">
                <div className="flex items-baseline gap-3">
                    <Wordmark className="text-xl text-foreground" />
                    <span>© {new Date().getFullYear()} Artfct</span>
                </div>
                <div className="flex flex-wrap gap-x-5 gap-y-2">
                    <Link href={docs.url()}>Docs</Link>
                    <Link href={blog.url()}>Blog</Link>
                    <a href={GITHUB} target="_blank" rel="noreferrer">
                        GitHub
                    </a>
                    <Link href={privacy.url()}>Privacy</Link>
                    <Link href={terms.url()}>Terms</Link>
                </div>
            </div>
        </footer>
    );
}

/** Page shell for public reading pages: Bone ground, header, footer. */
export function SitePage({
    active,
    children,
}: {
    active?: SiteSection;
    children: React.ReactNode;
}) {
    useAppTheme();

    return (
        <div className="site-page min-h-screen bg-background text-foreground">
            <SiteHeader active={active} />
            {children}
            <SiteFooter />
        </div>
    );
}

export { GITHUB };
