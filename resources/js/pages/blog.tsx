import { Head, Link } from '@inertiajs/react';

import { SitePage } from '@/components/site-chrome';
import { POSTS } from '@/lib/posts';

interface BlogPageProps {
    posts?: Array<{ slug: string; date: string; title: string; tag: string }>;
}

export default function Blog({ posts }: BlogPageProps) {
    const visiblePosts =
        posts ??
        POSTS.map((post) => ({
            slug: post.slug,
            date: post.date,
            title: post.title,
            tag: post.tag,
        }));

    return (
        <SitePage active="blog">
            <Head title="Blog" />

            <main className="mx-auto max-w-[760px] px-5 py-16">
                <p className="mb-3 text-xs font-bold tracking-[0.08em] text-primary uppercase">
                    Blog
                </p>
                <h1 className="max-w-[16ch]">
                    Guides for your <em className="text-primary">AI tools</em>
                </h1>
                <p className="mt-5 max-w-[52ch] text-lg text-muted-foreground">
                    How to get more out of Artfct with the AI tools your team
                    already uses.
                </p>

                <ul className="mt-14 divide-y divide-border border-y border-border">
                    {visiblePosts.map((post) => (
                        <li key={post.slug}>
                            <Link
                                href={`/blog/${post.slug}`}
                                className="group flex flex-col gap-3 py-8"
                            >
                                <span className="flex items-center gap-3 text-xs">
                                    <time
                                        dateTime={post.date}
                                        className="font-semibold tracking-[0.08em] text-[var(--sol-base1)] uppercase"
                                    >
                                        {post.date}
                                    </time>
                                    <span className="rounded-[5px] bg-primary/10 px-2 py-0.5 font-semibold text-primary">
                                        {post.tag}
                                    </span>
                                </span>
                                <span className="font-serif text-3xl leading-tight tracking-tight text-balance transition-colors group-hover:text-primary">
                                    {post.title}
                                </span>
                                <span className="max-w-[60ch] text-muted-foreground">
                                    {POSTS.find((p) => p.slug === post.slug)
                                        ?.description ?? ''}
                                </span>
                                <span className="text-sm font-semibold text-primary">
                                    Read the post{' '}
                                    <span
                                        aria-hidden="true"
                                        className="inline-block transition-transform group-hover:translate-x-1"
                                    >
                                        →
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </main>
        </SitePage>
    );
}
