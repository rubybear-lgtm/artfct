import { Head, Link } from '@inertiajs/react';
import { S, MONO, SANS, GITHUB, getPostBySlug } from '@/lib/posts';
import { ThemeToggle } from '@/lib/theme';

interface BlogShowProps {
    post: {
        slug: string;
        title: string;
        description: string;
        date: string;
        tag: string;
    };
}

const BASE_URL = 'https://artfct.dev';

export default function BlogShow({ post }: BlogShowProps) {
    const fullPost = getPostBySlug(post.slug);

    if (!fullPost) {
        return null;
    }

    const postUrl = `${BASE_URL}/blog/${post.slug}`;

    return (
        <>
            <Head title={`${post.title} — artfct`}>
                <meta name="description" content={post.description} />
                <meta property="og:title" content={`${post.title} — artfct`} />
                <meta property="og:description" content={post.description} />
                <meta property="og:url" content={postUrl} />
                <meta property="og:type" content="article" />
                <meta name="twitter:title" content={`${post.title} — artfct`} />
                <meta name="twitter:description" content={post.description} />
                <link rel="canonical" href={postUrl} />
            </Head>

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
                    {/* back link */}
                    <div
                        style={{
                            marginBottom: '1.5rem',
                            fontFamily: MONO,
                            fontSize: '11px',
                        }}
                    >
                        <Link
                            href="/blog"
                            style={{
                                color: S.base1,
                                textDecoration: 'none',
                            }}
                        >
                            ← all posts
                        </Link>
                    </div>

                    {/* post article */}
                    <article style={{ marginBottom: '4rem' }}>
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
                            <h1
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
                            </h1>
                        </div>

                        {/* post body */}
                        <div>{fullPost.body}</div>
                    </article>

                    {/* back to blog */}
                    <div
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                        }}
                    >
                        <Link
                            href="/blog"
                            style={{
                                color: S.base1,
                                textDecoration: 'none',
                            }}
                        >
                            ← all posts
                        </Link>
                    </div>
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

            {/* ── Article JSON-LD schema ── */}
            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={{
                    __html: JSON.stringify({
                        '@context': 'https://schema.org',
                        '@type': 'Article',
                        headline: post.title,
                        description: post.description,
                        datePublished: post.date,
                        author: {
                            '@type': 'Person',
                            name: 'artfct',
                        },
                        publisher: {
                            '@type': 'Organization',
                            name: 'artfct',
                            url: BASE_URL,
                        },
                        mainEntityOfPage: {
                            '@type': 'WebPage',
                            '@id': postUrl,
                        },
                        url: postUrl,
                    }),
                }}
            />
        </>
    );
}
