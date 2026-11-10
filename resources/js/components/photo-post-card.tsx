import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import BookmarkButton from '@/components/bookmark-button';
import PhotoPostTime from '@/components/photo-post-time';
import PhotoLikeButton from '@/components/photo-like-button';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { show as profile } from '@/routes/members';

export type PhotoCardPost = {
    id: number;
    caption: string | null;
    location: string | null;
    createdAt: string;
    author: { id: number; name: string };
    mediaUrl: string;
    detailUrl: string;
    likeState: { liked: boolean; revision: number };
    saveState: { saved: boolean; revision: number };
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

export default function PhotoPostCard({ post }: { post: PhotoCardPost }) {
    const [imageFailed, setImageFailed] = useState(false);
    const initials = useInitials();

    if (imageFailed) {
        return (
            <article
                aria-label="Photo unavailable"
                className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground sm:px-7"
                role="status"
            >
                This photo is no longer available. Refresh this page to check
                access.
            </article>
        );
    }

    return (
        <article className="photo-post-card">
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
                    <span className="min-w-0 break-words">
                        {post.author.name}
                    </span>
                </Link>
                <PhotoPostTime
                    className="feed-post-time"
                    createdAt={post.createdAt}
                    compact
                />
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
                {post.location && (
                    <p className="mt-2 text-xs wrap-anywhere whitespace-pre-wrap text-muted-foreground">
                        {post.location}
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
