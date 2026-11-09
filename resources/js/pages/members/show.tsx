import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import FollowAction from '@/components/follow-action';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import { index } from '@/routes/following';
import { destroy, store } from '@/routes/follows';
import { edit } from '@/routes/profile';
import { store as startMessage } from '@/routes/messages';

type Props = {
    member: { id: number; name: string };
    follow: { id: number; status: 'pending' | 'approved' } | null;
    canMessage: boolean;
    photos?: {
        data: {
            id: number;
            caption: string | null;
            createdAt: string;
            mediaUrl: string;
            detailUrl: string;
        }[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

function ProfilePhoto({
    photo,
    memberName,
}: {
    photo: NonNullable<Props['photos']>['data'][number];
    memberName: string;
}) {
    const [failed, setFailed] = useState(false);

    return (
        <li className="min-w-0 space-y-2">
            <Link
                href={photo.detailUrl}
                prefetch={false}
                aria-label={`Open photo from ${memberName}: ${photo.caption || 'Photo'}`}
                className="group block aspect-square overflow-hidden rounded-md border border-border bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                {failed ? (
                    <span
                        role="status"
                        className="flex h-full items-center justify-center p-4 text-center text-sm text-muted-foreground"
                    >
                        This photo could not be loaded. Open it to try again.
                    </span>
                ) : (
                    <img
                        src={photo.mediaUrl}
                        alt={photo.caption || `Photo from ${memberName}`}
                        loading="lazy"
                        decoding="async"
                        onError={() => setFailed(true)}
                        className="h-full w-full object-cover transition-transform group-hover:scale-[1.02]"
                    />
                )}
            </Link>
            {photo.caption && (
                <p className="line-clamp-3 text-sm break-words whitespace-pre-wrap">
                    {photo.caption}
                </p>
            )}
            <time
                dateTime={photo.createdAt}
                className="block text-xs text-muted-foreground"
            >
                {new Date(photo.createdAt).toLocaleDateString()}
            </time>
        </li>
    );
}

export default function Member({ member, follow, canMessage, photos }: Props) {
    const { auth } = usePage().props;
    const initials = useInitials();
    const own = auth.user.id === member.id;

    return (
        <>
            <Head title={member.name} />
            <section
                aria-labelledby="profile-name"
                className="max-w-xl space-y-8 py-10"
            >
                <nav aria-label="Profile navigation" className="flex gap-6">
                    <Link
                        href={dashboard()}
                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                    >
                        Home
                    </Link>
                    <Link
                        href={index()}
                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                    >
                        Following
                    </Link>
                </nav>
                <header className="flex items-center gap-5">
                    <Avatar
                        aria-hidden="true"
                        className="size-16 shrink-0 text-xl"
                    >
                        <AvatarFallback>{initials(member.name)}</AvatarFallback>
                    </Avatar>
                    <h1
                        id="profile-name"
                        className="min-w-0 font-serif text-3xl break-words"
                    >
                        {member.name}
                    </h1>
                </header>
                {own ? (
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            This is your profile. Share this page’s address so
                            other Momento members can request to follow you.
                        </p>
                        <Link
                            href={edit()}
                            className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                        >
                            Edit profile
                        </Link>
                    </div>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            {follow?.status === 'approved'
                                ? 'You follow this member.'
                                : follow
                                  ? 'Your request is waiting for approval.'
                                  : 'Send a request to follow this member.'}
                        </p>
                        {follow?.status !== 'approved' && (
                            <p className="text-sm text-muted-foreground">
                                Photos and captions are private until this
                                member approves your request.
                            </p>
                        )}
                        {follow ? (
                            <FollowAction
                                form={destroy.form(follow.id)}
                                label={
                                    follow.status === 'approved'
                                        ? 'Unfollow'
                                        : 'Cancel request'
                                }
                                memberName={member.name}
                                secondary
                            />
                        ) : (
                            <FollowAction
                                form={store.form(member.id)}
                                label="Request to follow"
                                memberName={member.name}
                            />
                        )}
                        {canMessage && (
                            <Link
                                href={startMessage(member.id)}
                                method="post"
                                as="button"
                                className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                            >
                                Message
                            </Link>
                        )}
                    </>
                )}
                {photos && (
                    <section
                        aria-labelledby="profile-photos-heading"
                        className="space-y-5"
                    >
                        <h2
                            id="profile-photos-heading"
                            className="font-serif text-2xl sm:text-3xl"
                        >
                            Photos
                        </h2>
                        {photos.data.length === 0 ? (
                            <p className="border border-border bg-card px-5 py-8 text-sm text-muted-foreground">
                                No photos have been shared here yet.
                            </p>
                        ) : (
                            <ul
                                aria-label={`${member.name} photos`}
                                className="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3"
                            >
                                {photos.data.map((photo) => (
                                    <ProfilePhoto
                                        key={photo.id}
                                        photo={photo}
                                        memberName={member.name}
                                    />
                                ))}
                            </ul>
                        )}
                        {(photos.prev_page_url || photos.next_page_url) && (
                            <nav
                                aria-label={`${member.name} photo pages`}
                                className="flex flex-wrap gap-3"
                            >
                                {photos.prev_page_url && (
                                    <Link
                                        href={photos.prev_page_url}
                                        prefetch={false}
                                        preserveScroll
                                        className="inline-flex min-h-11 items-center rounded-md border border-input px-4 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        Previous photos
                                    </Link>
                                )}
                                {photos.next_page_url && (
                                    <Link
                                        href={photos.next_page_url}
                                        prefetch={false}
                                        preserveScroll
                                        className="inline-flex min-h-11 items-center rounded-md border border-input px-4 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        Next photos
                                    </Link>
                                )}
                            </nav>
                        )}
                    </section>
                )}
            </section>
        </>
    );
}

Member.layout = {
    breadcrumbs: [{ title: 'Profile', href: '#profile-name' }],
};
