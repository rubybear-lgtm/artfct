import { Head, Link } from '@inertiajs/react';

import { SitePage } from '@/components/site-chrome';
import { getPostBySlug } from '@/lib/posts';

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
        <SitePage active="blog">
            <Head title={`${post.title} — Artfct`}>
                <meta name="description" content={post.description} />
                <meta property="og:title" content={`${post.title} — Artfct`} />
                <meta property="og:description" content={post.description} />
                <meta property="og:url" content={postUrl} />
                <meta property="og:type" content="article" />
                <meta name="twitter:title" content={`${post.title} — Artfct`} />
                <meta name="twitter:description" content={post.description} />
                <link rel="canonical" href={postUrl} />
            </Head>

            <main className="mx-auto max-w-[680px] px-5 py-14">
                <Link
                    href="/blog"
                    className="group inline-flex gap-2 text-sm font-semibold text-muted-foreground transition-colors hover:text-primary"
                >
                    <span
                        aria-hidden="true"
                        className="transition-transform group-hover:-translate-x-1"
                    >
                        ←
                    </span>
                    All posts
                </Link>

                <article className="mt-10">
                    <header className="border-b border-border pb-10">
                        <p className="mb-4 flex items-center gap-3 text-xs">
                            <time
                                dateTime={post.date}
                                className="font-semibold tracking-[0.08em] text-[var(--sol-base1)] uppercase"
                            >
                                {post.date}
                            </time>
                            <span className="rounded-[5px] bg-primary/10 px-2 py-0.5 font-semibold text-primary">
                                {post.tag}
                            </span>
                        </p>
                        <h1>{post.title}</h1>
                        <p className="mt-5 text-lg leading-relaxed text-muted-foreground">
                            {post.description}
                        </p>
                        {fullPost.image && (
                            <picture>
                                {/* Written alongside each PNG by `npm run images:webp`. */}
                                <source
                                    srcSet={fullPost.image.replace(
                                        /\.png$/,
                                        '.webp',
                                    )}
                                    type="image/webp"
                                />
                                <img
                                    src={fullPost.image}
                                    alt=""
                                    width={1024}
                                    height={576}
                                    className="mt-9 w-full rounded-[6px]"
                                />
                            </picture>
                        )}
                    </header>

                    <div className="pt-10">{fullPost.body}</div>
                </article>

                <div className="mt-16 border-t border-border pt-8">
                    <Link
                        href="/blog"
                        className="group inline-flex gap-2 text-sm font-semibold text-primary"
                    >
                        <span
                            aria-hidden="true"
                            className="transition-transform group-hover:-translate-x-1"
                        >
                            ←
                        </span>
                        All posts
                    </Link>
                </div>
            </main>
        </SitePage>
    );
}
