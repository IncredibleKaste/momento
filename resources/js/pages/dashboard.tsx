import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import { index as following } from '@/routes/following';
import { show as profile } from '@/routes/members';

type FollowedPerson = { id: number; name: string };

type PhotoPost = {
    id: number;
    caption: string | null;
    createdAt: string;
    author: { id: number; name: string };
    mediaUrl: string;
    detailUrl: string;
};

type Props = {
    followedPeople: FollowedPerson[];
    photoPosts: {
        data: PhotoPost[];
        nextCursor: string | null;
        previousCursor: string | null;
    };
};

function PhotoPostCard({ post }: { post: PhotoPost }) {
    const [imageFailed, setImageFailed] = useState(false);

    if (imageFailed) {
        return (
            <article
                aria-label="Photo unavailable"
                className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7"
                role="status"
            >
                This photo is no longer available. Refresh Home to check access.
            </article>
        );
    }

    return (
        <article className="overflow-hidden border border-border bg-card">
            <header className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <Link
                    href={profile(post.author.id)}
                    prefetch={false}
                    className="inline-flex min-h-11 items-center font-medium underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    {post.author.name}
                </Link>
                <time
                    className="text-sm text-muted-foreground"
                    dateTime={post.createdAt}
                >
                    {new Date(post.createdAt).toLocaleString(undefined, {
                        dateStyle: 'medium',
                        timeStyle: 'short',
                    })}
                </time>
            </header>
            <Link
                href={post.detailUrl}
                prefetch={false}
                className="block bg-muted focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring"
                aria-label={`View photo by ${post.author.name}`}
            >
                <img
                    src={post.mediaUrl}
                    alt={post.caption || `Photo by ${post.author.name}`}
                    loading="lazy"
                    decoding="async"
                    onError={() => setImageFailed(true)}
                    className="mx-auto aspect-[4/3] max-h-[70vh] w-full object-contain"
                />
            </Link>
            {post.caption && (
                <p className="px-4 py-4 text-sm leading-relaxed break-words whitespace-pre-wrap sm:px-6">
                    {post.caption}
                </p>
            )}
        </article>
    );
}

export default function Dashboard({ followedPeople, photoPosts }: Props) {
    const { auth } = usePage().props;
    const initials = useInitials();
    const [loadingPage, setLoadingPage] = useState(false);
    const [pageFailed, setPageFailed] = useState(false);

    const pageUrl = (cursor: string | null) =>
        dashboard({ query: cursor ? { postsCursor: cursor } : {} });

    const beginPageVisit = () => {
        setPageFailed(false);
        setLoadingPage(true);
    };

    const failPageVisit = () => {
        setPageFailed(true);
        setLoadingPage(false);
    };

    return (
        <>
            <Head title="Home" />
            <section className="flex flex-col gap-3 py-10">
                <h1 className="font-serif text-3xl">Home</h1>
                <p className="text-muted-foreground">
                    Welcome, {auth.user?.name}.
                </p>
            </section>
            <section
                aria-labelledby="followed-people-heading"
                className="min-w-0 space-y-4 pb-10"
            >
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <h2
                        id="followed-people-heading"
                        className="font-serif text-2xl"
                    >
                        People you follow
                    </h2>
                    <Link
                        href={`${following.url()}#people-you-follow`}
                        prefetch={false}
                        className="min-h-11 rounded-sm px-2 py-2 text-sm underline decoration-border underline-offset-4 hover:decoration-current focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        View all
                    </Link>
                </header>
                {followedPeople.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Visit someone’s profile and choose Follow to see them
                        here.
                    </p>
                ) : (
                    <ul
                        aria-label="People you follow"
                        className="flex w-full gap-2 overflow-x-auto overscroll-x-contain pb-3"
                    >
                        {followedPeople.map((person) => (
                            <li key={person.id} className="w-24 shrink-0">
                                <Link
                                    href={profile(person.id)}
                                    prefetch={false}
                                    className="group flex min-h-28 flex-col items-center gap-2 rounded-lg p-2 text-center hover:bg-muted/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                >
                                    <Avatar
                                        aria-hidden="true"
                                        className="size-14 border border-border"
                                    >
                                        <AvatarFallback className="font-serif text-base">
                                            {initials(person.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="line-clamp-2 w-full text-sm leading-snug break-words">
                                        {person.name}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            <section
                aria-labelledby="photo-feed-heading"
                className="min-w-0 space-y-5 pb-10"
            >
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2
                            id="photo-feed-heading"
                            className="font-serif text-2xl"
                        >
                            Photos from your people
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            You and people you follow share these photos.
                            Refresh Home to see newer posts.
                        </p>
                    </div>
                    <Link
                        href={pageUrl(null)}
                        prefetch={false}
                        onStart={beginPageVisit}
                        onError={failPageVisit}
                        onNetworkError={failPageVisit}
                        onHttpException={failPageVisit}
                        onFinish={() => setLoadingPage(false)}
                        className="min-h-11 rounded-sm px-2 py-2 text-sm underline decoration-border underline-offset-4 hover:decoration-current focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        Refresh photos
                    </Link>
                </header>
                <p aria-live="polite" className="sr-only" role="status">
                    {loadingPage ? 'Loading photos.' : ''}
                    {pageFailed ? 'Photos could not be loaded.' : ''}
                </p>
                {pageFailed ? (
                    <div
                        className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7"
                        role="alert"
                    >
                        Photos could not be loaded. Refresh Home to try again.
                    </div>
                ) : photoPosts.data.length === 0 ? (
                    <p className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7">
                        No photos to show yet. When you or people you follow
                        share photos, they will appear here.
                    </p>
                ) : (
                    <div className="space-y-6">
                        {photoPosts.data.map((post) => (
                            <PhotoPostCard key={post.id} post={post} />
                        ))}
                    </div>
                )}
                {(photoPosts.previousCursor || photoPosts.nextCursor) && (
                    <nav
                        aria-label="Photo feed pages"
                        className="flex flex-wrap gap-3"
                    >
                        {photoPosts.previousCursor && (
                            <Link
                                href={pageUrl(photoPosts.previousCursor)}
                                prefetch={false}
                                preserveScroll
                                aria-disabled={loadingPage}
                                onClick={(event) => {
                                    if (loadingPage) event.preventDefault();
                                }}
                                onStart={beginPageVisit}
                                onError={failPageVisit}
                                onNetworkError={failPageVisit}
                                onHttpException={failPageVisit}
                                onFinish={() => setLoadingPage(false)}
                                className="inline-flex min-h-11 items-center border border-border px-4 text-sm underline-offset-4 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring aria-disabled:pointer-events-none aria-disabled:opacity-60"
                            >
                                Newer photos
                            </Link>
                        )}
                        {photoPosts.nextCursor && (
                            <Link
                                href={pageUrl(photoPosts.nextCursor)}
                                prefetch={false}
                                preserveScroll
                                aria-disabled={loadingPage}
                                onClick={(event) => {
                                    if (loadingPage) event.preventDefault();
                                }}
                                onStart={beginPageVisit}
                                onError={failPageVisit}
                                onNetworkError={failPageVisit}
                                onHttpException={failPageVisit}
                                onFinish={() => setLoadingPage(false)}
                                className="inline-flex min-h-11 items-center border border-border px-4 text-sm underline-offset-4 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring aria-disabled:pointer-events-none aria-disabled:opacity-60"
                            >
                                Older photos
                            </Link>
                        )}
                    </nav>
                )}
            </section>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Home',
            href: dashboard(),
        },
    ],
};
