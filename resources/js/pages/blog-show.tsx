import { Link } from '@inertiajs/react';

import { SitePage } from '@/components/site-chrome';
import { getPostBySlug } from '@/lib/posts';
import { blog } from '@/routes';

interface BlogShowProps {
    post: {
        slug: string;
        title: string;
        description: string;
        date: string;
        tag: string;
    };
}

export default function BlogShow({ post }: BlogShowProps) {
    const fullPost = getPostBySlug(post.slug);

    if (!fullPost) {
        return null;
    }

    return (
        <SitePage active="blog">
            <main className="mx-auto max-w-[680px] px-5 py-14">
                <Link
                    href={blog.url()}
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
                        <p className="mb-4 flex flex-wrap items-center gap-3 text-xs">
                            <time
                                dateTime={post.date}
                                className="font-semibold tracking-[0.08em] text-[var(--ink-quiet)] uppercase"
                            >
                                {new Date(
                                    post.date + 'T00:00:00',
                                ).toLocaleDateString('en-GB', {
                                    day: 'numeric',
                                    month: 'long',
                                    year: 'numeric',
                                })}
                            </time>
                            <span className="rounded-[5px] bg-primary/10 px-2 py-0.5 font-semibold text-primary">
                                {post.tag}
                            </span>
                        </p>
                        <h1 className="break-words">{post.title}</h1>
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

                    <div className="min-w-0 pt-10 break-words">
                        {fullPost.body}
                    </div>
                </article>

                <div className="mt-16 border-t border-border pt-8">
                    <Link
                        href={blog.url()}
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
