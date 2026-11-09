import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import BookmarkButton from '@/components/bookmark-button';
import PhotoLikeButton from '@/components/photo-like-button';
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
    likeState: { liked: boolean; revision: number };
    saveState: { saved: boolean; revision: number };
};

type Props = {
    followedPeople: FollowedPerson[];
    photoPosts: {
        data: PhotoPost[];
        nextCursor: string | null;
        previousCursor: string | null;
    };
};

const feedGradients = [
    '#648b49, #c5ad4d',
    '#a8a24d, #dc983e',
    '#d5ad4c, #db7845',
    '#d99046, #bf5650',
    '#d07849, #b55168',
    '#bd5755, #96658c',
    '#b45c77, #805e9f',
    '#966092, #627caf',
    '#7c69a5, #488fae',
    '#617fb0, #529b9b',
    '#4d8da6, #65955f',
    '#50998a, #a3a356',
    '#709f62, #d0ad66',
    '#b39c59, #c97d76',
    '#c07d78, #8d7da8',
    '#8c7faa, #66948c',
];

function PhotoPostCard({ post }: { post: PhotoPost }) {
    const [imageFailed, setImageFailed] = useState(false);
    const initials = useInitials();

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
        <article className="feed-post">
            <header className="feed-post-header">
                <Link
                    href={profile(post.author.id)}
                    prefetch={false}
                    className="feed-author min-w-0"
                >
                    <Avatar aria-hidden="true" className="size-9 shrink-0">
                        <AvatarFallback className="text-xs">
                            {initials(post.author.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="min-w-0 break-words">{post.author.name}</span>
                </Link>
                <time
                    className="feed-post-time"
                    dateTime={post.createdAt}
                    title={new Date(post.createdAt).toLocaleString()}
                >
                    {new Date(post.createdAt).toLocaleString(undefined, {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
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
                    className="feed-photo"
                />
            </Link>
            <div className="feed-post-bottom">
                <div
                    className="feed-post-actions"
                    aria-label="Your photo actions"
                >
                    <PhotoLikeButton
                        postId={post.id}
                        {...post.likeState}
                        compact
                    />
                    <Link
                        href={post.detailUrl}
                        prefetch={false}
                        className="feed-comments"
                        aria-label={`Comments on photo by ${post.author.name}`}
                    >
                        <MessageCircle aria-hidden="true" className="size-5" />
                        <span>Comments</span>
                    </Link>
                    <div className="ml-auto">
                        <BookmarkButton postId={post.id} {...post.saveState} />
                    </div>
                </div>
                {post.caption && (
                    <p className="feed-caption">
                        <Link
                            href={profile(post.author.id)}
                            prefetch={false}
                            className="font-bold hover:underline"
                        >
                            {post.author.name}
                        </Link>{' '}
                        {post.caption}
                    </p>
                )}
            </div>
            <div
                aria-hidden="true"
                className="feed-post-end"
                style={{
                    backgroundImage: `linear-gradient(105deg, ${feedGradients[post.id % feedGradients.length]})`,
                }}
            />
        </article>
    );
}

export default function Dashboard({ followedPeople, photoPosts }: Props) {
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
            <div className="home-feed">
                <h1 className="sr-only">Home</h1>
                <section
                    aria-labelledby="followed-people-heading"
                    className="min-w-0 space-y-3 pb-3"
                >
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <h2
                            id="followed-people-heading"
                            className="text-sm font-semibold"
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
                            Visit someone’s profile and choose Follow to see
                            them here.
                        </p>
                    ) : (
                        <ul
                            aria-label="People you follow"
                            className="feed-people"
                        >
                            {followedPeople.map((person) => (
                                <li key={person.id} className="feed-person">
                                    <Link
                                        href={profile(person.id)}
                                        prefetch={false}
                                        className="flex flex-col items-center gap-2.5 text-center focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        <Avatar
                                            aria-hidden="true"
                                            className="feed-person-face"
                                        >
                                            <AvatarFallback className="font-serif text-base">
                                                {initials(person.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span className="line-clamp-2 w-full text-xs leading-snug break-words">
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
                    className="min-w-0 space-y-4 pb-5"
                >
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h2
                                id="photo-feed-heading"
                                className="font-serif text-xl"
                            >
                                Your feed
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                From you and your people
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
                            Photos could not be loaded. Refresh Home to try
                            again.
                        </div>
                    ) : photoPosts.data.length === 0 ? (
                        <p className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7">
                            No photos to show yet. When you or people you follow
                            share photos, they will appear here.
                        </p>
                    ) : (
                        <div className="space-y-7">
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
            </div>
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
