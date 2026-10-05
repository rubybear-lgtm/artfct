import { Link } from '@inertiajs/react';

import { useAppTheme } from '@/lib/useAppTheme';
import { blog, docs, home, login, privacy, terms } from '@/routes';

const GITHUB = 'https://github.com/rubybear-lgtm/artfct';

type SiteSection = 'docs' | 'blog';

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

    return (
        <header className="border-b border-border">
            <div className="mx-auto flex max-w-[1120px] items-center justify-between gap-6 px-5 py-[18px]">
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
                <details className="relative sm:hidden">
                    <summary className="flex min-h-11 cursor-pointer list-none items-center text-sm text-muted-foreground [&::-webkit-details-marker]:hidden">
                        Menu
                    </summary>
                    <div className="absolute right-0 z-20 mt-2 flex min-w-[200px] flex-col rounded-md border border-border bg-background p-2 text-sm text-muted-foreground shadow-lg">
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
                <div className="flex items-center gap-[18px] text-sm">
                    <Link href={login.url()}>Sign in</Link>
                    <Link
                        href={login.url()}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary-deep"
                    >
                        Try Team free
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
                <div className="flex gap-5">
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
        <div className="min-h-screen bg-background text-foreground">
            <SiteHeader active={active} />
            {children}
            <SiteFooter />
        </div>
    );
}

export { GITHUB };
