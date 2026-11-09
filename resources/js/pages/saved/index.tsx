import { Head, Link } from '@inertiajs/react';
import BookmarkButton from '@/components/bookmark-button';
import { Button } from '@/components/ui/button';

type SavedPost = {
    id: number;
    caption: string | null;
    author: { id: number; name: string };
    revision: number;
};

type Page<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    savedPosts: Page<SavedPost>;
    error: string | null;
};

export default function Saved({ savedPosts, error }: Props) {
    return (
        <>
            <Head title="Saved" />
            <div className="mx-auto max-w-3xl space-y-8 py-10">
                <header className="space-y-2">
                    <h1 className="font-serif text-4xl tracking-tight sm:text-5xl">
                        Saved
                    </h1>
                    <p className="max-w-lg text-base leading-relaxed text-muted-foreground">
                        Photos you saved, newest first.
                    </p>
                </header>

                {error && (
                    <p role="alert" className="rounded-md bg-muted p-3 text-sm">
                        {error}
                    </p>
                )}

                {savedPosts.data.length === 0 ? (
                    <p className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7">
                        You have no saved photos yet.
                    </p>
                ) : (
                    <ul aria-label="Saved photos" className="space-y-4">
                        {savedPosts.data.map((post) => (
                            <li
                                key={post.id}
                                className="flex min-w-0 items-start justify-between gap-3 border-b border-border/80 pb-4"
                            >
                                <div className="min-w-0 space-y-2">
                                    <p className="text-sm text-muted-foreground">
                                        {post.author.name}
                                    </p>
                                    {post.caption && (
                                        <p className="break-words whitespace-pre-wrap">
                                            {post.caption}
                                        </p>
                                    )}
                                    <Link
                                        href={`/photo-posts/${post.id}`}
                                        className="inline-flex min-h-11 items-center text-sm font-medium underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        Open photo
                                    </Link>
                                </div>
                                <BookmarkButton
                                    postId={post.id}
                                    saved
                                    revision={post.revision}
                                />
                            </li>
                        ))}
                    </ul>
                )}

                {(savedPosts.prev_page_url || savedPosts.next_page_url) && (
                    <nav aria-label="Saved pages" className="flex gap-3">
                        {savedPosts.prev_page_url && (
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <Link
                                    href={savedPosts.prev_page_url}
                                    preserveScroll
                                >
                                    Previous
                                </Link>
                            </Button>
                        )}
                        {savedPosts.next_page_url && (
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <Link
                                    href={savedPosts.next_page_url}
                                    preserveScroll
                                >
                                    Next
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}
