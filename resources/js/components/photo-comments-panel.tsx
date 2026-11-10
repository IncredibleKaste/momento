import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    commentLength,
    isBlankComment,
    xsrfHeaders,
} from '@/lib/photo-comments';
import { PlainCommentText } from './plain-comment-text';

type CommentRow = {
    id: number;
    author: { id: number; name: string };
    body: string;
    createdAt: string | null;
    canRemove: boolean;
};

type CommentsPage = {
    count: number;
    preview: CommentRow[];
    comments: CommentRow[];
    nextAfter: number | null;
};

type ApiError = { message?: string; errors?: Record<string, string[]> };

async function readError(
    response: Response,
    fallback = 'Could not complete this action. Try again.',
): Promise<string> {
    try {
        const payload = (await response.json()) as ApiError;
        return payload.errors?.body?.[0] ?? payload.message ?? fallback;
    } catch {
        return fallback;
    }
}

export function PhotoCommentsPanel({ photoPostId }: { photoPostId: number }) {
    return (
        <PhotoCommentsPanelForPost
            key={photoPostId}
            photoPostId={photoPostId}
        />
    );
}

function PhotoCommentsPanelForPost({ photoPostId }: { photoPostId: number }) {
    const endpoint = `/photo-posts/${photoPostId}/comments`;
    const [page, setPage] = useState<CommentsPage | null>(null);
    const [expanded, setExpanded] = useState(false);
    const [draft, setDraft] = useState('');
    const [loading, setLoading] = useState(true);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    const [status, setStatus] = useState('');
    const submissionKey = useRef(crypto.randomUUID());
    const heading = useRef<HTMLHeadingElement>(null);

    const refresh = useCallback(async (): Promise<boolean> => {
        setLoading(true);
        setError('');
        setStatus('');
        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok)
                throw new Error(
                    await readError(
                        response,
                        'Comments could not be loaded. Try again.',
                    ),
                );
            setPage((await response.json()) as CommentsPage);
            return true;
        } catch (reason) {
            setError(
                reason instanceof Error && !(reason instanceof TypeError)
                    ? reason.message
                    : 'Comments could not be loaded. Try again.',
            );
            return false;
        } finally {
            setLoading(false);
        }
    }, [endpoint]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    async function loadMore() {
        if (!page?.nextAfter || loading) return;
        setLoading(true);
        setError('');
        setStatus('');
        try {
            const response = await fetch(
                `${endpoint}?after=${page.nextAfter}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            );
            if (!response.ok)
                throw new Error(
                    await readError(
                        response,
                        'More comments could not be loaded. Try again.',
                    ),
                );
            const next = (await response.json()) as CommentsPage;
            setPage((current) =>
                current
                    ? {
                          ...next,
                          comments: [...current.comments, ...next.comments],
                      }
                    : next,
            );
        } catch (reason) {
            setError(
                reason instanceof Error && !(reason instanceof TypeError)
                    ? reason.message
                    : 'More comments could not be loaded. Try again.',
            );
        } finally {
            setLoading(false);
        }
    }

    async function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (processing || isBlankComment(draft) || commentLength(draft) > 500)
            return;
        setProcessing(true);
        setError('');
        setStatus('');
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: xsrfHeaders(),
                body: JSON.stringify({
                    body: draft,
                    submissionKey: submissionKey.current,
                }),
            });
            if (!response.ok) {
                const message = await readError(
                    response,
                    'Comment could not be confirmed. Your draft is still here; retry it.',
                );
                if (response.status === 409)
                    submissionKey.current = crypto.randomUUID();
                throw new Error(message);
            }
            const result = (await response.json()) as { replayed: boolean };
            setDraft('');
            submissionKey.current = crypto.randomUUID();
            await refresh();
            setStatus(
                result.replayed
                    ? 'This comment was already posted.'
                    : 'Comment posted.',
            );
        } catch (reason) {
            setError(
                reason instanceof Error && !(reason instanceof TypeError)
                    ? reason.message
                    : 'Comment could not be confirmed. Your draft is still here; retry it.',
            );
        } finally {
            setProcessing(false);
        }
    }

    async function remove(comment: CommentRow) {
        if (!window.confirm('Remove this comment?')) return;
        setError('');
        setStatus('');
        try {
            const response = await fetch(`/photo-comments/${comment.id}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: xsrfHeaders(),
            });
            if (!response.ok)
                throw new Error(
                    await readError(
                        response,
                        'Comment could not be removed. Try again.',
                    ),
                );
            const refreshed = await refresh();
            if (refreshed) setStatus('Comment list updated.');
            heading.current?.focus();
        } catch (reason) {
            setError(
                reason instanceof Error && !(reason instanceof TypeError)
                    ? reason.message
                    : 'Comment removal could not be confirmed. Retry it.',
            );
        }
    }

    const rows = expanded ? (page?.comments ?? []) : (page?.preview ?? []);

    return (
        <section
            aria-labelledby={`comments-title-${photoPostId}`}
            className="space-y-3"
        >
            <div className="flex items-center justify-between gap-3">
                <h2
                    id={`comments-title-${photoPostId}`}
                    className="text-sm font-semibold"
                >
                    <span
                        ref={heading}
                        tabIndex={-1}
                        className="outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Comments{' '}
                        <span className="text-muted-foreground">
                            ({page?.count ?? 0})
                        </span>
                    </span>
                </h2>
                {page && page.count > 2 && (
                    <Button
                        type="button"
                        variant="link"
                        className="h-auto p-0"
                        aria-expanded={expanded}
                        onClick={() => setExpanded((value) => !value)}
                    >
                        {expanded ? 'Show preview' : 'View all comments'}
                    </Button>
                )}
            </div>

            {loading && !page ? (
                <p role="status" className="text-sm text-muted-foreground">
                    Loading comments…
                </p>
            ) : (
                <ol className="space-y-2" aria-label="Comments">
                    {rows.map((comment) => (
                        <li
                            key={comment.id}
                            className="flex items-start justify-between gap-3 text-sm"
                        >
                            <div className="min-w-0">
                                <p className="break-words whitespace-pre-wrap">
                                    <span className="font-medium">
                                        {comment.author.name}
                                    </span>{' '}
                                    <PlainCommentText body={comment.body} />
                                </p>
                                {comment.createdAt && (
                                    <time
                                        dateTime={comment.createdAt}
                                        className="text-xs text-muted-foreground"
                                    >
                                        {new Date(
                                            comment.createdAt,
                                        ).toLocaleString()}
                                    </time>
                                )}
                            </div>
                            {comment.canRemove && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="shrink-0"
                                    onClick={() => void remove(comment)}
                                    aria-label={`Remove comment by ${comment.author.name}`}
                                >
                                    Remove
                                </Button>
                            )}
                        </li>
                    ))}
                    {!loading && page?.count === 0 && (
                        <li className="text-sm text-muted-foreground">
                            No comments yet.
                        </li>
                    )}
                </ol>
            )}

            {expanded && page?.nextAfter !== null && page && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={loading}
                    onClick={() => void loadMore()}
                >
                    {loading ? 'Loading…' : 'Load next comments'}
                </Button>
            )}

            <form
                onSubmit={(event) => void submit(event)}
                className="space-y-2"
            >
                <label
                    htmlFor={`comment-draft-${photoPostId}`}
                    className="sr-only"
                >
                    Add a comment
                </label>
                <textarea
                    id={`comment-draft-${photoPostId}`}
                    value={draft}
                    onChange={(event) => {
                        const next = event.target.value;
                        if (commentLength(next) <= 500) setDraft(next);
                    }}
                    rows={2}
                    aria-describedby={`comment-count-${photoPostId}`}
                    aria-invalid={commentLength(draft) > 500 || undefined}
                    className="w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <div className="flex items-center justify-between gap-3">
                    <p
                        id={`comment-count-${photoPostId}`}
                        className="text-xs text-muted-foreground"
                    >
                        {commentLength(draft)} / 500 characters
                    </p>
                    <Button
                        type="submit"
                        size="sm"
                        disabled={processing || isBlankComment(draft)}
                    >
                        {processing ? 'Posting…' : 'Post comment'}
                    </Button>
                </div>
            </form>

            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}{' '}
                    {!page && (
                        <button
                            type="button"
                            className="underline"
                            onClick={() => void refresh()}
                        >
                            Retry loading
                        </button>
                    )}
                </p>
            )}
            {status && (
                <p role="status" className="text-sm text-muted-foreground">
                    {status}
                </p>
            )}
        </section>
    );
}
