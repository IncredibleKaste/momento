import { Head, Link } from '@inertiajs/react';
import PhotoPostCard, {
    type PhotoCardPost,
} from '@/components/photo-post-card';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type SavedPost = Omit<PhotoCardPost, 'saveState'> & {
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
            <div className="min-w-0 space-y-5 pb-5">
                <header className="space-y-2">
                    <h1 className="font-serif text-xl">Saved moments</h1>
                    <p className="text-xs text-muted-foreground">
                        Photos you saved, newest first.
                    </p>
                </header>

                {error && (
                    <p role="alert" className="rounded-md bg-muted p-3 text-sm">
                        {error}
                    </p>
                )}

                {savedPosts.data.length === 0 ? (
                    <section className="space-y-3 border border-border bg-card px-5 py-8 sm:px-7">
                        <h2 className="font-serif text-2xl">
                            {savedPosts.prev_page_url
                                ? 'No saved photos on this page.'
                                : 'Keep something close.'}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {savedPosts.prev_page_url
                                ? 'Use Previous to check earlier pages.'
                                : 'Save a photo from your feed to find it here.'}
                        </p>
                        <Link
                            href={dashboard()}
                            prefetch={false}
                            className="inline-flex min-h-11 items-center text-sm underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            Back to your feed
                        </Link>
                    </section>
                ) : (
                    <ul aria-label="Saved photos" className="space-y-7">
                        {savedPosts.data.map((post) => (
                            <li key={post.id} className="min-w-0">
                                <PhotoPostCard
                                    post={{
                                        ...post,
                                        saveState: {
                                            saved: true,
                                            revision: post.revision,
                                        },
                                    }}
                                />
                            </li>
                        ))}
                    </ul>
                )}

                {(savedPosts.prev_page_url || savedPosts.next_page_url) && (
                    <nav
                        aria-label="Saved pages"
                        className="flex flex-wrap gap-3"
                    >
                        {savedPosts.prev_page_url && (
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <Link
                                    href={savedPosts.prev_page_url}
                                    prefetch={false}
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
                                    prefetch={false}
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
