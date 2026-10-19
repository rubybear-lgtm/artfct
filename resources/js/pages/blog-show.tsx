import { Link } from '@inertiajs/react';
import type { ComponentType } from 'react';

import { BreakFigure, LoopFigure } from '@/components/contradicting-figures';
import { SitePage } from '@/components/site-chrome';
import { blog } from '@/routes';

/** The closed set the renderer emits; a name outside it is a server bug. */
const FIGURES: Record<string, ComponentType> = {
    break: BreakFigure,
    loop: LoopFigure,
};

type Block = { type: 'html'; html: string } | { type: 'figure'; name: string };

interface BlogShowProps {
    post: {
        slug: string;
        title: string;
        description: string;
        date: string;
        tag: string;
        blocks: Block[];
    };
}

/**
 * The article body arrives as ordered blocks rather than one HTML string,
 * because a figure is a React component and cannot be mounted inside
 * server-rendered markup.
 */
function ArticleBody({ blocks, slug }: { blocks: Block[]; slug: string }) {
    return (
        <>
            {blocks.map((block, index) => {
                if (block.type === 'figure') {
                    const Figure = FIGURES[block.name];

                    if (!Figure) {
                        throw new Error(
                            `Unknown figure block "${block.name}" in post "${slug}".`,
                        );
                    }

                    return <Figure key={index} />;
                }

                return (
                    <div
                        key={index}
                        dangerouslySetInnerHTML={{ __html: block.html }}
                    />
                );
            })}
        </>
    );
}

export default function BlogShow({ post }: BlogShowProps) {
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
                    </header>

                    <div className="min-w-0 pt-10 break-words">
                        <ArticleBody blocks={post.blocks} slug={post.slug} />
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
