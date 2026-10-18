import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import comments from '@/routes/artifacts/comments';

export interface ArtifactComment {
    id: number;
    parent_id: number | null;
    author: string | null;
    agent: string | null;
    body: string;
    created_at: string;
    resolved: boolean;
}

interface Props {
    artifactId: string;
    comments: ArtifactComment[];
    className?: string;
}

function formatWhen(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleDateString(undefined, { dateStyle: 'medium' });
}

function authorLabel(comment: ArtifactComment): string {
    const name = comment.author ?? 'Someone';

    return comment.agent ? `${name} via ${comment.agent}` : name;
}

/** A discussion under the artifact: threads, replies, and resolve. */
export default function ArtifactComments({
    artifactId,
    comments: items,
    className,
}: Props) {
    const [body, setBody] = useState('');
    const [replyTo, setReplyTo] = useState<number | null>(null);
    const [reply, setReply] = useState('');

    const roots = items.filter((comment) => comment.parent_id === null);
    const repliesTo = (id: number) =>
        items.filter((comment) => comment.parent_id === id);

    const post = (text: string, parentId: number | null, done: () => void) => {
        if (text.trim() === '') {
            return;
        }

        router.post(
            comments.store.url(artifactId),
            { body: text, parent_id: parentId },
            { preserveScroll: true, onSuccess: done },
        );
    };

    const toggleResolved = (comment: ArtifactComment) => {
        router.patch(
            comments.resolve.url({ artifactId, commentId: comment.id }),
            { resolved: !comment.resolved },
            { preserveScroll: true },
        );
    };

    return (
        <section
            aria-label="Comments"
            data-testid="artifact-comments"
            className={cn(
                'mx-auto mt-8 w-full max-w-3xl border-t border-border pt-6',
                className,
            )}
        >
            <h2 className="text-lg font-normal">Comments</h2>

            {roots.length === 0 && (
                <p className="mt-2 text-sm text-muted-foreground">
                    No comments yet.
                </p>
            )}

            <ul className="mt-4 divide-y divide-border">
                {roots.map((root) => (
                    <li
                        key={root.id}
                        className={`py-4 ${root.resolved ? 'opacity-60' : ''}`}
                    >
                        {[root, ...repliesTo(root.id)].map((comment) => (
                            <div key={comment.id} className="mb-3 last:mb-0">
                                <p className="text-xs text-muted-foreground">
                                    {authorLabel(comment)}
                                    {formatWhen(comment.created_at) !== '' &&
                                        ` · ${formatWhen(comment.created_at)}`}
                                </p>
                                <p className="mt-0.5 text-sm whitespace-pre-wrap">
                                    {comment.body}
                                </p>
                            </div>
                        ))}

                        <div className="mt-2 flex gap-3 text-sm">
                            <button
                                type="button"
                                className="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                onClick={() =>
                                    setReplyTo(
                                        replyTo === root.id ? null : root.id,
                                    )
                                }
                            >
                                Reply
                            </button>
                            <button
                                type="button"
                                className="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                onClick={() => toggleResolved(root)}
                            >
                                {root.resolved ? 'Reopen' : 'Resolve'}
                            </button>
                        </div>

                        {replyTo === root.id && (
                            <form
                                className="mt-3 flex flex-col gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    post(reply, root.id, () => {
                                        setReply('');
                                        setReplyTo(null);
                                    });
                                }}
                            >
                                <textarea
                                    value={reply}
                                    onChange={(event) =>
                                        setReply(event.target.value)
                                    }
                                    maxLength={4000}
                                    rows={2}
                                    aria-label="Reply"
                                    className="w-full rounded-md border border-border bg-transparent p-2 text-sm"
                                />
                                <div>
                                    <Button type="submit" size="sm">
                                        Reply
                                    </Button>
                                </div>
                            </form>
                        )}
                    </li>
                ))}
            </ul>

            <form
                className="mt-4 flex flex-col gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    post(body, null, () => setBody(''));
                }}
            >
                <textarea
                    value={body}
                    onChange={(event) => setBody(event.target.value)}
                    maxLength={4000}
                    rows={3}
                    aria-label="Add a comment"
                    placeholder="Add a comment"
                    className="w-full rounded-md border border-border bg-transparent p-2 text-sm"
                />
                <div>
                    <Button type="submit" size="sm">
                        Comment
                    </Button>
                </div>
            </form>
        </section>
    );
}
