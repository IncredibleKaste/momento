import { useState, type MouseEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import PhotoPostCard, {
    type PhotoCardPost,
} from '@/components/photo-post-card';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import { index as following } from '@/routes/following';
import { show as profile } from '@/routes/members';

type FollowedPerson = { id: number; name: string };

type Props = {
    followedPeople: FollowedPerson[];
    photoPosts: {
        data: PhotoCardPost[];
        nextCursor: string | null;
        previousCursor: string | null;
    };
};

export default function Dashboard({ followedPeople, photoPosts }: Props) {
    const initials = useInitials();
    const [loadingPage, setLoadingPage] = useState(false);
    const [pageFailed, setPageFailed] = useState(false);

    const pageUrl = (cursor: string | null) =>
        dashboard({ query: cursor ? { postsCursor: cursor } : {} }).url;

    const beginPageVisit = () => {
        setPageFailed(false);
        setLoadingPage(true);
    };

    const failPageVisit = () => {
        setPageFailed(true);
        setLoadingPage(false);
    };

    const visitFeedPage =
        (href: string, preserveScroll = false) =>
        (event: MouseEvent<Element>) => {
            const anchor = event.currentTarget;

            if (
                event.defaultPrevented ||
                !(anchor instanceof HTMLAnchorElement) ||
                event.button !== 0 ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }

            event.preventDefault();

            if (loadingPage) {
                return;
            }

            router.get(
                href,
                {},
                {
                    preserveScroll,
                    onStart: beginPageVisit,
                    onError: failPageVisit,
                    onNetworkError: () => {
                        failPageVisit();
                        return false;
                    },
                    onHttpException: (response) => {
                        if (response.status === 429 || response.status >= 500) {
                            failPageVisit();
                            return false;
                        }
                    },
                    onCancel: () => setLoadingPage(false),
                    onFinish: () => setLoadingPage(false),
                },
            );
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
                            onClick={visitFeedPage(pageUrl(null))}
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
                                    onClick={visitFeedPage(
                                        pageUrl(photoPosts.previousCursor),
                                        true,
                                    )}
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
                                    onClick={visitFeedPage(
                                        pageUrl(photoPosts.nextCursor),
                                        true,
                                    )}
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
