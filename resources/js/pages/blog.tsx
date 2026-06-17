import { Head, Link } from '@inertiajs/react';
import { S, MONO, SANS, GITHUB, POSTS } from '@/lib/posts';
import { ThemeToggle } from '@/lib/theme';

interface BlogPageProps {
    posts?: Array<{ slug: string; date: string; title: string; tag: string }>;
}

// ── page ─────────────────────────────────────────────────────────────────────

export default function Blog({ posts }: BlogPageProps) {
    const visiblePosts =
        posts ??
        POSTS.map((p) => ({
            slug: p.slug,
            date: p.date,
            title: p.title,
            tag: p.tag,
        }));

    return (
        <>
            <Head title="blog — artfct" />
            <ThemeToggle />
            <div
                id="top"
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

                    {visiblePosts.map((post) => (
                        <article
                            key={post.slug}
                            id={post.slug}
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
                                    <Link
                                        href={`/blog/${post.slug}`}
                                        style={{
                                            color: S.base00,
                                            textDecoration: 'none',
                                        }}
                                    >
                                        {post.title}
                                    </Link>
                                </h2>
                            </div>

                            {/* post body */}
                            <div>
                                {POSTS.find((p) => p.slug === post.slug)?.body}
                            </div>
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
                        <Link
                            href="/blog"
                            style={{ color: S.base1, textDecoration: 'none' }}
                        >
                            blog
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
                        href="#top"
                        style={{ color: S.base1, textDecoration: 'none' }}
                    >
                        ↑ top
                    </a>
                </footer>
            </div>
        </>
    );
}
